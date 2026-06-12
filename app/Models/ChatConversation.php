<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Internal chat conversation. See migration
 * 2026_06_01_000070_create_chat_conversations_table.php.
 *
 * type 'customer' (roadmap §4) = B2C customer ↔ platform support thread:
 * company_id is null, customer_user_id pins the owner, one thread per
 * customer reused forever.
 */
class ChatConversation extends Model
{
    protected $table = 'chat_conversations';

    public const TYPES = ['direct', 'group', 'customer'];

    public const TYPE_CUSTOMER = 'customer';

    protected $fillable = [
        'company_id',
        'type',
        'title',
        'created_by_user_id',
        'customer_user_id',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_user_id');
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
