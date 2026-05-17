<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Seed the i18n keys for the "🔄 Translation in progress" banner that the
 * customer site renders when a record's requested-language version hasn't
 * been filled by the AI translator yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $rows = [
            // Title
            ['en', 'translation.in_progress.title', '🔄 Translation in progress'],
            ['hy', 'translation.in_progress.title', '🔄 Թարգմանությունը մշակվում է'],
            ['ru', 'translation.in_progress.title', '🔄 Перевод в процессе'],

            // Partial detail
            ['en', 'translation.in_progress.partial', 'Some text is not yet translated — showing the original-language version.'],
            ['hy', 'translation.in_progress.partial', 'Որոշ տեքստերը դեռ թարգմանված չեն. ցույց ենք տալիս աղբյուր լեզվի տարբերակը։'],
            ['ru', 'translation.in_progress.partial', 'Часть текста ещё не переведена — показываем оригинал.'],

            // Pending detail
            ['en', 'translation.in_progress.pending', 'This listing has not been translated yet — showing the original-language version.'],
            ['hy', 'translation.in_progress.pending', 'Այս հայտարարության թարգմանությունը դեռ պատրաստ չէ. ցույց ենք տալիս աղբյուր լեզվի տարբերակը։'],
            ['ru', 'translation.in_progress.pending', 'Этот объект ещё не переведён — показываем оригинал.'],
        ];

        foreach ($rows as [$lang, $key, $value]) {
            $existing = DB::table('ui_translations')
                ->where('language_code', $lang)
                ->where('key', $key)
                ->first();
            if ($existing === null) {
                DB::table('ui_translations')->insert([
                    'language_code' => $lang,
                    'key' => $key,
                    'value' => $value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        foreach (['en', 'hy', 'ru'] as $lang) {
            Cache::forget('ui_translations_'.$lang);
        }
    }

    public function down(): void
    {
        DB::table('ui_translations')
            ->where('key', 'like', 'translation.in_progress.%')
            ->delete();
        foreach (['en', 'hy', 'ru'] as $lang) {
            Cache::forget('ui_translations_'.$lang);
        }
    }
};
