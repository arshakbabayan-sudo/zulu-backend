<?php

namespace App\Services\Social;

use App\Models\SocialConversation;
use App\Models\SocialMessage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Stores inbound social-inbox messages (Facebook Messenger / Instagram Direct)
 * into social_conversations + social_messages, deduped by Meta's message id.
 *
 * Kept separate from the webhook controller so the same ingest path can serve
 * other channels/tests without going through HTTP.
 */
class SocialInboxService
{
    /**
     * Record one inbound customer message. Idempotent: a re-delivered webhook
     * carrying the same external message id is a no-op (returns the existing
     * row). Returns null only when there is nothing to store (no text and no
     * attachments).
     *
     * @param  array<int, array<string, mixed>>|null  $attachments
     * @param  array<string, mixed>  $raw
     */
    public function recordInbound(
        string $channel,
        string $pageId,
        string $psid,
        ?string $externalMessageId,
        ?string $text,
        ?array $attachments,
        array $raw = [],
        ?int $timestampMs = null
    ): ?SocialMessage {
        $channel = in_array($channel, SocialConversation::CHANNELS, true) ? $channel : 'facebook';

        if (($text === null || $text === '') && empty($attachments)) {
            // Read-receipts, deliveries, reactions etc. carry no body — ignore.
            return null;
        }

        // Dedupe first: if we've already stored this Meta message id, stop.
        if ($externalMessageId !== null && $externalMessageId !== '') {
            $existing = SocialMessage::query()
                ->where('external_message_id', $externalMessageId)
                ->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        $conversation = SocialConversation::query()->firstOrCreate(
            ['channel' => $channel, 'page_id' => $pageId, 'psid' => $psid],
            ['unread_count' => 0],
        );

        $when = $timestampMs !== null
            ? Carbon::createFromTimestampMs($timestampMs)
            : Carbon::now();

        try {
            $message = new SocialMessage([
                'conversation_id' => $conversation->id,
                'direction' => SocialMessage::DIRECTION_IN,
                'external_message_id' => $externalMessageId ?: null,
                'sender_psid' => $psid,
                'text' => $text,
                'attachments' => $attachments ?: null,
                'raw' => $raw ?: null,
            ]);
            $message->created_at = $when;
            $message->updated_at = $when;
            $message->save();
        } catch (QueryException $e) {
            // A concurrent delivery of the same mid raced us to the unique
            // index — fetch and return the winner instead of 500ing.
            if ($this->isUniqueViolation($e) && $externalMessageId) {
                $winner = SocialMessage::query()
                    ->where('external_message_id', $externalMessageId)
                    ->first();
                if ($winner !== null) {
                    return $winner;
                }
            }
            throw $e;
        }

        $conversation->forceFill([
            'last_message_at' => $when,
            'unread_count' => $conversation->unread_count + 1,
        ])->save();

        return $message;
    }

    /**
     * Unique-constraint violation across Postgres (23505) + SQLite (23000).
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        if ($sqlState === '23505' || $sqlState === '23000') {
            return true;
        }
        $message = strtolower($e->getMessage());

        return str_contains($message, 'unique constraint')
            || str_contains($message, 'unique violation')
            || str_contains($message, 'duplicate key');
    }
}
