<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Roadmap §4 — customer support chat widget (zulu.am floating panel) strings.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $rows = [
            ['button_label', 'Support chat', 'Աջակցության չաթ', 'Чат поддержки'],
            ['title', 'ZULU support', 'ZULU աջակցություն', 'Поддержка ZULU'],
            ['subtitle', 'We usually reply within minutes.', 'Սովորաբար պատասխանում ենք րոպեների ընթացքում։', 'Обычно отвечаем в течение нескольких минут.'],
            ['empty', 'Write to us — how can we help?', 'Գրիր մեզ — ինչո՞վ կարող ենք օգնել։', 'Напишите нам — чем можем помочь?'],
            ['placeholder', 'Type a message…', 'Գրիր հաղորդագրություն…', 'Напишите сообщение…'],
            ['send', 'Send', 'Ուղարկել', 'Отправить'],
            ['error', 'Could not send — try again.', 'Չհաջողվեց ուղարկել — փորձիր նորից։', 'Не удалось отправить — попробуйте ещё раз.'],
        ];

        $batch = [];
        foreach ($rows as [$suffix, $en, $hy, $ru]) {
            foreach (['en' => $en, 'hy' => $hy, 'ru' => $ru] as $lang => $value) {
                $batch[] = [
                    'language_code' => $lang,
                    'key' => 'chat.widget.'.$suffix,
                    'value' => $value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('ui_translations')->upsert(
            $batch,
            ['language_code', 'key'],
            ['value', 'updated_at']
        );

        foreach (['en', 'hy', 'ru'] as $lang) {
            Cache::forget("ui_translations_{$lang}");
        }
    }

    public function down(): void
    {
        // Translations may be refined afterwards — keep.
    }
};
