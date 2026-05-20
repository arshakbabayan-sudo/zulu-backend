<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NewsletterSubscription extends Model
{
    use HasFactory;

    public const SOURCE_BOTTOM_FORM = 'bottom_form';

    public const SOURCE_MIDDLE_FORM = 'middle_form';

    public const SOURCE_FOOTER = 'footer';

    public const SOURCE_OTHER = 'other';

    /** @var list<string> */
    public const SOURCES = [
        self::SOURCE_BOTTOM_FORM,
        self::SOURCE_MIDDLE_FORM,
        self::SOURCE_FOOTER,
        self::SOURCE_OTHER,
    ];

    protected $fillable = [
        'email',
        'lang',
        'source',
        'ip',
        'user_agent',
        'subscribed_at',
        'unsubscribed_at',
        // Phase 3.3 GDPR double opt-in
        'confirmed_at',
        'confirmation_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subscribed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }
}
