<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * YouTube's embed player refuses to play (error 153) when the embedding page sends no
 * Referer at all — a bare "no-referrer" policy breaks the free-preview video. This still
 * limits what a cross-origin destination learns (only the origin, never the full URL/path),
 * which is what YouTube's player needs to work while keeping most of no-referrer's privacy
 * benefit.
 */
class SetReferrerPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }
}
