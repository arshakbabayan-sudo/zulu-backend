<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phase 2 (GDPR) translation strings: account settings page,
     * deletion-flow copy, data-export copy, and the cookie consent
     * banner. Keys map directly to t() calls in
     * components/account/AccountSettingsClient.tsx and
     * components/CookieConsentBanner.tsx.
     */
    public function up(): void
    {
        $now = now();

        $rows = [
            // [key, en, ru, hy]

            // Account settings page chrome
            ['account.settings.title', 'Account settings', 'Настройки аккаунта', 'Հաշվի կարգավորումներ'],
            ['account.settings.sign_in_required', 'Sign in to manage account settings.', 'Войдите, чтобы управлять настройками аккаунта.', 'Մուտք գործիր՝ հաշվի կարգավորումները կառավարելու համար։'],

            // Data export
            ['account.settings.export_title', 'Download my data', 'Скачать мои данные', 'Իմ տվյալները ներբեռնել'],
            ['account.settings.export_description', 'Get a ZIP archive containing every record we hold about your account — bookings, vouchers, reviews, favorites, login history, and more. We will email you a link valid for 7 days.', 'Получите ZIP-архив со всеми данными вашего аккаунта — бронирования, ваучеры, отзывы, избранное, история входов и многое другое. Ссылка действует 7 дней.', 'Ստացիր ZIP-արխիվ՝ քո հաշվին վերաբերող բոլոր տվյալներով՝ ամրագրումներ, վաուչերներ, կարծիքներ, պահածներ, մուտքերի պատմություն և այլն։ Հղումը գործում է 7 օր։'],
            ['account.settings.export_button', 'Prepare my data export', 'Подготовить экспорт данных', 'Պատրաստել տվյալների արտահանումը'],
            ['account.settings.export_ready', 'Your data is ready. We have emailed you a download link — check your inbox.', 'Ваши данные готовы. Мы отправили вам ссылку для скачивания на email.', 'Քո տվյալները պատրաստ են։ Ներբեռնման հղումը ուղարկել ենք էլ․ փոստով։'],
            ['account.settings.export_generating', 'Your export is being prepared. We will email you when it is ready.', 'Ваш экспорт готовится. Мы сообщим, когда он будет готов.', 'Արտահանումը պատրաստվում է։ Կտեղեկացնենք, երբ պատրաստ լինի։'],
            ['account.settings.export_failed', 'Could not prepare your data right now. Please try again later.', 'Сейчас не удалось подготовить данные. Попробуйте позже.', 'Հիմա չհաջողվեց պատրաստել տվյալները։ Փորձիր մի փոքր ուշ։'],
            ['account.settings.export_expires', 'Link expires:', 'Ссылка истекает:', 'Հղման ժամկետն ավարտվում է՝'],

            // Account deletion
            ['account.settings.delete_title', 'Delete my account', 'Удалить мой аккаунт', 'Հաշիվս ջնջել'],
            ['account.settings.delete_description', 'We will email you a confirmation link. Once you confirm, your account is deactivated immediately and permanently deleted after 30 days. You can log in within that window to cancel.', 'Мы отправим ссылку для подтверждения. После подтверждения аккаунт деактивируется и будет полностью удалён через 30 дней. Вы можете отменить, войдя в течение этого срока.', 'Հաստատման հղում կուղարկենք էլ․ փոստով։ Հաստատելուց հետո հաշիվը անջատվում է անմիջապես և լրիվ ջնջվում 30 օր անց։ 30 օրվա ընթացքում մուտք գործելով կարող ես չեղարկել։'],
            ['account.settings.delete_button', 'Delete my account', 'Удалить аккаунт', 'Հաշիվս ջնջել'],
            ['account.settings.delete_reason_label', 'Reason (optional)', 'Причина (по желанию)', 'Պատճառ (ըստ ցանկության)'],
            ['account.settings.delete_reason_placeholder', 'Help us improve — tell us why you are leaving.', 'Помогите нам стать лучше — расскажите, почему уходите.', 'Օգնիր մեզ բարելավվել՝ ասա ինչու ես հեռանում։'],
            ['account.settings.delete_confirm', 'Send confirmation email', 'Отправить письмо подтверждения', 'Ուղարկել հաստատման email'],
            ['account.settings.deletion_email_sent', 'Check your email — we sent a confirmation link.', 'Проверьте email — мы отправили ссылку для подтверждения.', 'Ստուգիր էլ․ փոստդ՝ հաստատման հղում ուղարկեցինք։'],

            // Cookie consent banner
            ['cookie.banner_aria', 'Cookie preferences', 'Настройки cookie', 'Cookie-ների կարգավորումներ'],
            ['cookie.title', 'We use cookies', 'Мы используем cookie', 'Մենք օգտագործում ենք cookie-ներ'],
            ['cookie.description', 'ZULU uses essential cookies to keep you signed in and remember your preferences (language, currency). We do not run any third-party trackers today — if that changes, we will ask first.', 'ZULU использует только необходимые cookie, чтобы держать вас в системе и помнить ваши настройки (язык, валюта). Сторонние трекеры не используем — если что-то изменится, спросим заранее.', 'ZULU-ն օգտագործում է միայն անհրաժեշտ cookie-ներ՝ որ մուտքագրված մնաս և քո կարգավորումները հիշվեն (լեզու, արժույթ)։ Կողմնակի trackers չենք օգտագործում՝ եթե դա փոխվի, նախ կհարցնենք քեզ։'],
            ['cookie.read_more', 'Privacy policy', 'Политика конфиденциальности', 'Գաղտնիության քաղաքականություն'],
            ['cookie.essential_only', 'Essential only', 'Только необходимые', 'Միայն անհրաժեշտները'],
            ['cookie.accept_all', 'Accept all', 'Принять всё', 'Ընդունել բոլորը'],

            // Settings nav label
            ['account.nav.settings', 'Settings', 'Настройки', 'Կարգավորումներ'],
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
        $prefixes = ['account.settings.', 'cookie.', 'account.nav.settings'];
        foreach ($prefixes as $prefix) {
            DB::table('ui_translations')->where('key', 'like', $prefix.'%')->delete();
        }
        Cache::forget('ui_translations_en');
        Cache::forget('ui_translations_hy');
        Cache::forget('ui_translations_ru');
    }
};
