<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Banner extends Model
{
    use HasFactory;

    protected $fillable = [
        'image_path',
        'title_en',
        'title_ru',
        'title_hy',
        'link_url',
        'sort_order',
        'is_active',
    ];

    /**
     * Get the title based on current locale.
     */
    public function getTitleAttribute()
    {
        $locale = app()->getLocale();
        $field = 'title_' . $locale;
        return $this->{$field} ?? $this->title_en;
    }
}
