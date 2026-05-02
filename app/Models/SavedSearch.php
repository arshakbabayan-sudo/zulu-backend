<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedSearch extends Model
{
    protected $table = 'saved_searches';

    protected $fillable = [
        'user_id',
        'name',
        'module_type',
        'query_string',
        'filters',
        'alerts_enabled',
        'last_run_at',
        'result_count_at_save',
    ];

    protected $casts = [
        'filters' => 'array',
        'alerts_enabled' => 'boolean',
        'last_run_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
