<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One social-inbox thread: a customer (by PSID) talking to a Meta page.
 * See migration 2026_07_03_000100_create_social_inbox_tables.php.
 */
class SocialConversation extends Model
{
    use SoftDeletes;

    protected $table = 'social_conversations';

    public const CHANNELS = ['facebook', 'instagram'];

    protected $fillable = [
        'channel',
        'page_id',
        'psid',
        'customer_name',
        'lead_id',
        'company_id',
        'last_message_at',
        'unread_count',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'unread_count' => 'integer',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(SocialMessage::class, 'conversation_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class, 'lead_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
