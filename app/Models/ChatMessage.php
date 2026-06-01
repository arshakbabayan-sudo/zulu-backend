<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Internal chat message. See migration
 * 2026_06_01_000090_create_chat_messages_table.php.
 */
class ChatMessage extends Model
{
    protected $table = 'chat_messages';

    protected $fillable = [
        'conversation_id',
        'sender_id',
        'body',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
