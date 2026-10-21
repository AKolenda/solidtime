<?php

declare(strict_types=1);

use App\Http\Middleware\RestrictMcpToInternalNetwork;
use App\Mcp\Servers\SolidtimeServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

Route::middleware(RestrictMcpToInternalNetwork::class)->group(static function (): void {
    // OAuth discovery and dynamic client registration for MCP clients; consent and tokens use Passport
    Route::middleware('throttle:mcp-oauth')->group(static function (): void {
        Mcp::oauthRoutes();
    });

    Mcp::web('/mcp', SolidtimeServer::class)
        ->middleware(['auth:api', 'verified', 'throttle:mcp'])
        ->name('mcp');
});
