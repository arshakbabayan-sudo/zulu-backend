<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Roadmap §4 — enforce "one support thread per customer, reused forever" at the
 * DB level. Without this, two near-simultaneous first messages (double-tap,
 * retry, two tabs) each see no thread and both INSERT one → a split, unrecoverable
 * conversation. We first dedupe any existing duplicates (none expected yet), then
 * add a partial unique index. Both Postgres (prod) and SQLite (tests) support
 * partial unique indexes with a WHERE predicate.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Defensive dedupe: merge any duplicate customer threads into the
        //    earliest one (lowest id), moving its messages + participants over.
        $dupes = DB::table('chat_conversations')
            ->select('customer_user_id', DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as cnt'))
            ->where('type', 'customer')
            ->whereNotNull('customer_user_id')
            ->groupBy('customer_user_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($dupes as $dupe) {
            $extraIds = DB::table('chat_conversations')
                ->where('type', 'customer')
                ->where('customer_user_id', $dupe->customer_user_id)
                ->where('id', '!=', $dupe->keep_id)
                ->pluck('id')
                ->all();

            if ($extraIds === []) {
                continue;
            }

            DB::table('chat_messages')
                ->whereIn('conversation_id', $extraIds)
                ->update(['conversation_id' => $dupe->keep_id]);

            // Drop participant rows that would collide with the kept thread's
            // existing (conversation_id, user_id) unique pair, then move the rest.
            $keptUserIds = DB::table('chat_participants')
                ->where('conversation_id', $dupe->keep_id)
                ->pluck('user_id')
                ->all();
            DB::table('chat_participants')
                ->whereIn('conversation_id', $extraIds)
                ->whereIn('user_id', $keptUserIds)
                ->delete();
            DB::table('chat_participants')
                ->whereIn('conversation_id', $extraIds)
                ->update(['conversation_id' => $dupe->keep_id]);

            DB::table('chat_conversations')->whereIn('id', $extraIds)->delete();
        }

        // 2) Partial unique index — 'customer' is a hardcoded literal, never input.
        DB::statement(
            "CREATE UNIQUE INDEX IF NOT EXISTS chat_conv_one_customer_thread "
            ."ON chat_conversations (customer_user_id) WHERE type = 'customer'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS chat_conv_one_customer_thread');
    }
};
