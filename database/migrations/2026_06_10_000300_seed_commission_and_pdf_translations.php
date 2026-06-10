<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Roadmap 10.06 §6 — EN/HY/RU ui_translations for the restyled company-detail
 * Commission tab (admin.commission.* incl. the 3 new base_* labels that were
 * hardcoded English in calculationBaseLabel) + the Finance summary PDF button.
 * Note: err_load/err_save/loading overwrite older shorter wordings from the
 * admin_commission_settings SQL seed — semantically compatible, newer wins.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $rows = [
            ['en', 'admin.commission.actions', 'Actions'],
            ['hy', 'admin.commission.actions', 'Գործողություններ'],
            ['ru', 'admin.commission.actions', 'Действия'],
            ['en', 'admin.commission.add_override', 'Add per-agent override'],
            ['hy', 'admin.commission.add_override', 'Ավելացնել անհատական արժեք'],
            ['ru', 'admin.commission.add_override', 'Добавить индивидуальное значение'],
            ['en', 'admin.commission.add_override_btn', '+ Add override'],
            ['hy', 'admin.commission.add_override_btn', '+ Ավելացնել'],
            ['ru', 'admin.commission.add_override_btn', '+ Добавить'],
            ['en', 'admin.commission.agent', 'Agent'],
            ['hy', 'admin.commission.agent', 'Գործակալ'],
            ['ru', 'admin.commission.agent', 'Агент'],
            ['en', 'admin.commission.agent_company_id', 'Agent company ID'],
            ['hy', 'admin.commission.agent_company_id', 'Գործակալի ընկերության ID'],
            ['ru', 'admin.commission.agent_company_id', 'ID компании агента'],
            ['en', 'admin.commission.agent_id_placeholder', 'e.g. 19'],
            ['hy', 'admin.commission.agent_id_placeholder', 'օր.՝ 19'],
            ['ru', 'admin.commission.agent_id_placeholder', 'напр. 19'],
            ['en', 'admin.commission.base_custom', 'Custom % of gross'],
            ['hy', 'admin.commission.base_custom', 'Հատուկ %՝ համախառն գումարից'],
            ['ru', 'admin.commission.base_custom', 'Произвольный % от валовой суммы'],
            ['en', 'admin.commission.base_gross', 'Gross booking amount'],
            ['hy', 'admin.commission.base_gross', 'Ամրագրման համախառն գումար'],
            ['ru', 'admin.commission.base_gross', 'Валовая сумма бронирования'],
            ['en', 'admin.commission.base_post_platform_fee', "Post platform fee (operator's net)"],
            ['hy', 'admin.commission.base_post_platform_fee', 'Հարթակի վճարից հետո (օպերատորի զուտ գումար)'],
            ['ru', 'admin.commission.base_post_platform_fee', 'После комиссии платформы (нетто оператора)'],
            ['en', 'admin.commission.calculation_base', 'Calculation base'],
            ['hy', 'admin.commission.calculation_base', 'Հաշվարկի հիմք'],
            ['ru', 'admin.commission.calculation_base', 'База расчёта'],
            ['en', 'admin.commission.confirm_delete_override', 'Delete override for "{name}"?'],
            ['hy', 'admin.commission.confirm_delete_override', 'Ջնջե՞լ «{name}»-ի անհատական արժեքը'],
            ['ru', 'admin.commission.confirm_delete_override', 'Удалить индивидуальное значение для «{name}»?'],
            ['en', 'admin.commission.custom_base', 'Custom base percentage (%)'],
            ['hy', 'admin.commission.custom_base', 'Հատուկ հիմքի տոկոս (%)'],
            ['ru', 'admin.commission.custom_base', 'Процент собственной базы (%)'],
            ['en', 'admin.commission.default_hint', 'Applied to every agent unless an override row below exists for that agent.'],
            ['hy', 'admin.commission.default_hint', 'Կիրառվում է բոլոր գործակալների համար, եթե ստորև տվյալ գործակալի համար առանձին տող չկա։'],
            ['ru', 'admin.commission.default_hint', 'Применяется ко всем агентам, если ниже нет отдельной строки для этого агента.'],
            ['en', 'admin.commission.default_percentage', 'Percentage (%)'],
            ['hy', 'admin.commission.default_percentage', 'Տոկոս (%)'],
            ['ru', 'admin.commission.default_percentage', 'Процент (%)'],
            ['en', 'admin.commission.default_title', 'Default commission for all agents'],
            ['hy', 'admin.commission.default_title', 'Լռելյայն միջնորդավճար բոլոր գործակալների համար'],
            ['ru', 'admin.commission.default_title', 'Комиссия по умолчанию для всех агентов'],
            ['en', 'admin.commission.delete', 'Delete'],
            ['hy', 'admin.commission.delete', 'Ջնջել'],
            ['ru', 'admin.commission.delete', 'Удалить'],
            ['en', 'admin.commission.err_agent_id', 'Please enter a valid agent company ID'],
            ['hy', 'admin.commission.err_agent_id', 'Մուտքագրիր գործակալի ընկերության վավեր ID'],
            ['ru', 'admin.commission.err_agent_id', 'Введите корректный ID компании агента'],
            ['en', 'admin.commission.err_load', 'Failed to load commission settings'],
            ['hy', 'admin.commission.err_load', 'Չհաջողվեց բեռնել միջնորդավճարի կարգավորումները'],
            ['ru', 'admin.commission.err_load', 'Не удалось загрузить настройки комиссии'],
            ['en', 'admin.commission.err_save', 'Failed to save commission settings'],
            ['hy', 'admin.commission.err_save', 'Չհաջողվեց պահպանել միջնորդավճարի կարգավորումները'],
            ['ru', 'admin.commission.err_save', 'Не удалось сохранить настройки комиссии'],
            ['en', 'admin.commission.loading', 'Loading commission settings…'],
            ['hy', 'admin.commission.loading', 'Բեռնվում են միջնորդավճարի կարգավորումները…'],
            ['ru', 'admin.commission.loading', 'Загрузка настроек комиссии…'],
            ['en', 'admin.commission.no_overrides', 'No per-agent overrides yet.'],
            ['hy', 'admin.commission.no_overrides', 'Անհատական արժեքներ դեռ չկան։'],
            ['ru', 'admin.commission.no_overrides', 'Индивидуальных значений пока нет.'],
            ['en', 'admin.commission.notes', 'Notes (optional)'],
            ['hy', 'admin.commission.notes', 'Նշումներ (ոչ պարտադիր)'],
            ['ru', 'admin.commission.notes', 'Заметки (необязательно)'],
            ['en', 'admin.commission.notes_placeholder', 'Internal note about how this default was decided…'],
            ['hy', 'admin.commission.notes_placeholder', 'Ներքին նշում՝ ինչպես է ընտրվել այս լռելյայն արժեքը…'],
            ['ru', 'admin.commission.notes_placeholder', 'Внутренняя заметка о том, как выбрано это значение по умолчанию…'],
            ['en', 'admin.commission.overrides_hint', 'Override the default for specific agents — used when a partner has negotiated a different rate.'],
            ['hy', 'admin.commission.overrides_hint', 'Փոխարինիր լռելյայն արժեքը կոնկրետ գործակալների համար — երբ գործընկերոջ հետ այլ տոկոս է համաձայնեցված։'],
            ['ru', 'admin.commission.overrides_hint', 'Переопределите значение по умолчанию для конкретных агентов — когда с партнёром согласована другая ставка.'],
            ['en', 'admin.commission.overrides_title', 'Per-agent overrides'],
            ['hy', 'admin.commission.overrides_title', 'Անհատական արժեքներ ըստ գործակալների'],
            ['ru', 'admin.commission.overrides_title', 'Индивидуальные значения для агентов'],
            ['en', 'admin.commission.save_default', 'Save default'],
            ['hy', 'admin.commission.save_default', 'Պահպանել'],
            ['ru', 'admin.commission.save_default', 'Сохранить'],
            ['en', 'admin.commission.saved_just_now', 'Saved just now'],
            ['hy', 'admin.commission.saved_just_now', 'Հենց նոր պահպանվեց'],
            ['ru', 'admin.commission.saved_just_now', 'Только что сохранено'],
            ['en', 'admin.finance_summary.btn_pdf', 'PDF'],
            ['hy', 'admin.finance_summary.btn_pdf', 'PDF'],
            ['ru', 'admin.finance_summary.btn_pdf', 'PDF'],
        ];

        $batch = [];
        foreach ($rows as [$lang, $key, $value]) {
            $batch[] = ['language_code' => $lang, 'key' => $key, 'value' => $value, 'created_at' => $now, 'updated_at' => $now];
        }

        foreach (array_chunk($batch, 100) as $chunk) {
            DB::table('ui_translations')->upsert(
                $chunk,
                ['language_code', 'key'],
                ['value', 'updated_at']
            );
        }

        foreach (['en', 'hy', 'ru'] as $lang) {
            Cache::forget("ui_translations_{$lang}");
        }
    }

    public function down(): void
    {
        // Translations may be refined in the admin UI afterwards — keep.
    }
};
