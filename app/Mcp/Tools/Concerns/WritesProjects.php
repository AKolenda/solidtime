<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Mcp\Present;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** Fields shared by the tools that create or change projects and tasks. */
trait WritesProjects
{
    /** Colors the web app offers for new projects */
    private const COLORS = ['#ef5350', '#ec407a', '#ab47bc', '#7e57c2', '#5c6bc0', '#42a5f5', '#29b6f6', '#26c6da', '#26a69a', '#66bb6a', '#9ccc65', '#d4e157', '#ffee58', '#ffca28', '#ffa726', '#ff7043', '#8d6e63', '#bdbdbd', '#78909c'];

    /**
     * @return array<string, mixed>
     */
    protected function projectArguments(JsonSchema $schema): array
    {
        return [
            'color' => $schema->string()->pattern('^#[0-9a-fA-F]{6}$')->description('Color as #rrggbb.'),
            'client_id' => $schema->string()->format('uuid')->nullable()->description('Client (customer) from list_clients, or null for none.'),
            'is_billable' => $schema->boolean()->description('Time on this project is billable.'),
            'is_public' => $schema->boolean()->description('Visible to every member, not only project members.'),
            'estimated_hours' => $schema->number()->min(0)->max(1000000)->nullable()->description('Estimated hours for the whole project, or null for none.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function projectRules(): array
    {
        return [
            'color' => ['sometimes', 'string', 'regex:/\A#[0-9a-fA-F]{6}\z/'],
            'client_id' => ['sometimes', 'nullable', 'string', 'uuid'],
            'is_billable' => ['sometimes', 'boolean:strict'],
            'is_public' => ['sometimes', 'boolean:strict'],
            'estimated_hours' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000'],
        ];
    }

    protected function defaultColor(string $name): string
    {
        return self::COLORS[crc32($name) % count(self::COLORS)];
    }

    protected function seconds(mixed $hours): ?int
    {
        return $hours === null ? null : (int) round(((float) $hours) * 3600);
    }

    protected function presentProject(): Closure
    {
        return fn (mixed $data): ?array => is_array($data) ? Present::project($data) : null;
    }

    protected function presentTask(): Closure
    {
        return fn (mixed $data): ?array => is_array($data) ? Present::task($data) : null;
    }
}
