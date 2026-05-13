<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seed missing home-page section translation keys. Used by:
     *   - NewPopularDestinations.tsx (subtitle)
     *   - HomeNewsletter.tsx middle banner (banner_title / _body / _cta)
     *
     * The corresponding *.title keys already exist (popular_destinations.title,
     * newsletter.title were seeded earlier and verified live).
     */
    public function up(): void
    {
        $now = now();

        $rows = [
            ['home.popular_destinations.subtitle', 'The best tours and excursions in every city', 'Лучшие туры и экскурсии в каждом городе', 'Լավագույն տուրեր և էքսկուրսիաներ ամեն քաղաքում'],
            ['home.newsletter.banner_title', 'Subscribe to our newsletter', 'Подпишитесь на нашу рассылку', 'Բաժանորդագրվիր մեր տեղեկագրին'],
            ['home.newsletter.banner_body', 'Be the first to receive exclusive offers and the latest news on our services directly in your inbox.', 'Первыми получайте эксклюзивные предложения и последние новости о наших услугах прямо на вашу электронную почту.', 'Առաջինը ստացիր բացառիկ առաջարկներ և մեր ծառայությունների մասին վերջին նորությունները ուղիղ քո էլ. փոստի արկղում:'],
            ['home.newsletter.banner_cta', 'Join us', 'Присоединиться', 'Միանալ'],
        ];

        foreach ($rows as $r) {
            [$key, $en, $ru, $hy] = $r;
            foreach (['en' => $en, 'ru' => $ru, 'hy' => $hy] as $lang => $value) {
                DB::table('ui_translations')->updateOrInsert(
                    ['key' => $key, 'language_code' => $lang],
                    ['value' => $value, 'updated_at' => $now, 'created_at' => $now]
                );
            }
        }

        Cache::forget('ui_translations_en');
        Cache::forget('ui_translations_hy');
        Cache::forget('ui_translations_ru');
    }

    public function down(): void
    {
        DB::table('ui_translations')
            ->whereIn('key', [
                'home.popular_destinations.subtitle',
                'home.newsletter.banner_title',
                'home.newsletter.banner_body',
                'home.newsletter.banner_cta',
            ])
            ->delete();
        Cache::forget('ui_translations_en');
        Cache::forget('ui_translations_hy');
        Cache::forget('ui_translations_ru');
    }
};
