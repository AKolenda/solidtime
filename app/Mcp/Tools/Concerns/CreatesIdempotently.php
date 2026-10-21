<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use Illuminate\Contracts\JsonSchema\JsonSchema;

/** An optional request_id that makes a creation safe to retry (the API's Idempotency-Key). */
trait CreatesIdempotently
{
    protected function requestIdArgument(JsonSchema $schema): mixed
    {
        return $schema->string()->pattern('^[A-Za-z0-9_-]{12,100}$')->description('Optional unique ID for this creation (12 to 100 letters, digits, - or _). Retrying with the same request_id and arguments returns the first result instead of creating a duplicate.');
    }

    /**
     * @return array<int, string>
     */
    protected function requestIdRule(): array
    {
        return ['sometimes', 'string', 'regex:/\A[A-Za-z0-9_-]{12,100}\z/'];
    }

    /**
     * @return array<string, string>
     */
    protected function idempotencyHeaders(?string $requestId): array
    {
        return $requestId === null ? [] : ['Idempotency-Key' => 'mcp:'.$requestId];
    }
}
