<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UiTranslation extends Model
{
    protected $table = 'ui_translations';

    protected $fillable = [
        'language_code',
        'key',
        'value',
    ];

    /**
     * @return BelongsTo<SupportedLanguage, $this>
     */
    public function language(): BelongsTo
    {
        return $this->belongsTo(SupportedLanguage::class, 'language_code', 'code');
    }
}
