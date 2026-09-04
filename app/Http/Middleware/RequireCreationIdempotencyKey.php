<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class RequireCreationIdempotencyKey
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasHeader('Idempotency-Key')) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'An Idempotency-Key is required for this endpoint.']);
        }

        return $next($request);
    }
}
