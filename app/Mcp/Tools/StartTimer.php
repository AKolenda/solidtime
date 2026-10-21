<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\PlannedChange;
use App\Mcp\ToolContext;
use App\Mcp\Tools\Concerns\CreatesIdempotently;
use App\Mcp\Tools\Concerns\FindsRecords;
use App\Mcp\Tools\Concerns\WritesTimeEntries;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive(false)]
class StartTimer extends WriteTool
{
    use CreatesIdempotently;
    use FindsRecords;
    use WritesTimeEntries;

    protected string $name = 'start_timer';

    protected string $description = 'Start a running timer (a time entry without an end). A person can have only one running timer; stop it first with stop_timer. Without start, the timer starts when the change is confirmed.';

    protected function changeArguments(JsonSchema $schema): array
    {
        return [
            ...$this->entryArguments($schema),
            'start' => $schema->string()->format('date-time')->description('When the timer started (ISO 8601 with offset). Defaults to the moment of confirmation.'),
            'request_id' => $this->requestIdArgument($schema),
        ];
    }

    protected function rules(): array
    {
        return [
            ...$this->entryRules(),
            'start' => ['sometimes', 'string', 'date'],
            'request_id' => $this->requestIdRule(),
        ];
    }

    protected function plan(array $input, ToolContext $context): PlannedChange
    {
        $body = $this->newEntryBody($input, $context, $this->utc($input['start'] ?? now()->toIso8601String()), null);
        $memberUserId = $context->organization->members()->whereKey($body['member_id'])->value('user_id');
        $running = is_string($memberUserId) ? $this->runningTimer($context, $memberUserId) : null;
        $summary = 'Start a timer'.($running !== null ? ' (a timer is already running for this person; it must be stopped first or this will be refused)' : '');

        return new PlannedChange($summary, 'POST', $context->path('time-entries'), $body, null, $this->entryPreview($body, $context),
            $this->presentEntry($context), $this->idempotencyHeaders($input['request_id'] ?? null));
    }
}
