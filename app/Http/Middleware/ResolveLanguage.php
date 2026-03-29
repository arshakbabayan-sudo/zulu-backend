<?php

namespace App\Http\Middleware;

use App\Services\Localization\LocalizationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ResolveLanguage
{
    public function handle(Request $request, Closure $next): Response
    {
        $resolved = 'en';

        try {
            $candidate = null;
            if ($request->query->has('lang')) {
                $candidate = (string) $request->query('lang');
            } elseif ($request->headers->has('Accept-Language')) {
                $header = (string) $request->headers->get('Accept-Language', '');
                $first = trim(explode(',', $header)[0] ?? '');
                $first = preg_replace('/;.*$/', '', $first) ?? '';
                $candidate = trim($first);
            } elseif ($request->user() !== null) {
                $pref = $request->user()->preferred_language;
                $candidate = $pref !== null && $pref !== '' ? (string) $pref : null;
            }

            if ($candidate === null || $candidate === '') {
                $candidate = (string) config('app.locale', 'en');
            }

            $resolved = app(LocalizationService::class)->resolveLanguage($candidate);
        } catch (Throwable) {
            $resolved = 'en';
        }

        $request->attributes->set('lang', $resolved);
        app()->setLocale($resolved);

        return $next($request);
    }
}
