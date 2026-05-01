<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserNotificationPreference extends Model
{
    protected $table = 'user_notification_preferences';

    public const CHANNELS = ['email', 'sms', 'push', 'in_app', 'webhook'];

    protected $fillable = [
        'user_id',
        'event',
        'channel',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Resolve the effective preference for a given user/event/channel triple.
     * Default behavior: enabled (transactional default opt-in). Returns false
     * only when an explicit row exists with enabled=false.
     */
    public static function isEnabled(int $userId, string $event, string $channel): bool
    {
        $row = self::query()
            ->where('user_id', $userId)
            ->where('event', $event)
            ->where('channel', $channel)
            ->first();

        if ($row === null) {
            return true;
        }

        return (bool) $row->enabled;
    }
}
