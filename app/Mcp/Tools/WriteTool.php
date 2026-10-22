<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\PlannedChange;
use App\Mcp\ToolContext;
use App\Mcp\ToolError;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Response;

/**
 * A change answers with a preview of exactly what it will do. It is made only when the
 * same call carries confirm: true, which the AI may set only after the user approved it.
 */
abstract class WriteTool extends McpTool
{
    protected bool $writes = true;

    /**
     * Tool-specific arguments, without confirm.
     *
     * @return array<string, mixed>
     */
    abstract protected function changeArguments(JsonSchema $schema): array;

    /**
     * Work out the exact change, reading the current state where there is one.
     *
     * @param  array<string, mixed>  $input
     */
    abstract protected function plan(array $input, ToolContext $context): PlannedChange;

    protected function arguments(JsonSchema $schema): array
    {
        return [
            ...$this->changeArguments($schema),
            'confirm' => $schema->boolean()->description('Leave unset to get a preview; nothing changes. Set to true only after the user has seen that preview and explicitly approved this exact change in the conversation. Never set it on your own initiative.'),
        ];
    }

    protected function commonRules(): array
    {
        // Any value is accepted here and checked strictly in run()
        return [...parent::commonRules(), 'confirm' => ['sometimes']];
    }

    protected function run(array $input, ToolContext $context): Response
    {
        // The API silently drops these on such plans, so a preview must not promise them
        if (array_key_exists('estimated_hours', $input) && ! $context->canUseEstimates()) {
            throw new ToolError("This organization's plan does not store estimated hours.");
        }
        if ((($input['billable'] ?? false) === true || ($input['is_billable'] ?? false) === true) && ! $context->canUseBillableRates()) {
            throw new ToolError('This organization cannot use billable time.');
        }
        $change = $this->plan($input, $context);
        // Strictly the boolean true: a string or a number is not an approval
        if (($input['confirm'] ?? null) !== true) {
            $this->previewed = true;

            return Response::json([
                'preview' => true,
                'summary' => $change->summary,
                'before' => $change->before,
                'after' => $change->after,
                'confirm_with' => [...$change->confirmWith, 'confirm' => true],
                'next_step' => 'Nothing has changed. Show the user this change. Only if they approve it, call '.$this->name().' again with the same arguments plus '
                    .json_encode([...$change->confirmWith, 'confirm' => true], JSON_UNESCAPED_SLASHES).'.',
            ]);
        }

        $send = fn (): array => $context->api->send($change->method, $change->path, $change->body, $change->headers);
        // The API runs in this process on the same connection, so the guard's row lock holds until the change is made
        $body = $change->guard === null ? $send() : DB::transaction(function () use ($change, $send): array {
            ($change->guard)();

            return $send();
        });

        return Response::json([
            'applied' => true,
            'summary' => $change->summary,
            'result' => ($change->present)($body['data'] ?? null),
        ]);
    }
}
