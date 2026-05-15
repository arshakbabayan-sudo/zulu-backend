<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Phase 13.6 batch 3 — ui_translations for company-applications pages
 * (list + detail), plus three keys that were used in code but had
 * never been seeded (caught by the diff between t() call sites and
 * existing ui_translations rows).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $rows = [
            // ── Truly missing keys (used in code, no DB row at all) ──
            ['common.see_all', 'See all', 'Показать все', 'Տեսնել բոլորը'],
            ['common.loading', 'Loading…', 'Загрузка…', 'Բեռնվում է…'],
            ['admin.notifications.empty', 'No notifications.', 'Уведомлений нет.', 'Ծանուցումներ չկան։'],
            ['admin.shell.login', 'Log in', 'Войти', 'Մուտք'],

            // ── /platform/company-applications (list) ────────────────
            ['admin.company_applications.title', 'Company applications', 'Заявки компаний', 'Ընկերությունների դիմումներ'],
            ['admin.company_applications.col_id', 'ID', 'ID', 'ID'],
            ['admin.company_applications.col_company', 'Company', 'Компания', 'Ընկերություն'],
            ['admin.company_applications.col_email', 'Email', 'Email', 'Email'],
            ['admin.company_applications.col_status', 'Status', 'Статус', 'Կարգավիճակ'],
            ['admin.company_applications.col_submitted', 'Submitted', 'Отправлено', 'Ուղարկված է'],
            ['admin.company_applications.btn_open', 'Open', 'Открыть', 'Բացել'],
            ['admin.company_applications.err_load', 'Failed to load', 'Не удалось загрузить', 'Չհաջողվեց բեռնել'],

            // ── /platform/company-applications/[id] (detail) ─────────
            ['admin.company_application_detail.title', 'Company application', 'Заявка компании', 'Ընկերության դիմում'],
            ['admin.company_application_detail.application_number', 'Application #{id}', 'Заявка #{id}', 'Դիմում #{id}'],
            ['admin.company_application_detail.back_to_list', 'Back to list', 'Назад к списку', 'Վերադառնալ ցանկին'],
            ['admin.company_application_detail.err_not_found', 'Application not found.', 'Заявка не найдена.', 'Դիմումը գտնված չէ։'],
            ['admin.company_application_detail.confirm_approve', 'Approve this application? A company and company_admin user will be created.', 'Одобрить заявку? Будут созданы компания и пользователь company_admin.', 'Հաստատե՞լ դիմումը։ Կստեղծվեն ընկերությունը և company_admin օգտատերը։'],
            ['admin.company_application_detail.prompt_rejection_reason', 'Rejection reason (required)', 'Причина отклонения (обязательно)', 'Մերժման պատճառ (պարտադիր)'],
            ['admin.company_application_detail.err_rejection_reason_required', 'Rejection reason is required by the API.', 'API требует указать причину отклонения.', 'API-ն պարտադրում է նշել մերժման պատճառ։'],
            ['admin.company_application_detail.label_status', 'Status', 'Статус', 'Կարգավիճակ'],
            ['admin.company_application_detail.label_company_name', 'Company name', 'Название компании', 'Ընկերության անվանում'],
            ['admin.company_application_detail.label_business_email', 'Business email', 'Бизнес-email', 'Բիզնես-email'],
            ['admin.company_application_detail.label_contact', 'Contact', 'Контакт', 'Կոնտակտ'],
            ['admin.company_application_detail.label_legal_address', 'Legal address', 'Юридический адрес', 'Իրավաբանական հասցե'],
            ['admin.company_application_detail.label_actual_address', 'Actual address', 'Фактический адрес', 'Փաստացի հասցե'],
            ['admin.company_application_detail.label_country_city', 'Country / city', 'Страна / город', 'Երկիր / քաղաք'],
            ['admin.company_application_detail.label_phone_tax', 'Phone / tax ID', 'Телефон / налоговый ID', 'Հեռախոս / հարկային ID'],
            ['admin.company_application_detail.label_reviewed', 'Reviewed', 'Проверено', 'Ստուգված է'],
            ['admin.company_application_detail.label_rejection_reason', 'Rejection reason', 'Причина отклонения', 'Մերժման պատճառ'],
            ['admin.company_application_detail.label_notes', 'Notes', 'Заметки', 'Նշումներ'],
            ['admin.company_application_detail.label_documents', 'Documents on disk', 'Документы на диске', 'Փաստաթղթեր սկավառակի վրա'],
            ['admin.company_application_detail.label_state_cert', 'State cert', 'Гос. сертификат', 'Պետական վկայական'],
            ['admin.company_application_detail.label_license', 'License', 'Лицензия', 'Լիցենզիա'],
        ];

        $batch = [];
        foreach ($rows as $r) {
            [$key, $en, $ru, $hy] = $r;
            $batch[] = ['language_code' => 'en', 'key' => $key, 'value' => $en, 'created_at' => $now, 'updated_at' => $now];
            $batch[] = ['language_code' => 'ru', 'key' => $key, 'value' => $ru, 'created_at' => $now, 'updated_at' => $now];
            $batch[] = ['language_code' => 'hy', 'key' => $key, 'value' => $hy, 'created_at' => $now, 'updated_at' => $now];
        }

        foreach (array_chunk($batch, 200) as $chunk) {
            DB::table('ui_translations')->upsert(
                $chunk,
                ['language_code', 'key'],
                ['value', 'updated_at']
            );
        }

        foreach (['en', 'ru', 'hy'] as $lang) {
            Cache::forget('ui_translations_'.$lang);
        }
    }

    public function down(): void
    {
        DB::table('ui_translations')
            ->where(function ($q): void {
                $q->where('key', 'like', 'admin.company_applications.%')
                    ->orWhere('key', 'like', 'admin.company_application_detail.%')
                    ->orWhere('key', '=', 'common.see_all')
                    ->orWhere('key', '=', 'common.loading')
                    ->orWhere('key', '=', 'admin.notifications.empty')
                    ->orWhere('key', '=', 'admin.shell.login');
            })
            ->delete();

        foreach (['en', 'ru', 'hy'] as $lang) {
            Cache::forget('ui_translations_'.$lang);
        }
    }
};
