<?php

declare(strict_types=1);

namespace App\Mcp;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

/**
 * Sends MCP tool calls through the regular /api/v1 routes as the same user, so every
 * permission check, validation rule, overlap check and idempotency rule applies unchanged.
 */
class InternalApi
{
    public function __construct(private readonly Kernel $kernel) {}

    /**
     * @param  array<string, mixed>  $data  query parameters for GET and DELETE, JSON body otherwise
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     *
     * @throws ToolError when the API refuses the request
     */
    public function send(string $method, string $path, array $data = [], array $headers = []): array
    {
        $original = app('request');
        $server = [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_HOST' => $original->getHttpHost(),
            'HTTPS' => $original->isSecure() ? 'on' : 'off',
            'REMOTE_ADDR' => $original->server('REMOTE_ADDR'),
        ];
        if ($original->headers->has('Authorization')) {
            $server['HTTP_AUTHORIZATION'] = $original->header('Authorization');
        }
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $uri = '/api/v1/'.ltrim($path, '/');
        $query = in_array($method, ['GET', 'DELETE'], true);
        $request = $query
            ? Request::create($uri, $method, $data, [], [], $server)
            : Request::create($uri, $method, [], [], [], $server + ['CONTENT_TYPE' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));

        try {
            $response = $this->kernel->handle($request);
        } finally {
            // The kernel rebinds the current request; the MCP request continues afterwards
            app()->instance('request', $original);
            Facade::clearResolvedInstance('request');
        }

        $status = $response->getStatusCode();
        $content = (string) $response->getContent();
        $body = $content === '' ? [] : json_decode($content, true);
        if (! is_array($body)) {
            $body = [];
        }
        if ($status >= 400) {
            throw ToolError::fromApi($status, $body);
        }

        return $body;
    }

    /**
     * Read every page of a page-paginated list, up to a bound.
     *
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    public function all(string $path, array $query = [], int $maxPages = 20): array
    {
        $items = [];
        for ($page = 1; $page <= $maxPages; $page++) {
            $body = $this->send('GET', $path, [...$query, 'page' => $page]);
            array_push($items, ...($body['data'] ?? []));
            $last = $body['meta']['last_page'] ?? null;
            if (! is_int($last) || $page >= $last) {
                return $items;
            }
        }
        throw new ToolError('There are too many records to list at once. Narrow the request.');
    }
}
