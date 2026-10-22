<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Mcp\ToolError;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * A preview hands out the entry's version; the confirming call sends it back, and the API
 * refuses the change (412) if the entry is no longer in the state the user approved.
 */
trait BindsToPreviewedVersion
{
    protected function versionArgument(JsonSchema $schema): mixed
    {
        return $schema->string()->max(200)->description('The expected_version the preview returned. Required with confirm, so only the previewed state is changed.');
    }

    /**
     * @return array<int, string>
     */
    protected function versionRule(): array
    {
        return ['sometimes', 'string', 'max:200'];
    }

    /**
     * If-Match for the confirming call: the previewed version, never one read just now.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    protected function versionHeaders(array $input): array
    {
        if (($input['confirm'] ?? null) !== true) {
            return [];
        }
        if (! isset($input['expected_version'])) {
            throw new ToolError('Confirm with the expected_version from the preview, so only the previewed state of the entry is changed.');
        }

        return ['If-Match' => $input['expected_version']];
    }
}
