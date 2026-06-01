<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Internal chat conversation. See migration
 * 2026_06_01_000070_create_chat_conversations_table.php.
 */
class ChatConversation extends Model
{
    protected $table = 'chat_conversations';

    public const TYPES = ['direct', 'group'];

    protected $fillable = [
        'company_id',
        'type',
        'title',
        'created_by_user_id',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ChatParticipant::class, 'conversation_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id');
    }
}
