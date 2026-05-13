<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FooterLink extends Model
{
    protected $fillable = [
        'column_id', 'label_en', 'label_ru', 'label_hy',
        'url', 'position', 'is_visible', 'open_in_new_tab',
    ];

    protected function casts(): array
    {
        return [
            'is_visible' => 'boolean',
            'open_in_new_tab' => 'boolean',
            'position' => 'integer',
            'column_id' => 'integer',
        ];
    }

    /** @return BelongsTo<FooterColumn, $this> */
    public function column(): BelongsTo
    {
        return $this->belongsTo(FooterColumn::class, 'column_id');
    }
}
