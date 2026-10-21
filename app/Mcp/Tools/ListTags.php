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
class ListTags extends McpTool
{
    protected string $name = 'list_tags';

    protected string $description = 'Tags that time entries can carry.';

    protected function arguments(JsonSchema $schema): array
    {
        return [];
    }

    protected function rules(): array
    {
        return [];
    }

    protected function run(array $input, ToolContext $context): Response
    {
        $tags = $context->api->all($context->path('tags'));

        return Response::json(['tags' => array_map(fn (array $tag): array => ['id' => $tag['id'] ?? null, 'name' => $tag['name'] ?? null], $tags)]);
    }
}
