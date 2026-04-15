<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Widget extends Model
{
    use HasFactory;

    protected $fillable = [
        'widget_name',
        'widget_slug',
        'icon',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => 'integer',
        ];
    }

    /**
     * @return HasMany<WidgetContent, $this>
     */
    public function widgetContents(): HasMany
    {
        return $this->hasMany(WidgetContent::class, 'widget_slug', 'widget_slug');
    }
}
