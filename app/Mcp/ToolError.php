<?php

declare(strict_types=1);

namespace App\Mcp;

use Illuminate\Support\Arr;
use RuntimeException;

/** A failure whose message is safe to show to the MCP client. */
class ToolError extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromApi(int $status, array $body): self
    {
        $messages = is_array($body['errors'] ?? null) ? Arr::flatten($body['errors']) : [];
        $errors = implode(' ', array_slice(array_filter($messages, 'is_string'), 0, 5));
        $message = is_string($body['message'] ?? null) ? $body['message'] : '';

        return new self(match (true) {
            $status === 401 => 'Sign in to SolidTime again.',
            $status === 403 => 'You do not have permission to do that in this organization.',
            $status === 404 => 'Not found, or not visible to you.',
            $status === 429 => 'Too many requests. Wait a minute and try again.',
            $status === 422 && $errors !== '' => $errors,
            $status >= 500 => 'SolidTime could not complete the request. Try again later.',
            $message !== '' => $message,
            default => 'SolidTime refused the request ('.$status.').',
        }, $status);
    }
}
