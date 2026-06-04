<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Admin note attached to a user (B2C customer or staff).
 *
 * Each note is authored by an admin user (`author_user_id`) and lives on the
 * subject user (`user_id`). `body` is the note text. Soft-deleted notes are
 * hidden from the inline detail pane but retained for audit (Phase 7.1 PII
 * cleanup rule: anonymise/erase content, never lose the row).
 */
class UserNote extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'author_user_id',
        'body',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
