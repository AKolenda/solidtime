<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * The MCP endpoint and its OAuth client registration answer only on the internal network.
 * A request relayed by Cloudflare (a tunnel) is refused even when its last hop is local.
 */
class RestrictMcpToInternalNetwork
{
    public function handle(Request $request, Closure $next): Response
    {
        $relayed = $request->hasHeader('Cf-Connecting-Ip') || $request->hasHeader('Cf-Ray');
        $ip = $request->ip();
        if (! config('mcp.enabled') || $relayed || $ip === null || ! IpUtils::checkIp($ip, config('mcp.allowed_networks'))) {
            abort(404);
        }

        return $next($request);
    }
}
