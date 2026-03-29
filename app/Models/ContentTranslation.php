<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentTranslation extends Model
{
    protected $table = 'content_translations';

    /** @var list<string> */
    public const ENTITY_TYPES = [
        'offer',
        'package',
        'hotel',
        'flight',
        'transfer',
        'excursion',
        'car',
        'visa',
        'company',
    ];

    /** @var list<string> */
    public const TRANSLATABLE_FIELDS = [
        'title',
        'subtitle',
        'description',
        'package_title',
        'package_subtitle',
        'hotel_name',
        'short_description',
        'highlights',
        'included_summary',
        'notes',
    ];

    protected $fillable = [
        'entity_type',
        'entity_id',
        'language_code',
        'field_name',
        'translated_value',
    ];

    /**
     * @return BelongsTo<SupportedLanguage, $this>
     */
    public function language(): BelongsTo
    {
        return $this->belongsTo(SupportedLanguage::class, 'language_code', 'code');
    }
}
