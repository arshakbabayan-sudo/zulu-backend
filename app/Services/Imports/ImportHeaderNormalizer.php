<?php

namespace App\Services\Imports;

class ImportHeaderNormalizer
{
    public static function normalize(string $header): string
    {
        $h = trim($header);
        if ($h === '') {
            return '';
        }

        $h = preg_replace('/[^\p{L}\p{N}]+/u', '_', $h) ?? '';
        $h = strtolower($h);
        $h = trim($h, '_');
        $h = preg_replace('/_+/', '_', $h) ?? '';

        return $h;
    }

    /**
     * @param  list<string>  $rawHeaders
     * @return array<string, string> map normalized_key => first raw header label (for raw payload)
     */
    public static function buildNormalizedKeyToRawLabelMap(array $rawHeaders): array
    {
        $map = [];
        foreach ($rawHeaders as $raw) {
            $key = self::normalize((string) $raw);
            if ($key === '') {
                continue;
            }
            if (! array_key_exists($key, $map)) {
                $map[$key] = (string) $raw;
            }
        }

        return $map;
    }
}
