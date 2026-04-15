<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WidgetContent extends Model
{
    use HasFactory;

    protected $fillable = [
        'page_id',
        'widget_slug',
        'ui_card_number',
        'widget_content',
        'position',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'widget_content' => 'array',
            'position' => 'integer',
            'status' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /**
     * @return HasMany<WidgetContentTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(WidgetContentTranslation::class);
    }

    /**
     * @return BelongsTo<Widget, $this>
     */
    public function widget(): BelongsTo
    {
        return $this->belongsTo(Widget::class, 'widget_slug', 'widget_slug');
    }

    /**
     * Return translated widget_content or fallback default content.
     *
     * @return array<mixed>|null
     */
    public function getTranslation(string $lang): ?array
    {
        $translation = $this->translations()
            ->where('lang', $lang)
            ->first();

        return $translation?->widget_content ?? $this->widget_content;
    }
}
