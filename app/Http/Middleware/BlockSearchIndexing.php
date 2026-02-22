<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockSearchIndexing
{
    private const X_ROBOTS_TAG_VALUE = 'noindex, nofollow, noarchive, nosnippet, noimageindex';

    /**
     * Add anti-indexing header to every web response.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Robots-Tag', self::X_ROBOTS_TAG_VALUE);

        return $response;
    }
}

