<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the translation keys consumed by the new admin <ConfirmDialog>
 * primitive (replaces window.confirm() across ~25 admin pages). All keys
 * have en/hy/ru rows so non-English admins finally see localized prompts
 * instead of English browser-native confirm dialogs.
 *
 * Discovered during the 2026-05-20 platform audit (Wave-2). After deploy
 * remember to clear the ui_translations cache.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            // Common buttons (used by every ConfirmDialog)
            ['common.confirm', 'en', 'Confirm'],
            ['common.confirm', 'hy', 'Հաստատել'],
            ['common.confirm', 'ru', 'Подтвердить'],

            ['common.cancel', 'en', 'Cancel'],
            ['common.cancel', 'hy', 'Չեղարկել'],
            ['common.cancel', 'ru', 'Отмена'],

            ['common.confirm_title', 'en', 'Are you sure?'],
            ['common.confirm_title', 'hy', 'Համոզվա՞ծ եք'],
            ['common.confirm_title', 'ru', 'Вы уверены?'],

            // Bucket-3 confirms
            ['admin.bucket3.bulk_notifications.confirm_send', 'en', 'Send broadcast to the matched recipients?'],
            ['admin.bucket3.bulk_notifications.confirm_send', 'hy', 'Ուղարկե՞լ ծանուցումը համապատասխան ստացողներին'],
            ['admin.bucket3.bulk_notifications.confirm_send', 'ru', 'Отправить уведомление выбранным получателям?'],

            ['admin.bucket3.block_dates.confirm_delete', 'en', 'Remove this blocked date range?'],
            ['admin.bucket3.block_dates.confirm_delete', 'hy', 'Ջնջե՞լ այս արգելված ամսաթվերի միջակայքը'],
            ['admin.bucket3.block_dates.confirm_delete', 'ru', 'Удалить этот диапазон заблокированных дат?'],

            ['admin.bucket3.non_service_hours.confirm_approve', 'en', 'Approve this request?'],
            ['admin.bucket3.non_service_hours.confirm_approve', 'hy', 'Հաստատե՞լ այս հարցումը'],
            ['admin.bucket3.non_service_hours.confirm_approve', 'ru', 'Утвердить эту заявку?'],

            ['admin.bucket3.non_service_hours.confirm_reject', 'en', 'Reject this request?'],
            ['admin.bucket3.non_service_hours.confirm_reject', 'hy', 'Մերժե՞լ այս հարցումը'],
            ['admin.bucket3.non_service_hours.confirm_reject', 'ru', 'Отклонить эту заявку?'],

            ['admin.bucket3.pin_settings.confirm_clear', 'en', 'Clear your PIN? You can set a new one later.'],
            ['admin.bucket3.pin_settings.confirm_clear', 'hy', 'Մաքրե՞լ ձեր PIN-ը։ Հետո կարող եք նորը սահմանել։'],
            ['admin.bucket3.pin_settings.confirm_clear', 'ru', 'Очистить PIN? Вы сможете установить новый позже.'],

            ['admin.bucket3.service_catalog.confirm_delete', 'en', 'Remove this catalog item?'],
            ['admin.bucket3.service_catalog.confirm_delete', 'hy', 'Ջնջե՞լ այս ծառայության կատալոգի տարրը'],
            ['admin.bucket3.service_catalog.confirm_delete', 'ru', 'Удалить этот элемент каталога?'],

            ['admin.bucket3.payroll.confirm_move', 'en', 'Move this record to "{status}"?'],
            ['admin.bucket3.payroll.confirm_move', 'hy', 'Տեղափոխե՞լ այս գրառումը «{status}» կարգավիճակին'],
            ['admin.bucket3.payroll.confirm_move', 'ru', 'Перевести запись в статус «{status}»?'],

            ['admin.bucket3.custom_fields.confirm_delete', 'en', 'Remove this custom field definition?'],
            ['admin.bucket3.custom_fields.confirm_delete', 'hy', 'Ջնջե՞լ այս հատուկ դաշտի սահմանումը'],
            ['admin.bucket3.custom_fields.confirm_delete', 'ru', 'Удалить определение этого пользовательского поля?'],

            // Localization
            ['admin.localization.languages.confirm_delete', 'en', 'Delete "{name}" ({code}) language?'],
            ['admin.localization.languages.confirm_delete', 'hy', 'Ջնջե՞լ «{name}» ({code}) լեզուն'],
            ['admin.localization.languages.confirm_delete', 'ru', 'Удалить язык «{name}» ({code})?'],

            // Operator-side
            ['admin.operator.contracts.confirm_sign', 'en', 'Sign this contract? This is a binding action.'],
            ['admin.operator.contracts.confirm_sign', 'hy', 'Ստորագրե՞լ պայմանագիրը։ Սա պարտադիր գործողություն է։'],
            ['admin.operator.contracts.confirm_sign', 'ru', 'Подписать договор? Это юридически обязывающее действие.'],

            ['admin.operator.commission_settings.confirm_remove_override', 'en', 'Remove this per-agent override? They will fall back to the default rate.'],
            ['admin.operator.commission_settings.confirm_remove_override', 'hy', 'Հանե՞լ այս գործակալի անհատական դրույքաչափը։ Կանցնեն լռելյայն դրույքին։'],
            ['admin.operator.commission_settings.confirm_remove_override', 'ru', 'Удалить индивидуальную ставку этого агента? Будет применена ставка по умолчанию.'],

            // Generic submit-for-review (used by hotels, transfers, excursions, visas, cars, flights, packages)
            ['admin.crud.submit_for_review_confirm', 'en', "Submit this item for super-admin review? Once submitted, you cannot edit it until it's approved or rejected."],
            ['admin.crud.submit_for_review_confirm', 'hy', 'Ուղարկե՞լ այս տարրը super-admin-ի հաստատման։ Ուղարկելուց հետո չեք կարող փոփոխել, մինչև հաստատվի կամ մերժվի։'],
            ['admin.crud.submit_for_review_confirm', 'ru', 'Отправить этот элемент на согласование супер-администратору? После отправки редактирование станет недоступно до одобрения или отклонения.'],

            ['admin.crud.flights.delete_confirm', 'en', 'Delete this flight?'],
            ['admin.crud.flights.delete_confirm', 'hy', 'Ջնջե՞լ այս թռիչքը'],
            ['admin.crud.flights.delete_confirm', 'ru', 'Удалить этот рейс?'],

            // Platform contracts
            ['admin.platform_contracts.confirm_send_for_signing', 'en', 'Send this contract to the partner for signing?'],
            ['admin.platform_contracts.confirm_send_for_signing', 'hy', 'Ուղարկե՞լ պայմանագիրը գործընկերոջը՝ ստորագրման համար'],
            ['admin.platform_contracts.confirm_send_for_signing', 'ru', 'Отправить договор партнёру на подпись?'],

            ['admin.platform_contracts.confirm_counter_sign', 'en', "Counter-sign this contract on ZULU's behalf? This is a binding action."],
            ['admin.platform_contracts.confirm_counter_sign', 'hy', 'Ստորագրե՞լ պայմանագիրը ZULU-ի անունից։ Սա պարտադիր գործողություն է։'],
            ['admin.platform_contracts.confirm_counter_sign', 'ru', 'Подписать договор от имени ZULU? Это юридически обязывающее действие.'],
        ];

        $now = now();
        foreach ($rows as [$key, $lang, $value]) {
            DB::table('ui_translations')->updateOrInsert(
                ['key' => $key, 'language_code' => $lang],
                ['value' => $value, 'updated_at' => $now, 'created_at' => $now]
            );
        }
    }

    public function down(): void
    {
        DB::table('ui_translations')
            ->whereIn('key', [
                'common.confirm', 'common.cancel', 'common.confirm_title',
                'admin.bucket3.bulk_notifications.confirm_send',
                'admin.bucket3.block_dates.confirm_delete',
                'admin.bucket3.non_service_hours.confirm_approve',
                'admin.bucket3.non_service_hours.confirm_reject',
                'admin.bucket3.pin_settings.confirm_clear',
                'admin.bucket3.service_catalog.confirm_delete',
                'admin.bucket3.payroll.confirm_move',
                'admin.bucket3.custom_fields.confirm_delete',
                'admin.localization.languages.confirm_delete',
                'admin.operator.contracts.confirm_sign',
                'admin.operator.commission_settings.confirm_remove_override',
                'admin.crud.submit_for_review_confirm',
                'admin.crud.flights.delete_confirm',
                'admin.platform_contracts.confirm_send_for_signing',
                'admin.platform_contracts.confirm_counter_sign',
            ])
            ->delete();
    }
};
