<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\InternalApi;
use App\Mcp\ToolContext;
use App\Mcp\ToolError;
use App\Models\McpToolCall;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Passport\AccessToken;
use Throwable;

/**
 * Base for every SolidTime MCP tool: checks the arguments, applies the per-user rate limit,
 * records the call in mcp_tool_calls, and turns API refusals into tool errors.
 */
abstract class McpTool extends Tool
{
    /** Write tools have a smaller per-minute budget than read tools. */
    protected bool $writes = false;

    /** Set by a write tool that answered with a preview instead of changing anything. */
    protected bool $previewed = false;

    /** Argument keys whose string values are identifiers or settings and may be logged as they are. */
    private const LOGGED_STRINGS = ['start', 'end', 'group', 'sub_group', 'archived', 'done', 'color', 'request_id', 'billable', 'active'];

    /**
     * Tool-specific arguments. Every tool also accepts organization_id.
     *
     * @return array<string, mixed>
     */
    abstract protected function arguments(JsonSchema $schema): array;

    /**
     * Laravel validation rules for the arguments.
     *
     * @return array<string, mixed>
     */
    abstract protected function rules(): array;

    /**
     * @param  array<string, mixed>  $input  validated arguments
     */
    abstract protected function run(array $input, ToolContext $context): Response;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'organization_id' => $schema->string()->format('uuid')->description('Organization to work in. Defaults to your current organization (see get_context).'),
            ...$this->arguments($schema),
        ];
    }

    /**
     * Rules for the arguments every tool of a kind takes.
     *
     * @return array<string, mixed>
     */
    protected function commonRules(): array
    {
        return ['organization_id' => ['sometimes', 'string', 'uuid']];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        // Undeclared arguments are refused, so say so in the schema
        $array['inputSchema']['additionalProperties'] = false;

        return $array;
    }

    public function handle(Request $request, InternalApi $api): Response
    {
        $started = hrtime(true);
        $arguments = $request->all();
        /** @var User $user */
        $user = $request->user();
        $token = $user->token();
        $call = McpToolCall::query()->create([
            'user_id' => $user->getKey(),
            'organization_id' => null,
            'client_id' => $token instanceof AccessToken && isset($token->oauth_client_id) ? (string) $token->oauth_client_id : null,
            'token_id' => $token instanceof AccessToken && isset($token->oauth_access_token_id) ? (string) $token->oauth_access_token_id : null,
            'tool' => $this->name(),
            'outcome' => 'started',
            'arguments' => $this->redact($arguments),
            'created_at' => now(),
        ]);

        $outcome = 'error';
        $error = null;
        $organization = null;
        try {
            $limiter = 'mcp-tools:'.($this->writes ? 'write' : 'read').':'.$user->getKey();
            $limit = (int) config('mcp.rate_limits.'.($this->writes ? 'write' : 'read'));
            if (RateLimiter::tooManyAttempts($limiter, $limit)) {
                $outcome = 'rate_limited';
                $error = 'Too many '.($this->writes ? 'changes' : 'reads').'. Try again in '.RateLimiter::availableIn($limiter).' seconds.';

                return Response::error($error);
            }
            RateLimiter::hit($limiter, 60);

            $allowed = array_keys(JsonSchemaFactory::object($this->schema(...))->toArray()['properties'] ?? []);
            if (array_diff(array_keys($arguments), $allowed) !== []) {
                // Undeclared names are untrusted text; they are neither echoed nor logged
                throw new ToolError('Unknown arguments. Use only the arguments in the tool schema.');
            }
            $input = Validator::make($arguments, [...$this->commonRules(), ...$this->rules()])->validate();

            $context = $this->context($user, $input['organization_id'] ?? null, $api);
            $organization = $context->organization;
            $response = $this->run($input, $context);
            $outcome = $response->isError() ? 'error' : ($this->previewed ? 'preview' : 'ok');

            return $response;
        } catch (ValidationException $exception) {
            $error = collect($exception->errors())->flatten()->take(5)->implode(' ');

            return Response::error($error);
        } catch (ToolError $exception) {
            $outcome = in_array($exception->status, [401, 403], true) ? 'denied' : 'error';
            $error = $exception->getMessage();

            return Response::error($error);
        } catch (Throwable $exception) {
            report($exception);
            $error = 'SolidTime could not complete the tool call.';

            return Response::error($error);
        } finally {
            $call->forceFill([
                'organization_id' => $organization?->getKey(),
                'outcome' => $outcome,
                'error' => $error === null ? null : Str::limit($error, 490),
                'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            ])->save();
        }
    }

    private function context(User $user, ?string $organizationId, InternalApi $api): ToolContext
    {
        $organizationId ??= $user->current_team_id;
        $member = $organizationId === null ? null : Member::query()
            ->where('user_id', $user->getKey())
            ->where('organization_id', $organizationId)
            ->first();
        if ($member === null) {
            throw new ToolError('You are not a member of that organization. Call get_context for your organizations.', 403);
        }
        /** @var Organization $organization */
        $organization = Organization::query()->findOrFail($organizationId);

        return new ToolContext($user, $organization, $member, $api);
    }

    /**
     * Identifiers, numbers, dates and settings are logged; other text is logged as its length.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function redact(array $arguments, int $depth = 0): array
    {
        $logged = [];
        foreach (array_slice($arguments, 0, 40, true) as $key => $value) {
            $key = (string) $key;
            if (! preg_match('/\A[a-z_]{1,40}\z/', $key)) {
                $logged['[other]'] = true;

                continue;
            }
            $logged[$key] = match (true) {
                is_bool($value), is_int($value), is_float($value), $value === null => $value,
                is_string($value) && (str_ends_with($key, '_id') || in_array($key, self::LOGGED_STRINGS, true)) => Str::limit($value, 64),
                is_string($value) => ['length' => mb_strlen($value)],
                is_array($value) && array_is_list($value) && str_ends_with($key, '_ids') => array_map(fn (mixed $id): mixed => is_string($id) ? Str::limit($id, 64) : null, array_slice($value, 0, 50)),
                is_array($value) && $depth < 1 => $this->redact($value, $depth + 1),
                default => ['items' => is_array($value) ? count($value) : null],
            };
        }

        return $logged;
    }
}
