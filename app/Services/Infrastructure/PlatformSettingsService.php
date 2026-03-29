<?php

namespace App\Services\Infrastructure;

use App\Models\PlatformSetting;

class PlatformSettingsService
{
    public function get(string $key, mixed $default = null): mixed
    {
        $row = PlatformSetting::query()->where('key', $key)->first();
        if ($row === null) {
            return $default;
        }

        return match ($row->type) {
            'integer' => (int) $row->value,
            'decimal' => (float) $row->value,
            'boolean' => filter_var($row->value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($row->value, true),
            default => $row->value,
        };
    }

    public function set(string $key, mixed $value): void
    {
        PlatformSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => (string) $value, 'updated_at' => now()]
        );
    }

    public function getB2cMarkupPercent(): float
    {
        return (float) $this->get('b2c_markup_percent', 15.0);
    }
}
