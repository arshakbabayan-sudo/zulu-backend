<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Unversioned /api/discovery/* is a compatibility alias; canonical contract is /api/v1/discovery/*.
 * Adds Link (successor-version) always; Deprecation + Sunset only when config provides an HTTP-date.
 */
class DeprecateLegacyDiscoveryApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $path = $request->path();
        if (preg_match('#^api/discovery/(.+)$#', $path, $m)) {
            $successor = url('api/v1/discovery/'.$m[1]);
            $response->headers->set('Link', '<'.$successor.'>; rel="successor-version"');
        }

        $sunset = config('zulu_platform.discovery.unversioned_sunset_http_date');
        if (is_string($sunset) && $sunset !== '') {
            $response->headers->set('Deprecation', 'true');
            $response->headers->set('Sunset', $sunset);
        }

        return $response;
    }
}
