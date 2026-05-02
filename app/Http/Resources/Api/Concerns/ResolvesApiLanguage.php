<?php

namespace App\Http\Resources\Api\Concerns;

use App\Http\Middleware\ResolveLanguage;
use Illuminate\Http\Request;

trait ResolvesApiLanguage
{
    /**
     * Resolved locale from {@see ResolveLanguage} (`?lang` or `Accept-Language`).
     */
    protected function apiLang(Request $request): string
    {
        $lang = $request->attributes->get('lang');

        return is_string($lang) && $lang !== '' ? $lang : 'en';
    }
}
