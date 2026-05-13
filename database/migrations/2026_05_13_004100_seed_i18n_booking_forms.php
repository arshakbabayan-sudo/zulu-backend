<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Sprint 4 — second seed pass for booking-flow labels + placeholders
     * (hotels / cars / excursions / flights / common booking clients).
     */
    public function up(): void
    {
        $now = now();

        // [key, en, ru, hy]
        $rows = [
            // ── form.label.* (TextField + SelectField labels in booking forms) ──
            ['form.label.first_name', 'First name', 'Имя', 'Անուն'],
            ['form.label.last_name', 'Last name', 'Фамилия', 'Ազգանուն'],
            ['form.label.middle_name', 'Middle name', 'Отчество', 'Հայրանուն'],
            ['form.label.email', 'Email', 'Эл. почта', 'Էլ. փոստ'],
            ['form.label.email_address', 'Email address', 'Электронная почта', 'Էլ. փոստի հասցե'],
            ['form.label.phone', 'Phone', 'Телефон', 'Հեռախոս'],
            ['form.label.phone_number', 'Phone number', 'Номер телефона', 'Հեռախոսահամար'],
            ['form.label.country', 'Country', 'Страна', 'Երկիր'],
            ['form.label.nationality', 'Nationality', 'Гражданство', 'Քաղաքացիություն'],
            ['form.label.issued_by', 'Issued by', 'Кем выдан', 'Տրման մարմին'],
            ['form.label.date_of_birth', 'Date of birth', 'Дата рождения', 'Ծննդյան ամսաթիվ'],
            ['form.label.gender', 'Gender', 'Пол', 'Սեռ'],
            ['form.label.first_given_name', 'First given name', 'Имя (по паспорту)', 'Անձնագրային անուն'],
            ['form.label.driver_license_number', 'Driver license number', 'Номер водительского удостоверения', 'Վարորդական իրավունքի համար'],
            ['form.label.license_expiry', 'License expiry', 'Срок действия прав', 'Իրավունքի վավերականության ժամկետ'],
            ['form.label.document_type', 'Document type', 'Тип документа', 'Փաստաթղթի տեսակ'],
            ['form.label.document_number', 'Document number', 'Номер документа', 'Փաստաթղթի համար'],
            ['form.label.type_of_meal', 'Type of meal (optional)', 'Тип питания (опционально)', 'Սննդի տեսակ (ընտրովի)'],
            ['form.label.cardholder_name', 'Cardholder name', 'Имя владельца карты', 'Քարտապանի անուն'],
            ['form.label.cardholders_name', 'Cardholder\'s name', 'Имя владельца карты', 'Քարտապանի անուն'],
            ['form.label.card_number', 'Card number', 'Номер карты', 'Քարտի համար'],
            ['form.label.expiry_date', 'Expiry date', 'Срок действия', 'Վավերականության ժամկետ'],
            ['form.label.cvv', 'CVV', 'CVV', 'CVV'],
            ['form.label.number_of_participants', 'Number of participants', 'Количество участников', 'Մասնակիցների քանակ'],

            // ── form.placeholder.* (when distinct from label) ──
            ['form.placeholder.middle_name_optional', 'Middle name (optional)', 'Отчество (опционально)', 'Հայրանուն (ընտրովի)'],
            ['form.placeholder.email_example', 'email@example.com', 'email@example.com', 'email@example.com'],
            ['form.placeholder.phone_example', '+374 60 400777', '+374 60 400777', '+374 60 400777'],
            ['form.placeholder.phone_example_us', '+1 213 373 4253', '+1 213 373 4253', '+1 213 373 4253'],
            ['form.placeholder.card_number', '0000 0000 0000 0000', '0000 0000 0000 0000', '0000 0000 0000 0000'],
            ['form.placeholder.expiry_mmyy', 'MM / YY', 'ММ / ГГ', 'ԱԱ / ՏՏ'],
            ['form.placeholder.cvv_zeros', '000', '000', '000'],
            ['form.placeholder.doc_number_example', 'AB1234567', 'AB1234567', 'AB1234567'],
            ['form.placeholder.single_one', '1', '1', '1'],
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
            ->where(function ($q) {
                $q->where('key', 'like', 'form.label.%')
                    ->orWhere('key', 'like', 'form.placeholder.%');
            })
            ->delete();
        Cache::forget('ui_translations_en');
        Cache::forget('ui_translations_hy');
        Cache::forget('ui_translations_ru');
    }
};
