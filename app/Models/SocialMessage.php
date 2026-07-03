<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single social-inbox message (inbound from a customer or an outbound staff
 * reply). See migration 2026_07_03_000100_create_social_inbox_tables.php.
 */
class SocialMessage extends Model
{
    protected $table = 'social_messages';

    public const DIRECTION_IN = 'in';

    public const DIRECTION_OUT = 'out';

    protected $fillable = [
        'conversation_id',
        'direction',
        'external_message_id',
        'sender_psid',
        'text',
        'attachments',
        'raw',
        'sent_by_user_id',
    ];

    protected $casts = [
        'attachments' => 'array',
        'raw' => 'array',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SocialConversation::class, 'conversation_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }
}
