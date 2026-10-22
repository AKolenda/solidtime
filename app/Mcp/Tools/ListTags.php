<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\ToolContext;
use App\Mcp\ToolError;
use App\Models\Tag;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ListTags extends McpTool
{
    protected string $name = 'list_tags';

    protected string $description = 'Tags that time entries can carry. Search narrows by name.';

    protected function arguments(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->max(255)->description('Only tags whose name contains this text.'),
        ];
    }

    protected function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:255'],
        ];
    }

    protected function run(array $input, ToolContext $context): Response
    {
        if (! $context->can('tags:view')) {
            throw new ToolError('You do not have permission to see the tags.', 403);
        }
        // As TagController::index
        $query = Tag::query()->whereBelongsTo($context->organization, 'organization');
        if (isset($input['search']) && trim($input['search']) !== '') {
            $query->where('name', 'ilike', '%'.addcslashes(trim($input['search']), '%_\\').'%');
        }
        $count = (clone $query)->count();
        $tags = $query->orderBy('created_at', 'desc')->orderBy('id')->limit(500)->get();

        return Response::json([
            'tags' => $tags->map(fn (Tag $tag): array => ['id' => $tag->getKey(), 'name' => $tag->name])->all(),
            'count' => $count,
            'truncated' => $count > 500,
        ]);
    }
}
