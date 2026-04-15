<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WidgetContentTranslation extends Model
{
    use HasFactory;

    protected $fillable = [
        'widget_content_id',
        'page_id',
        'lang',
        'widget_content',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'widget_content' => 'array',
        ];
    }

    /**
     * @return BelongsTo<WidgetContent, $this>
     */
    public function widgetContent(): BelongsTo
    {
        return $this->belongsTo(WidgetContent::class);
    }
}
