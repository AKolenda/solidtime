<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\ToolContext;
use App\Mcp\ToolError;
use App\Models\Client;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ListClients extends McpTool
{
    protected string $name = 'list_clients';

    protected string $description = 'Clients (customers) that projects belong to. Search narrows by name.';

    protected function arguments(JsonSchema $schema): array
    {
        return [
            'archived' => $schema->string()->enum(['false', 'true', 'all'])->description('Active clients (default), archived ones, or all.'),
            'search' => $schema->string()->max(255)->description('Only clients whose name contains this text.'),
        ];
    }

    protected function rules(): array
    {
        return [
            'archived' => ['sometimes', 'string', 'in:false,true,all'],
            'search' => ['sometimes', 'string', 'max:255'],
        ];
    }

    protected function run(array $input, ToolContext $context): Response
    {
        if (! $context->can('clients:view')) {
            throw new ToolError('You do not have permission to see the clients.', 403);
        }
        // As ClientController::index
        $query = Client::query()->whereBelongsTo($context->organization, 'organization');
        if (! $context->can('clients:view:all')) {
            $query->visibleByEmployee($context->user);
        }
        $archived = $input['archived'] ?? 'false';
        if ($archived !== 'all') {
            $archived === 'true' ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');
        }
        if (isset($input['search']) && trim($input['search']) !== '') {
            $query->where('name', 'ilike', '%'.addcslashes(trim($input['search']), '%_\\').'%');
        }
        $count = (clone $query)->count();
        $clients = $query->orderBy('created_at', 'desc')->orderBy('id')->limit(500)->get();

        return Response::json([
            'clients' => $clients->map(fn (Client $client): array => ['id' => $client->getKey(), 'name' => $client->name, 'is_archived' => $client->archived_at !== null])->all(),
            'count' => $count,
            'truncated' => $count > 500,
        ]);
    }
}
