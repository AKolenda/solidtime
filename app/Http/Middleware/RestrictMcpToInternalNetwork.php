<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * The MCP endpoint and its OAuth client registration answer only on the internal network.
 *
 * Every address a request carries must be internal: the connecting address and each hop that
 * forwarding headers name, whether or not the proxy is trusted. A public client behind a
 * reverse proxy is named by that proxy, and an internal address it claims for itself is
 * followed by its real one. A request relayed by Cloudflare (a tunnel) is refused outright.
 */
class RestrictMcpToInternalNetwork
{
    public function handle(Request $request, Closure $next): Response
    {
        $relayed = $request->hasHeader('Cf-Connecting-Ip') || $request->hasHeader('Cf-Ray');
        $networks = config('mcp.allowed_networks');
        $addresses = [$request->server('REMOTE_ADDR'), $request->ip(), ...$this->forwardedAddresses($request)];
        $internal = array_reduce($addresses, fn (bool $all, mixed $ip): bool => $all && is_string($ip) && $ip !== '' && IpUtils::checkIp($ip, $networks), true);
        if (! config('mcp.enabled') || $relayed || ! $internal) {
            abort(404);
        }

        return $next($request);
    }

    /**
     * @return array<int, string>
     */
    private function forwardedAddresses(Request $request): array
    {
        $addresses = [];
        foreach ($request->headers->all('x-forwarded-for') as $header) {
            array_push($addresses, ...array_map('trim', explode(',', $header)));
        }
        foreach ($request->headers->all('x-real-ip') as $header) {
            $addresses[] = trim($header);
        }
        foreach ($request->headers->all('forwarded') as $header) {
            preg_match_all('/for=\s*"?\[?([^\]";,]+)/i', $header, $matches);
            array_push($addresses, ...$matches[1]);
        }

        return array_map($this->address(...), $addresses);
    }

    /**
     * The address itself: "10.0.0.5:1234" is 10.0.0.5, "[fd00::5]" and "[fd00::5]:443" are
     * fd00::5, and a bare IPv6 address stays as it is.
     */
    private function address(string $value): string
    {
        $value = trim($value, ' 	"');
        if (preg_match('/\A\[([^\]]+)\](?::\d+)?\z/', $value, $match)) {
            return $match[1];
        }

        return preg_match('/\A([\d.]+):\d+\z/', $value, $match) ? $match[1] : $value;
    }
}
