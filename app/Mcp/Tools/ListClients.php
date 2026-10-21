<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\ToolContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ListClients extends McpTool
{
    protected string $name = 'list_clients';

    protected string $description = 'Clients (customers) that projects belong to.';

    protected function arguments(JsonSchema $schema): array
    {
        return [
            'archived' => $schema->string()->enum(['false', 'true', 'all'])->description('Active clients (default), archived ones, or all.'),
        ];
    }

    protected function rules(): array
    {
        return [
            'archived' => ['sometimes', 'string', 'in:false,true,all'],
        ];
    }

    protected function run(array $input, ToolContext $context): Response
    {
        $clients = $context->api->all($context->path('clients'), ['archived' => $input['archived'] ?? 'false']);

        return Response::json(['clients' => array_map(fn (array $client): array => [
            'id' => $client['id'] ?? null,
            'name' => $client['name'] ?? null,
            'is_archived' => $client['is_archived'] ?? null,
        ], $clients)]);
    }
}
