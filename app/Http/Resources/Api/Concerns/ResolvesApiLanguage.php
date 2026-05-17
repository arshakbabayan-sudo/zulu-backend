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

    /**
     * Inspect the requested language's coverage of the entity's translatable
     * fields and return a status the frontend can use to show a "Translation
     * in progress" banner.
     *
     *   'complete' — every checked field has a row in $lang
     *   'partial'  — some fields have a row in $lang, some fall back
     *   'pending'  — no field has a row in $lang yet (showing source lang)
     *   'untracked' — the model doesn't use HasTranslations (defensive)
     *
     * @param  list<string>  $fields
     * @return array{status: string, requested_lang: string, source_lang: ?string, fields_translated: int, fields_total: int}
     */
    protected function computeTranslationStatus(string $lang, array $fields): array
    {
        $sourceLang = is_string($this->attributes['source_lang'] ?? null)
            ? (string) $this->attributes['source_lang']
            : null;

        if (! method_exists($this, 'getTranslationSource')) {
            return [
                'status' => 'untracked',
                'requested_lang' => $lang,
                'source_lang' => $sourceLang,
                'fields_translated' => 0,
                'fields_total' => count($fields),
            ];
        }

        $translated = 0;
        $eligible = 0;
        foreach ($fields as $field) {
            $info = $this->getTranslationSource((string) $field, $lang);
            if (($info['source'] ?? 'missing') === 'missing') {
                continue;
            }
            $eligible++;
            if (($info['source'] ?? null) === 'translated') {
                $translated++;
            }
        }

        $status = 'complete';
        if ($eligible === 0) {
            $status = 'pending';
        } elseif ($translated === 0) {
            $status = 'pending';
        } elseif ($translated < $eligible) {
            $status = 'partial';
        }

        return [
            'status' => $status,
            'requested_lang' => $lang,
            'source_lang' => $sourceLang,
            'fields_translated' => $translated,
            'fields_total' => $eligible,
        ];
    }
}
