<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | MCP server
    |--------------------------------------------------------------------------
    |
    | The MCP endpoint (POST /mcp) lets AI clients act as the signed-in user
    | through the regular API, with that user's organization permissions.
    | It is meant for the internal network only: requests with any address
    | (connecting or forwarded) outside "allowed_networks", and requests
    | relayed by Cloudflare, get 404. It is off until MCP_ENABLED=true.
    |
    */

    'enabled' => (bool) env('MCP_ENABLED', false),

    'allowed_networks' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'MCP_ALLOWED_NETWORKS',
        '127.0.0.0/8,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,::1/128,fc00::/7'
    ))))),

    // Tool calls per user and minute
    'rate_limits' => [
        'read' => (int) env('MCP_READ_CALLS_PER_MINUTE', 120),
        'write' => (int) env('MCP_WRITE_CALLS_PER_MINUTE', 30),
    ],

    // Tool-call log retention
    'audit_retention_days' => (int) env('MCP_AUDIT_RETENTION_DAYS', 180),

    /*
    |--------------------------------------------------------------------------
    | OAuth client registration (laravel/mcp)
    |--------------------------------------------------------------------------
    |
    | Redirect URIs that dynamically registered MCP clients may use. Local
    | desktop and CLI clients redirect to localhost.
    |
    */

    'redirect_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'MCP_REDIRECT_DOMAINS',
        'http://localhost,http://127.0.0.1'
    ))))),

    'custom_schemes' => [],

    'authorization_server' => null,

];
