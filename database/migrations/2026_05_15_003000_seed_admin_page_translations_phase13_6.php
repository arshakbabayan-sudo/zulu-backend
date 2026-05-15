<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Phase 13.6 batch 1 — ui_translations seed for 4 admin pages whose
 * hardcoded English literals just got wrapped in t() calls:
 *
 *   - /platform/pending-review
 *   - /platform/approvals
 *   - /inventory/hotels       (filter bar + table headers)
 *   - /localization/translations  (Content translations editor)
 *
 * Follow-up migrations will cover the remaining 68 admin tsx files.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // [key, en, ru, hy]
        $rows = [
            // ── shared additions ──────────────────────────────────────
            ['common.delete', 'Delete', 'Удалить', 'Ջնջել'],

            // ── module_type labels (shared) ───────────────────────────
            ['admin.module_type.hotel', 'Hotel', 'Отель', 'Հյուրանոց'],
            ['admin.module_type.car', 'Car rental', 'Аренда авто', 'Մեքենայի վարձ'],
            ['admin.module_type.transfer', 'Transfer', 'Трансфер', 'Տրանսֆեր'],
            ['admin.module_type.excursion', 'Excursion', 'Экскурсия', 'Էքսկուրսիա'],
            ['admin.module_type.flight', 'Flight', 'Перелёт', 'Թռիչք'],
            ['admin.module_type.package', 'Package', 'Тур-пакет', 'Փաթեթ'],
            ['admin.module_type.visa', 'Visa', 'Виза', 'Վիզա'],

            // ── /platform/pending-review ──────────────────────────────
            ['admin.pending_review.title', 'Pending Review', 'Ожидают проверки', 'Սպասում են ստուգման'],
            ['admin.pending_review.subtitle', 'Operator-submitted offers awaiting super-admin approval. Once approved, they appear on the customer-facing site.', 'Предложения операторов, ожидающие одобрения супер-администратора. После одобрения они появляются на клиентском сайте.', 'Օպերատորների ուղարկած առաջարկները, որոնք սպասում են super-admin-ի հաստատման։ Հաստատվելուց հետո հայտնվում են հաճախորդների կայքում։'],
            ['admin.pending_review.filter_type', 'Type', 'Тип', 'Տեսակ'],
            ['admin.pending_review.filter_all_types', 'All types', 'Все типы', 'Բոլոր տեսակները'],
            ['admin.pending_review.filter_search_title', 'Search title', 'Поиск по названию', 'Որոնում անունով'],
            ['admin.pending_review.placeholder_title', 'title…', 'название…', 'անուն…'],
            ['admin.pending_review.btn_search', 'Search', 'Найти', 'Որոնել'],
            ['admin.pending_review.col_id', 'ID', 'ID', 'ID'],
            ['admin.pending_review.col_type', 'Type', 'Тип', 'Տեսակ'],
            ['admin.pending_review.col_title', 'Title', 'Название', 'Անուն'],
            ['admin.pending_review.col_operator', 'Operator', 'Оператор', 'Օպերատոր'],
            ['admin.pending_review.col_country', 'Country', 'Страна', 'Երկիր'],
            ['admin.pending_review.col_submitted', 'Submitted', 'Отправлено', 'Ուղարկված է'],
            ['admin.pending_review.col_actions', 'Actions', 'Действия', 'Գործողություններ'],
            ['admin.pending_review.empty', 'No offers waiting for review. 🎉', 'Нет предложений на проверке. 🎉', 'Ստուգման սպասող առաջարկ չկա։ 🎉'],
            ['admin.pending_review.btn_approve', 'Approve', 'Одобрить', 'Հաստատել'],
            ['admin.pending_review.btn_reject', 'Reject', 'Отклонить', 'Մերժել'],
            ['admin.pending_review.modal_title', 'Reject offer', 'Отклонить предложение', 'Մերժել առաջարկը'],
            ['admin.pending_review.modal_reason', 'Reason', 'Причина', 'Պատճառ'],
            ['admin.pending_review.modal_reason_placeholder', 'Explain why this is being rejected so the operator can fix it…', 'Объясните, почему предложение отклоняется, чтобы оператор мог исправить…', 'Բացատրեք, ինչու եք մերժում, որպեսզի օպերատորը կարողանա ուղղել…'],
            ['admin.pending_review.modal_submit_reject', 'Reject offer', 'Отклонить предложение', 'Մերժել առաջարկը'],
            ['admin.pending_review.err_load', 'Failed to load review queue.', 'Не удалось загрузить очередь на проверку.', 'Ստուգման հերթը չհաջողվեց բեռնել։'],
            ['admin.pending_review.err_approve', 'Approve failed.', 'Не удалось одобрить.', 'Հաստատումը չհաջողվեց։'],
            ['admin.pending_review.err_reject', 'Reject failed.', 'Не удалось отклонить.', 'Մերժումը չհաջողվեց։'],
            ['admin.pending_review.err_reject_min', 'Please provide a reason (minimum 3 characters).', 'Укажите причину (минимум 3 символа).', 'Նշեք պատճառը (նվազագույնը 3 նիշ)։'],

            // ── /platform/approvals ───────────────────────────────────
            ['admin.approvals.title', 'Generic approvals', 'Общие одобрения', 'Ընդհանուր հաստատումներ'],
            ['admin.approvals.title_short', 'Approvals', 'Одобрения', 'Հաստատումներ'],
            ['admin.approvals.filter_status', 'Status', 'Статус', 'Կարգավիճակ'],
            ['admin.approvals.status_any', 'Any', 'Любой', 'Ցանկացած'],
            ['admin.approvals.status_pending', 'pending', 'на рассмотрении', 'սպասում է'],
            ['admin.approvals.status_under_review', 'under_review', 'на проверке', 'ստուգման ընթացքում'],
            ['admin.approvals.status_approved', 'approved', 'одобрено', 'հաստատված'],
            ['admin.approvals.status_rejected', 'rejected', 'отклонено', 'մերժված'],
            ['admin.approvals.filter_entity_type', 'Entity type', 'Тип сущности', 'Օբյեկտի տեսակ'],
            ['admin.approvals.placeholder_entity_type', 'Filter by entity_type', 'Фильтр по entity_type', 'Զտում ըստ entity_type-ի'],
            ['admin.approvals.btn_apply_filter', 'Apply entity filter', 'Применить фильтр', 'Կիրառել զտիչը'],
            ['admin.approvals.col_id', 'ID', 'ID', 'ID'],
            ['admin.approvals.col_entity', 'Entity', 'Сущность', 'Օբյեկտ'],
            ['admin.approvals.col_status', 'Status', 'Статус', 'Կարգավիճակ'],
            ['admin.approvals.col_priority', 'Priority', 'Приоритет', 'Առաջնահերթություն'],
            ['admin.approvals.col_requested_by', 'Requested by', 'Запросил(а)', 'Հարցումը կատարել է'],
            ['admin.approvals.col_created', 'Created', 'Создано', 'Ստեղծված է'],
            ['admin.approvals.col_actions', 'Actions', 'Действия', 'Գործողություններ'],
            ['admin.approvals.btn_approve', 'Approve', 'Одобрить', 'Հաստատել'],
            ['admin.approvals.btn_reject', 'Reject', 'Отклонить', 'Մերժել'],
            ['admin.approvals.prompt_notes_approve', 'Optional decision notes', 'Необязательные заметки решения', 'Որոշման լրացուցիչ նշումներ (ոչ պարտադիր)'],
            ['admin.approvals.prompt_notes_reject', 'Optional decision notes (rejection)', 'Необязательные заметки об отклонении', 'Մերժման լրացուցիչ նշումներ (ոչ պարտադիր)'],
            ['admin.approvals.err_load', 'Failed to load', 'Не удалось загрузить', 'Չհաջողվեց բեռնել'],
            ['admin.approvals.err_approve', 'Approve failed', 'Не удалось одобрить', 'Հաստատումը չհաջողվեց'],
            ['admin.approvals.err_reject', 'Reject failed', 'Не удалось отклонить', 'Մերժումը չհաջողվեց'],

            // ── /inventory/hotels ─────────────────────────────────────
            ['admin.inventory_hotels.title', 'Hotels inventory', 'Каталог отелей', 'Հյուրանոցների ցանկ'],
            ['admin.inventory_hotels.col_id', 'ID', 'ID', 'ID'],
            ['admin.inventory_hotels.col_company_id', 'Company ID', 'ID компании', 'Ընկերության ID'],
            ['admin.inventory_hotels.col_hotel', 'Hotel', 'Отель', 'Հյուրանոց'],
            ['admin.inventory_hotels.col_city', 'City', 'Город', 'Քաղաք'],
            ['admin.inventory_hotels.col_country', 'Country', 'Страна', 'Երկիր'],
            ['admin.inventory_hotels.col_lifecycle', 'Lifecycle status', 'Статус жизненного цикла', 'Կարգավիճակ'],
            ['admin.inventory_hotels.col_from', 'From', 'От', 'Սկսած'],
            ['admin.inventory_hotels.col_offer', 'Offer', 'Предложение', 'Առաջարկ'],
            ['admin.inventory_hotels.filter_advanced_phase', 'Advanced phase', 'Доп. фаза', 'Ընդլայնված փուլ'],
            ['admin.inventory_hotels.filter_company_id', 'Company ID', 'ID компании', 'Ընկերության ID'],
            ['admin.inventory_hotels.filter_country', 'Country', 'Страна', 'Երկիր'],
            ['admin.inventory_hotels.filter_city', 'City', 'Город', 'Քաղաք'],
            ['admin.inventory_hotels.filter_lifecycle', 'Lifecycle status', 'Статус жизненного цикла', 'Կարգավիճակ'],
            ['admin.inventory_hotels.filter_availability', 'Availability', 'Доступность', 'Հասանելիություն'],
            ['admin.inventory_hotels.filter_package_eligible', 'Package eligible', 'Подходит для пакета', 'Փաթեթի համար ընտրելի'],
            ['admin.inventory_hotels.filter_free_cancellation', 'Free cancellation', 'Бесплатная отмена', 'Անվճար չեղարկում'],
            ['admin.inventory_hotels.filter_room_type', 'Room type', 'Тип номера', 'Սենյակի տեսակ'],
            ['admin.inventory_hotels.filter_invoice_id', 'Invoice id', 'ID счёта', 'Հաշիվի ID'],
            ['admin.inventory_hotels.filter_date', 'Date', 'Дата', 'Ամսաթիվ'],
            ['admin.inventory_hotels.filter_user_email', 'User email', 'Email пользователя', 'Օգտատիրոջ Email'],
            ['admin.inventory_hotels.filter_min_price', 'Min price', 'Мин. цена', 'Նվազ. գին'],
            ['admin.inventory_hotels.filter_max_price', 'Max price', 'Макс. цена', 'Առավ. գին'],
            ['admin.inventory_hotels.placeholder_room_type', 'e.g. double', 'напр. double', 'օր. double'],
            ['admin.inventory_hotels.placeholder_from', 'from', 'от', 'սկսած'],
            ['admin.inventory_hotels.placeholder_to', 'to', 'до', 'մինչև'],
            ['admin.inventory_hotels.opt_any', 'Any', 'Любой', 'Ցանկացած'],
            ['admin.inventory_hotels.opt_yes', 'Yes', 'Да', 'Այո'],
            ['admin.inventory_hotels.opt_no', 'No', 'Нет', 'Ոչ'],
            ['admin.inventory_hotels.btn_apply', 'Apply filters', 'Применить', 'Կիրառել'],
            ['admin.inventory_hotels.btn_clear', 'Clear', 'Очистить', 'Մաքրել'],

            // ── /localization/translations (Content translations) ─────
            ['admin.content_translations.title', 'Content translations', 'Переводы контента', 'Բովանդակային թարգմանություններ'],
            ['admin.content_translations.title_short', 'Translations', 'Переводы', 'Թարգմանություններ'],
            ['admin.content_translations.entity_type', 'Entity type', 'Тип сущности', 'Օբյեկտի տեսակ'],
            ['admin.content_translations.entity_id', 'Entity id', 'ID сущности', 'Օբյեկտի ID'],
            ['admin.content_translations.language', 'Language', 'Язык', 'Լեզու'],
            ['admin.content_translations.btn_load', 'Load', 'Загрузить', 'Բեռնել'],
            ['admin.content_translations.editing_prefix', 'Editing', 'Редактируется', 'Խմբագրվում է'],
            ['admin.content_translations.delete_section_title', 'Delete translations (super admin)', 'Удалить переводы (супер-админ)', 'Ջնջել թարգմանությունները (սուպեր-ադմին)'],
            ['admin.content_translations.delete_section_hint', 'Optional language code - leave empty to delete all languages for this entity.', 'Необязательный код языка — оставьте пустым, чтобы удалить все языки для этой сущности.', 'Լեզվի կոդը ոչ պարտադիր է — դատարկ թողեք՝ բոլոր լեզուները ջնջելու համար։'],
            ['admin.content_translations.delete_lang_placeholder', 'e.g. ru (optional)', 'напр. ru (необяз.)', 'օր. ru (ոչ պարտադիր)'],
            ['admin.content_translations.msg_loaded', 'Loaded.', 'Загружено.', 'Բեռնված է։'],
            ['admin.content_translations.msg_saved', 'Saved.', 'Сохранено.', 'Պահպանված է։'],
            ['admin.content_translations.msg_deleted', 'Deleted.', 'Удалено.', 'Ջնջված է։'],
            ['admin.content_translations.err_invalid_id', 'Enter a valid entity id.', 'Введите корректный ID сущности.', 'Մուտքագրեք օբյեկտի ճիշտ ID-ն։'],
            ['admin.content_translations.err_load', 'Load failed', 'Не удалось загрузить', 'Չհաջողվեց բեռնել'],
            ['admin.content_translations.err_save', 'Save failed', 'Не удалось сохранить', 'Չհաջողվեց պահպանել'],
            ['admin.content_translations.err_delete', 'Delete failed', 'Не удалось удалить', 'Չհաջողվեց ջնջել'],
            ['admin.content_translations.err_empty_fields', 'Add at least one non-empty field before saving.', 'Заполните хотя бы одно поле перед сохранением.', 'Պահպանելուց առաջ լրացրեք առնվազն մեկ դաշտ։'],
            ['admin.content_translations.confirm_delete', 'Delete translations for this entity?', 'Удалить переводы для этой сущности?', 'Ջնջե՞լ այս օբյեկտի թարգմանությունները։'],
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
        // Match-by-prefix delete; safer than enumerating every key.
        DB::table('ui_translations')
            ->where(function ($q): void {
                $q->where('key', 'like', 'admin.pending_review.%')
                    ->orWhere('key', 'like', 'admin.approvals.%')
                    ->orWhere('key', 'like', 'admin.inventory_hotels.%')
                    ->orWhere('key', 'like', 'admin.content_translations.%')
                    ->orWhere('key', 'like', 'admin.module_type.%')
                    ->orWhere('key', '=', 'common.delete');
            })
            ->delete();

        foreach (['en', 'ru', 'hy'] as $lang) {
            Cache::forget('ui_translations_'.$lang);
        }
    }
};
