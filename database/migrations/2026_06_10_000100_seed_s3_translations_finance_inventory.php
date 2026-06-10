<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Roadmap 10.06 §3 (translations) — EN/HY/RU ui_translations rows for the
 * surfaces translated in zulu-admin-next commit "§3 translations":
 *   - /platform/finance-summary (admin.finance_summary.* — 40 keys)
 *   - Inventory/Bookings page subtitles (admin.inventory.*.subtitle,
 *     admin.bookings.subtitle, admin.package_orders.subtitle,
 *     admin.inventory.packages_oversight.subtitle, admin.statistics.subtitle)
 * Tab-strip labels reuse the already-seeded admin.nav.tab.* rows — not here.
 *
 * Also backfills HY/RU for two pre-existing EN-only finance keys the page
 * reuses (title_short, btn_refresh) via insertOrIgnore so any value already
 * produced by the AI translation scan is never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $rows = [
            ['en', 'admin.bookings.subtitle', 'All bookings across the platform with status tracking.'],
            ['hy', 'admin.bookings.subtitle', 'Հարթակի բոլոր ամրագրումները՝ կարգավիճակի հետևմամբ։'],
            ['ru', 'admin.bookings.subtitle', 'Все бронирования платформы с отслеживанием статусов.'],
            ['en', 'admin.finance_summary.btn_export', 'Export'],
            ['hy', 'admin.finance_summary.btn_export', 'Արտահանել'],
            ['ru', 'admin.finance_summary.btn_export', 'Экспорт'],
            ['en', 'admin.finance_summary.card_collection_rate', 'Collection rate'],
            ['hy', 'admin.finance_summary.card_collection_rate', 'Գանձումների մակարդակ'],
            ['ru', 'admin.finance_summary.card_collection_rate', 'Собираемость платежей'],
            ['en', 'admin.finance_summary.card_payment_methods', 'Payment methods'],
            ['hy', 'admin.finance_summary.card_payment_methods', 'Վճարման եղանակներ'],
            ['ru', 'admin.finance_summary.card_payment_methods', 'Способы оплаты'],
            ['en', 'admin.finance_summary.card_quick_actions', 'Quick actions'],
            ['hy', 'admin.finance_summary.card_quick_actions', 'Արագ գործողություններ'],
            ['ru', 'admin.finance_summary.card_quick_actions', 'Быстрые действия'],
            ['en', 'admin.finance_summary.card_recent_transactions', 'Recent transactions'],
            ['hy', 'admin.finance_summary.card_recent_transactions', 'Վերջին գործարքները'],
            ['ru', 'admin.finance_summary.card_recent_transactions', 'Последние транзакции'],
            ['en', 'admin.finance_summary.card_revenue_by_service', 'Revenue by service'],
            ['hy', 'admin.finance_summary.card_revenue_by_service', 'Եկամուտն ըստ ծառայության'],
            ['ru', 'admin.finance_summary.card_revenue_by_service', 'Выручка по услугам'],
            ['en', 'admin.finance_summary.col_amount', 'Amount'],
            ['hy', 'admin.finance_summary.col_amount', 'Գումար'],
            ['ru', 'admin.finance_summary.col_amount', 'Сумма'],
            ['en', 'admin.finance_summary.col_company', 'Company'],
            ['hy', 'admin.finance_summary.col_company', 'Ընկերություն'],
            ['ru', 'admin.finance_summary.col_company', 'Компания'],
            ['en', 'admin.finance_summary.col_id', 'ID'],
            ['hy', 'admin.finance_summary.col_id', 'ID'],
            ['ru', 'admin.finance_summary.col_id', 'ID'],
            ['en', 'admin.finance_summary.col_status', 'Status'],
            ['hy', 'admin.finance_summary.col_status', 'Կարգավիճակ'],
            ['ru', 'admin.finance_summary.col_status', 'Статус'],
            ['en', 'admin.finance_summary.col_type', 'Type'],
            ['hy', 'admin.finance_summary.col_type', 'Տեսակ'],
            ['ru', 'admin.finance_summary.col_type', 'Тип'],
            ['en', 'admin.finance_summary.col_when', 'When'],
            ['hy', 'admin.finance_summary.col_when', 'Երբ'],
            ['ru', 'admin.finance_summary.col_when', 'Когда'],
            ['en', 'admin.finance_summary.empty_no_paid_payments', 'No paid payments in this range yet.'],
            ['hy', 'admin.finance_summary.empty_no_paid_payments', 'Այս ժամանակահատվածում վճարումներ դեռ չկան։'],
            ['ru', 'admin.finance_summary.empty_no_paid_payments', 'В этом периоде оплаченных платежей пока нет.'],
            ['en', 'admin.finance_summary.empty_no_recent_tx', 'No recent transactions yet.'],
            ['hy', 'admin.finance_summary.empty_no_recent_tx', 'Դեռևս գործարքներ չկան։'],
            ['ru', 'admin.finance_summary.empty_no_recent_tx', 'Транзакций пока нет.'],
            ['en', 'admin.finance_summary.meta_collection_rate', '{pct}% of invoices collected on time'],
            ['hy', 'admin.finance_summary.meta_collection_rate', 'Հաշիվ-ապրանքագրերի {pct}%-ը գանձվել է ժամանակին'],
            ['ru', 'admin.finance_summary.meta_collection_rate', '{pct}% счетов оплачено вовремя'],
            ['en', 'admin.finance_summary.meta_commission_split', 'Platform: {platform} · Agent: {agent}'],
            ['hy', 'admin.finance_summary.meta_commission_split', 'Հարթակ՝ {platform} · Գործակալ՝ {agent}'],
            ['ru', 'admin.finance_summary.meta_commission_split', 'Платформа: {platform} · Агент: {agent}'],
            ['en', 'admin.finance_summary.meta_pending_age', 'Avg. age: {avg} days · Oldest: {oldest} days'],
            ['hy', 'admin.finance_summary.meta_pending_age', 'Միջին վաղեմություն՝ {avg} օր · Ամենահինը՝ {oldest} օր'],
            ['ru', 'admin.finance_summary.meta_pending_age', 'Средний срок: {avg} дн. · Самый старый: {oldest} дн.'],
            ['en', 'admin.finance_summary.meta_pending_records', '{n} pending records'],
            ['hy', 'admin.finance_summary.meta_pending_records', '{n} սպասվող գրառում'],
            ['ru', 'admin.finance_summary.meta_pending_records', '{n} записей в ожидании'],
            ['en', 'admin.finance_summary.qa_issue_invoice', 'Issue invoice'],
            ['hy', 'admin.finance_summary.qa_issue_invoice', 'Թողարկել հաշիվ-ապրանքագիր'],
            ['ru', 'admin.finance_summary.qa_issue_invoice', 'Выставить счёт'],
            ['en', 'admin.finance_summary.qa_issue_voucher', 'Issue voucher'],
            ['hy', 'admin.finance_summary.qa_issue_voucher', 'Թողարկել վաուչեր'],
            ['ru', 'admin.finance_summary.qa_issue_voucher', 'Выдать ваучер'],
            ['en', 'admin.finance_summary.qa_monthly_statement', 'Monthly statement'],
            ['hy', 'admin.finance_summary.qa_monthly_statement', 'Ամսական քաղվածք'],
            ['ru', 'admin.finance_summary.qa_monthly_statement', 'Месячная выписка'],
            ['en', 'admin.finance_summary.qa_reconciliation_soon', 'Reconciliation tool coming soon'],
            ['hy', 'admin.finance_summary.qa_reconciliation_soon', 'Հաշտեցման գործիքը շուտով հասանելի կլինի'],
            ['ru', 'admin.finance_summary.qa_reconciliation_soon', 'Инструмент сверки скоро появится'],
            ['en', 'admin.finance_summary.qa_record_payment', 'Record payment'],
            ['hy', 'admin.finance_summary.qa_record_payment', 'Գրանցել վճարում'],
            ['ru', 'admin.finance_summary.qa_record_payment', 'Зафиксировать платёж'],
            ['en', 'admin.finance_summary.qa_run_reconciliation', 'Run reconciliation'],
            ['hy', 'admin.finance_summary.qa_run_reconciliation', 'Կատարել հաշտեցում'],
            ['ru', 'admin.finance_summary.qa_run_reconciliation', 'Запустить сверку'],
            ['en', 'admin.finance_summary.qa_tax_report', 'Tax report'],
            ['hy', 'admin.finance_summary.qa_tax_report', 'Հարկային հաշվետվություն'],
            ['ru', 'admin.finance_summary.qa_tax_report', 'Налоговый отчёт'],
            ['en', 'admin.finance_summary.range_30d', 'Last 30 days'],
            ['hy', 'admin.finance_summary.range_30d', 'Վերջին 30 օրը'],
            ['ru', 'admin.finance_summary.range_30d', 'Последние 30 дней'],
            ['en', 'admin.finance_summary.range_7d', 'Last 7 days'],
            ['hy', 'admin.finance_summary.range_7d', 'Վերջին 7 օրը'],
            ['ru', 'admin.finance_summary.range_7d', 'Последние 7 дней'],
            ['en', 'admin.finance_summary.range_90d', 'Last 90 days'],
            ['hy', 'admin.finance_summary.range_90d', 'Վերջին 90 օրը'],
            ['ru', 'admin.finance_summary.range_90d', 'Последние 90 дней'],
            ['en', 'admin.finance_summary.range_year', 'This year'],
            ['hy', 'admin.finance_summary.range_year', 'Այս տարի'],
            ['ru', 'admin.finance_summary.range_year', 'Текущий год'],
            ['en', 'admin.finance_summary.stat_commissions_accrued', 'Commissions accrued'],
            ['hy', 'admin.finance_summary.stat_commissions_accrued', 'Հաշվեգրված միջնորդավճարներ'],
            ['ru', 'admin.finance_summary.stat_commissions_accrued', 'Начисленные комиссии'],
            ['en', 'admin.finance_summary.stat_pending_count', '{n} pending'],
            ['hy', 'admin.finance_summary.stat_pending_count', '{n} սպասվող'],
            ['ru', 'admin.finance_summary.stat_pending_count', '{n} в ожидании'],
            ['en', 'admin.finance_summary.stat_pending_payments', 'Pending payments'],
            ['hy', 'admin.finance_summary.stat_pending_payments', 'Սպասվող վճարումներ'],
            ['ru', 'admin.finance_summary.stat_pending_payments', 'Ожидающие платежи'],
            ['en', 'admin.finance_summary.stat_total_revenue', 'Total revenue ({range})'],
            ['hy', 'admin.finance_summary.stat_total_revenue', 'Ընդհանուր եկամուտ ({range})'],
            ['ru', 'admin.finance_summary.stat_total_revenue', 'Общая выручка ({range})'],
            ['en', 'admin.finance_summary.subtitle', 'High-level financial overview across the platform · {range}'],
            ['hy', 'admin.finance_summary.subtitle', 'Հարթակի ֆինանսական ընդհանուր պատկերը · {range}'],
            ['ru', 'admin.finance_summary.subtitle', 'Общий финансовый обзор по платформе · {range}'],
            ['en', 'admin.finance_summary.tx_type_commission', 'Commission'],
            ['hy', 'admin.finance_summary.tx_type_commission', 'Միջնորդավճար'],
            ['ru', 'admin.finance_summary.tx_type_commission', 'Комиссия'],
            ['en', 'admin.finance_summary.tx_type_payment_in', 'Payment in'],
            ['hy', 'admin.finance_summary.tx_type_payment_in', 'Մուտքային վճարում'],
            ['ru', 'admin.finance_summary.tx_type_payment_in', 'Входящий платёж'],
            ['en', 'admin.finance_summary.tx_type_payout', 'Payout'],
            ['hy', 'admin.finance_summary.tx_type_payout', 'Ելքային վճարում'],
            ['ru', 'admin.finance_summary.tx_type_payout', 'Выплата'],
            ['en', 'admin.finance_summary.tx_type_refund', 'Refund'],
            ['hy', 'admin.finance_summary.tx_type_refund', 'Փոխհատուցում'],
            ['ru', 'admin.finance_summary.tx_type_refund', 'Возврат'],
            ['en', 'admin.finance_summary.tx_type_voucher_issued', 'Voucher issued'],
            ['hy', 'admin.finance_summary.tx_type_voucher_issued', 'Վաուչերի թողարկում'],
            ['ru', 'admin.finance_summary.tx_type_voucher_issued', 'Выдача ваучера'],
            ['en', 'admin.finance_summary.view_all', 'View all'],
            ['hy', 'admin.finance_summary.view_all', 'Դիտել բոլորը'],
            ['ru', 'admin.finance_summary.view_all', 'Показать все'],
            ['en', 'admin.inventory.cars.subtitle', 'Manage your car rental inventory'],
            ['hy', 'admin.inventory.cars.subtitle', 'Կառավարեք ձեր մեքենաների վարձույթի ցանկը'],
            ['ru', 'admin.inventory.cars.subtitle', 'Управляйте списком автомобилей для аренды'],
            ['en', 'admin.inventory.excursions.subtitle', 'Manage your excursion inventory'],
            ['hy', 'admin.inventory.excursions.subtitle', 'Կառավարեք ձեր էքսկուրսիաների ցանկը'],
            ['ru', 'admin.inventory.excursions.subtitle', 'Управляйте списком ваших экскурсий'],
            ['en', 'admin.inventory.flights.subtitle', 'Manage your flight inventory'],
            ['hy', 'admin.inventory.flights.subtitle', 'Կառավարեք ձեր թռիչքների ցանկը'],
            ['ru', 'admin.inventory.flights.subtitle', 'Управляйте списком ваших авиабилетов'],
            ['en', 'admin.inventory.hotels.subtitle', 'Manage your hotel inventory'],
            ['hy', 'admin.inventory.hotels.subtitle', 'Կառավարեք ձեր հյուրանոցների ցանկը'],
            ['ru', 'admin.inventory.hotels.subtitle', 'Управляйте списком ваших отелей'],
            ['en', 'admin.inventory.packages_oversight.subtitle', "Platform oversight of all operators' packages."],
            ['hy', 'admin.inventory.packages_oversight.subtitle', 'Բոլոր օպերատորների փաթեթների վերահսկումը հարթակի կողմից։'],
            ['ru', 'admin.inventory.packages_oversight.subtitle', 'Контроль пакетов всех операторов на уровне платформы.'],
            ['en', 'admin.inventory.transfers.subtitle', 'Manage your transfer inventory'],
            ['hy', 'admin.inventory.transfers.subtitle', 'Կառավարեք ձեր փոխադրումների ցանկը'],
            ['ru', 'admin.inventory.transfers.subtitle', 'Управляйте списком ваших трансферов'],
            ['en', 'admin.inventory.visas.subtitle', 'Manage your visa services'],
            ['hy', 'admin.inventory.visas.subtitle', 'Կառավարեք ձեր վիզային ծառայությունները'],
            ['ru', 'admin.inventory.visas.subtitle', 'Управляйте вашими визовыми услугами'],
            ['en', 'admin.package_orders.subtitle', 'Package orders across the platform with payment status.'],
            ['hy', 'admin.package_orders.subtitle', 'Հարթակի փաթեթի պատվերները՝ վճարման կարգավիճակով։'],
            ['ru', 'admin.package_orders.subtitle', 'Заказы пакетов по всей платформе со статусом оплаты.'],
            ['en', 'admin.statistics.subtitle', 'Your sales and service performance over the selected period.'],
            ['hy', 'admin.statistics.subtitle', 'Ձեր վաճառքների և ծառայությունների արդյունքներն ընտրված ժամանակահատվածում։'],
            ['ru', 'admin.statistics.subtitle', 'Показатели ваших продаж и услуг за выбранный период.'],
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

        // HY/RU backfill for pre-existing EN-only keys — never overwrite.
        DB::table('ui_translations')->insertOrIgnore([
            ['language_code' => 'hy', 'key' => 'admin.finance_summary.title_short', 'value' => 'Ֆինանսների ամփոփ', 'created_at' => $now, 'updated_at' => $now],
            ['language_code' => 'ru', 'key' => 'admin.finance_summary.title_short', 'value' => 'Финансовая сводка', 'created_at' => $now, 'updated_at' => $now],
            ['language_code' => 'hy', 'key' => 'admin.finance_summary.btn_refresh', 'value' => 'Թարմացնել', 'created_at' => $now, 'updated_at' => $now],
            ['language_code' => 'ru', 'key' => 'admin.finance_summary.btn_refresh', 'value' => 'Обновить', 'created_at' => $now, 'updated_at' => $now],
        ]);

        foreach (['en', 'hy', 'ru'] as $lang) {
            Cache::forget("ui_translations_{$lang}");
        }
    }

    public function down(): void
    {
        // Keys may be refined by the AI translation scan afterwards — keep.
    }
};
