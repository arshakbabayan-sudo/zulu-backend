<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationTemplate extends Model
{
    protected $table = 'notification_templates';

    /** @var list<string> */
    public const CHANNELS = ['in_app', 'email'];

    protected $fillable = [
        'event_type',
        'language_code',
        'channel',
        'title_template',
        'body_template',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<SupportedLanguage, $this>
     */
    public function language(): BelongsTo
    {
        return $this->belongsTo(SupportedLanguage::class, 'language_code', 'code');
    }
}
