<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Phase 13.6 batch 2 — ui_translations for the four /inventory/* pages
 * (cars, excursions, flights, transfers). Flights was already wrapped
 * in t() but the matching ui_translations rows had never been seeded,
 * so the page rendered raw keys. Cars/excursions/transfers were just
 * refactored to wrap their filter bar labels in t().
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $rows = [
            // ── inventory_cars ────────────────────────────────────────
            ['admin.inventory_cars.title', 'Cars inventory', 'Каталог автомобилей', 'Մեքենաների ցանկ'],
            ['admin.inventory_cars.col_id', 'ID', 'ID', 'ID'],
            ['admin.inventory_cars.col_company', 'Company', 'Компания', 'Ընկերություն'],
            ['admin.inventory_cars.col_pickup', 'Pickup', 'Точка подачи', 'Վերցնելու վայր'],
            ['admin.inventory_cars.col_dropoff', 'Dropoff', 'Точка возврата', 'Հանձնելու վայր'],
            ['admin.inventory_cars.col_fleet', 'Fleet', 'Парк', 'Ավտոպարկ'],
            ['admin.inventory_cars.col_class', 'Class', 'Класс', 'Կարգ'],
            ['admin.inventory_cars.col_base_price', 'Base price', 'Базовая цена', 'Բազային գին'],
            ['admin.inventory_cars.col_status', 'Status', 'Статус', 'Կարգավիճակ'],
            ['admin.inventory_cars.col_offer', 'Offer', 'Предложение', 'Առաջարկ'],
            ['admin.inventory_cars.placeholder_substring', 'substring', 'подстрока', 'ենթատող'],
            ['admin.inventory_cars.filter_fleet', 'Fleet', 'Парк', 'Ավտոպարկ'],
            ['admin.inventory_cars.filter_origin', 'Origin (pickup)', 'Откуда (подача)', 'Սկզբնակետ (վերցնելու վայր)'],
            ['admin.inventory_cars.filter_destination', 'Destination (dropoff)', 'Куда (возврат)', 'Վերջնակետ (հանձնելու վայր)'],
            ['admin.inventory_cars.filter_status', 'Status', 'Статус', 'Կարգավիճակ'],
            ['admin.inventory_cars.filter_booking_date', 'Booking date (invoice)', 'Дата бронирования (счёт)', 'Ամրագրման ամսաթիվ (հաշիվ)'],
            ['admin.inventory_cars.filter_rental_date', 'Rental day (availability)', 'День аренды (доступность)', 'Վարձման օր (հասանելիություն)'],
            ['admin.inventory_cars.filter_rental_from', 'Rental from', 'Аренда с', 'Վարձում սկսած'],
            ['admin.inventory_cars.filter_rental_to', 'Rental to', 'Аренда до', 'Վարձում մինչև'],

            // ── inventory_excursions ─────────────────────────────────
            ['admin.inventory_excursions.title', 'Excursions inventory', 'Каталог экскурсий', 'Էքսկուրսիաների ցանկ'],
            ['admin.inventory_excursions.col_category', 'Category', 'Категория', 'Կատեգորիա'],
            ['admin.inventory_excursions.col_location', 'Location', 'Локация', 'Վայր'],
            ['admin.inventory_excursions.col_duration', 'Duration', 'Длительность', 'Տևողություն'],
            ['admin.inventory_excursions.col_group_size', 'Group size', 'Размер группы', 'Խմբի չափ'],
            ['admin.inventory_excursions.col_price', 'Price', 'Цена', 'Գին'],
            ['admin.inventory_excursions.phase_1', '1 — location & geography', '1 — локация и география', '1 — վայր և աշխարհագրություն'],
            ['admin.inventory_excursions.phase_2', '2 — schedule & status', '2 — расписание и статус', '2 — ժամանակացույց և կարգավիճակ'],
            ['admin.inventory_excursions.phase_3', '3 — orders & invoices', '3 — заказы и счета', '3 — պատվերներ և հաշիվներ'],
            ['admin.inventory_excursions.phase_4', '4 — price', '4 — цена', '4 — գին'],
            ['admin.inventory_excursions.filter_date_overlap', 'Date (overlap)', 'Дата (пересечение)', 'Ամսաթիվ (հատման)'],
            ['admin.inventory_excursions.filter_date_from', 'Date from', 'Дата с', 'Ամսաթիվ սկսած'],
            ['admin.inventory_excursions.filter_date_to', 'Date to', 'Дата по', 'Ամսաթիվ մինչև'],
            ['admin.inventory_excursions.filter_order_number', 'Order number', 'Номер заказа', 'Պատվերի համար'],
            ['admin.inventory_excursions.filter_min_price', 'Min price (offer)', 'Мин. цена (предложение)', 'Նվազ. գին (առաջարկ)'],
            ['admin.inventory_excursions.filter_max_price', 'Max price (offer)', 'Макс. цена (предложение)', 'Առավ. գին (առաջարկ)'],
            ['admin.inventory_excursions.placeholder_date', 'YYYY-MM-DD or ISO', 'YYYY-MM-DD или ISO', 'YYYY-MM-DD կամ ISO'],
            ['admin.inventory_excursions.placeholder_invoice_ref', 'invoice ref', 'номер счёта', 'հաշիվի համար'],

            // ── inventory_transfers ──────────────────────────────────
            ['admin.inventory_transfers.title', 'Transfers inventory', 'Каталог трансферов', 'Տրանսֆերների ցանկ'],
            ['admin.inventory_transfers.col_title', 'Title', 'Название', 'Անուն'],
            ['admin.inventory_transfers.col_type', 'Type', 'Тип', 'Տեսակ'],
            ['admin.inventory_transfers.filter_origin', 'Origin', 'Откуда', 'Սկզբնակետ'],
            ['admin.inventory_transfers.filter_destination', 'Destination', 'Куда', 'Վերջնակետ'],
            ['admin.inventory_transfers.filter_vehicle_category', 'Vehicle category', 'Категория транспорта', 'Տրանսպորտի կատեգորիա'],
            ['admin.inventory_transfers.filter_trip_date', 'Trip date', 'Дата поездки', 'Ուղևորության ամսաթիվ'],
            ['admin.inventory_transfers.filter_passenger', 'Passengers', 'Пассажиры', 'Ուղևորներ'],

            // ── inventory.flights (existing wrapper used in flights/page.tsx) ──
            ['admin.inventory.flights.title', 'Flights inventory', 'Каталог рейсов', 'Թռիչքների ցանկ'],
            ['admin.inventory.flights.col.id', 'ID', 'ID', 'ID'],
            ['admin.inventory.flights.col.company', 'Company', 'Компания', 'Ընկերություն'],
            ['admin.inventory.flights.col.route', 'Route', 'Маршрут', 'Երթուղի'],
            ['admin.inventory.flights.col.departure', 'Departure', 'Вылет', 'Մեկնում'],
            ['admin.inventory.flights.col.status', 'Status', 'Статус', 'Կարգավիճակ'],
            ['admin.inventory.flights.col.offer', 'Offer', 'Предложение', 'Առաջարկ'],
            ['admin.inventory.flights.filter.label.company_id', 'Company ID', 'ID компании', 'Ընկերության ID'],
            ['admin.inventory.flights.filter.label.departure_city', 'Departure city', 'Город вылета', 'Մեկնման քաղաք'],
            ['admin.inventory.flights.filter.label.arrival_city', 'Arrival city', 'Город прибытия', 'Ժամանման քաղաք'],
            ['admin.inventory.flights.filter.label.departure_airport_code', 'Departure airport (code)', 'Аэропорт вылета (код)', 'Մեկնման օդանավակայան (կոդ)'],
            ['admin.inventory.flights.filter.label.arrival_airport_code', 'Arrival airport (code)', 'Аэропорт прибытия (код)', 'Ժամանման օդանավակայան (կոդ)'],
            ['admin.inventory.flights.filter.label.departure_date_from', 'Departure from', 'Вылет с', 'Մեկնում սկսած'],
            ['admin.inventory.flights.filter.label.departure_date_to', 'Departure to', 'Вылет по', 'Մեկնում մինչև'],
            ['admin.inventory.flights.filter.label.status', 'Status', 'Статус', 'Կարգավիճակ'],
            ['admin.inventory.flights.filter.label.cabin_class', 'Cabin class', 'Класс обслуживания', 'Սրահի կարգ'],
            ['admin.inventory.flights.filter.label.min_price', 'Min price', 'Мин. цена', 'Նվազ. գին'],
            ['admin.inventory.flights.filter.label.max_price', 'Max price', 'Макс. цена', 'Առավ. գին'],
            ['admin.inventory.flights.filter.placeholder.company', 'Company numeric id', 'Числовой ID компании', 'Ընկերության թվային ID'],
            ['admin.inventory.flights.filter.placeholder.text', 'substring', 'подстрока', 'ենթատող'],
            ['admin.inventory.flights.filter.placeholder.departure_airport_code', 'EVN', 'EVN', 'EVN'],
            ['admin.inventory.flights.filter.placeholder.arrival_airport_code', 'IST', 'IST', 'IST'],
            ['admin.inventory.flights.filter.placeholder.cabin_class', 'economy / business', 'эконом / бизнес', 'էկոնոմ / բիզնես'],
            ['admin.inventory.flights.filter.placeholder.min_price', 'from', 'от', 'սկսած'],
            ['admin.inventory.flights.filter.placeholder.max_price', 'to', 'до', 'մինչև'],
            ['admin.inventory.flights.filter.action.apply', 'Apply filters', 'Применить', 'Կիրառել'],
            ['admin.inventory.flights.filter.action.clear', 'Clear', 'Очистить', 'Մաքրել'],
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
                $q->where('key', 'like', 'admin.inventory_cars.%')
                    ->orWhere('key', 'like', 'admin.inventory_excursions.%')
                    ->orWhere('key', 'like', 'admin.inventory_transfers.%')
                    ->orWhere('key', 'like', 'admin.inventory.flights.%');
            })
            ->delete();

        foreach (['en', 'ru', 'hy'] as $lang) {
            Cache::forget('ui_translations_'.$lang);
        }
    }
};
