<?php

namespace App\Http\Resources\Api\Concerns;

use Illuminate\Http\Request;

trait ResolvesApiLanguage
{
    /**
     * Resolved locale from {@see \App\Http\Middleware\ResolveLanguage} (`?lang` or `Accept-Language`).
     */
    protected function apiLang(Request $request): string
    {
        $lang = $request->attributes->get('lang');

        return is_string($lang) && $lang !== '' ? $lang : 'en';
    }
}
