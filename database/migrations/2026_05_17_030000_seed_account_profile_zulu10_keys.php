<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Seed the i18n keys introduced by the Zulu_10 Profile re-skin
 * (breadcrumb, user card, personal information form fields, delete account).
 * Driven from frontend `defaultUiTranslations` in `lib/lang.ts`; this migration
 * supplies HY and RU values so live users see Armenian/Russian copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $rows = [
            ['en', 'aria.breadcrumb', 'Breadcrumb'],
            ['hy', 'aria.breadcrumb', 'Նավարկման ուղի'],
            ['ru', 'aria.breadcrumb', 'Хлебные крошки'],

            ['en', 'account.profile.personal_info', 'Personal information'],
            ['hy', 'account.profile.personal_info', 'Անձնական տվյալներ'],
            ['ru', 'account.profile.personal_info', 'Личная информация'],

            ['en', 'account.profile.edit', 'Edit'],
            ['hy', 'account.profile.edit', 'Փոփոխել'],
            ['ru', 'account.profile.edit', 'Редактировать'],

            ['en', 'account.profile.edit_avatar_aria', 'Edit photo'],
            ['hy', 'account.profile.edit_avatar_aria', 'Փոփոխել նկարը'],
            ['ru', 'account.profile.edit_avatar_aria', 'Изменить фото'],

            ['en', 'account.profile.field.first_name', 'First name'],
            ['hy', 'account.profile.field.first_name', 'Անուն'],
            ['ru', 'account.profile.field.first_name', 'Имя'],

            ['en', 'account.profile.field.last_name', 'Last name'],
            ['hy', 'account.profile.field.last_name', 'Ազգանուն'],
            ['ru', 'account.profile.field.last_name', 'Фамилия'],

            ['en', 'account.profile.field.email', 'Email'],
            ['hy', 'account.profile.field.email', 'Էլ. փոստ'],
            ['ru', 'account.profile.field.email', 'Эл. почта'],

            ['en', 'account.profile.field.phone', 'Phone number'],
            ['hy', 'account.profile.field.phone', 'Հեռախոս'],
            ['ru', 'account.profile.field.phone', 'Телефон'],

            ['en', 'account.profile.field.birth_date', 'Birth date'],
            ['hy', 'account.profile.field.birth_date', 'Ծննդյան ամսաթիվ'],
            ['ru', 'account.profile.field.birth_date', 'Дата рождения'],

            ['en', 'account.profile.field.nationality', 'Nationality'],
            ['hy', 'account.profile.field.nationality', 'Քաղաքացիություն'],
            ['ru', 'account.profile.field.nationality', 'Гражданство'],

            ['en', 'account.profile.field.language', 'Language'],
            ['hy', 'account.profile.field.language', 'Լեզու'],
            ['ru', 'account.profile.field.language', 'Язык'],

            ['en', 'account.profile.admin_cta.title', 'Open your admin panel'],
            ['hy', 'account.profile.admin_cta.title', 'Բացել admin վահանակը'],
            ['ru', 'account.profile.admin_cta.title', 'Открыть админ-панель'],

            ['en', 'account.profile.admin_cta.subtitle', 'Manage your inventory, bookings and team on admin.zulu.am'],
            ['hy', 'account.profile.admin_cta.subtitle', 'Կառավարեք ձեր առաջարկները, ամրագրումները և թիմը admin.zulu.am-ում'],
            ['ru', 'account.profile.admin_cta.subtitle', 'Управляйте предложениями, бронированиями и командой на admin.zulu.am'],

            ['en', 'account.stats.bookings', 'My bookings'],
            ['hy', 'account.stats.bookings', 'Իմ ամրագրումները'],
            ['ru', 'account.stats.bookings', 'Мои бронирования'],

            ['en', 'account.stats.saved', 'Saved'],
            ['hy', 'account.stats.saved', 'Պահպանված'],
            ['ru', 'account.stats.saved', 'Сохранённые'],

            ['en', 'account.stats.loyalty', 'Loyalty'],
            ['hy', 'account.stats.loyalty', 'Հավատարմություն'],
            ['ru', 'account.stats.loyalty', 'Лояльность'],

            ['en', 'account.delete.title', 'Delete account'],
            ['hy', 'account.delete.title', 'Ջնջել հաշիվը'],
            ['ru', 'account.delete.title', 'Удалить аккаунт'],

            ['en', 'account.delete.subtitle', 'Account deletion is final, there will be no way to restore your account.'],
            ['hy', 'account.delete.subtitle', 'Հաշվի ջնջումը վերջնական է, վերականգնելու հնարավորություն չի լինի։'],
            ['ru', 'account.delete.subtitle', 'Удаление аккаунта окончательно, восстановить его будет невозможно.'],

            ['en', 'account.delete.button', 'Delete'],
            ['hy', 'account.delete.button', 'Ջնջել'],
            ['ru', 'account.delete.button', 'Удалить'],
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
            } else {
                DB::table('ui_translations')
                    ->where('id', $existing->id)
                    ->update(['value' => $value, 'updated_at' => $now]);
            }
        }

        foreach (['en', 'hy', 'ru'] as $lang) {
            Cache::forget('ui_translations_'.$lang);
        }
    }

    public function down(): void
    {
        $keys = [
            'aria.breadcrumb',
            'account.profile.personal_info',
            'account.profile.edit',
            'account.profile.edit_avatar_aria',
            'account.profile.field.first_name',
            'account.profile.field.last_name',
            'account.profile.field.email',
            'account.profile.field.phone',
            'account.profile.field.birth_date',
            'account.profile.field.nationality',
            'account.profile.field.language',
            'account.profile.admin_cta.title',
            'account.profile.admin_cta.subtitle',
            'account.stats.bookings',
            'account.stats.saved',
            'account.stats.loyalty',
            'account.delete.title',
            'account.delete.subtitle',
            'account.delete.button',
        ];
        DB::table('ui_translations')->whereIn('key', $keys)->delete();
        foreach (['en', 'hy', 'ru'] as $lang) {
            Cache::forget('ui_translations_'.$lang);
        }
    }
};
