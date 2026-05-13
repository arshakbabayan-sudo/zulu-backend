<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FooterColumn extends Model
{
    protected $fillable = [
        'slug', 'title_en', 'title_ru', 'title_hy',
        'position', 'is_visible',
    ];

    protected function casts(): array
    {
        return [
            'is_visible' => 'boolean',
            'position' => 'integer',
        ];
    }

    /** @return HasMany<FooterLink, $this> */
    public function links(): HasMany
    {
        return $this->hasMany(FooterLink::class, 'column_id')->orderBy('position');
    }
}
