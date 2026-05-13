<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HeaderMenuItem extends Model
{
    protected $fillable = [
        'parent_id', 'label_en', 'label_ru', 'label_hy',
        'url', 'position', 'is_visible', 'icon', 'open_in_new_tab',
    ];

    protected function casts(): array
    {
        return [
            'is_visible' => 'boolean',
            'open_in_new_tab' => 'boolean',
            'position' => 'integer',
            'parent_id' => 'integer',
        ];
    }

    /** @return BelongsTo<HeaderMenuItem, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(HeaderMenuItem::class, 'parent_id');
    }

    /** @return HasMany<HeaderMenuItem, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(HeaderMenuItem::class, 'parent_id')->orderBy('position');
    }
}
