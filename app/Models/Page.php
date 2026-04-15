<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Page extends Model
{
    use HasFactory;

    protected $fillable = [
        'page_name',
        'page_slug',
        'meta_title',
        'meta_keywords',
        'meta_description',
        'status',
        'enable_seo',
        'is_bread_crumb',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta_keywords' => 'array',
            'status' => 'integer',
            'enable_seo' => 'boolean',
            'is_bread_crumb' => 'boolean',
        ];
    }

    /**
     * @return HasMany<PageTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(PageTranslation::class);
    }

    /**
     * @return HasMany<WidgetContent, $this>
     */
    public function widgetContents(): HasMany
    {
        return $this->hasMany(WidgetContent::class)->orderBy('position', 'asc');
    }

    /**
     * Return translated value for a field or fallback to default value.
     */
    public function getTranslation(string $field, string $lang): mixed
    {
        $translation = $this->translations()
            ->where('lang', $lang)
            ->first();

        if ($translation !== null && $translation->{$field} !== null) {
            return $translation->{$field};
        }

        return $this->{$field};
    }
}
