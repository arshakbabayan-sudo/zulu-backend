<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportedLanguage extends Model
{
    protected $table = 'supported_languages';

    protected $fillable = [
        'code',
        'name',
        'name_en',
        'is_default',
        'is_enabled',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_enabled' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<ContentTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(ContentTranslation::class, 'language_code', 'code');
    }

    /**
     * @return HasMany<NotificationTemplate, $this>
     */
    public function notificationTemplates(): HasMany
    {
        return $this->hasMany(NotificationTemplate::class, 'language_code', 'code');
    }
}
