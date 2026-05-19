<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseReply extends Model
{
    use HasFactory;

    public const VISIBILITIES = ['public', 'internal'];

    protected $table = 'case_replies';

    protected $fillable = [
        'case_id',
        'user_id',
        'body',
        'visibility',
        'attachments',
    ];

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
        ];
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(AdminCase::class, 'case_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
