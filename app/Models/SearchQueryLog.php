<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SearchQueryLog extends Model
{
    protected $table = 'search_query_log';

    protected $fillable = [
        'user_id',
        'query_string',
        'module_type',
        'filters',
        'result_count',
        'happened_at',
    ];

    protected $casts = [
        'filters' => 'array',
        'happened_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
