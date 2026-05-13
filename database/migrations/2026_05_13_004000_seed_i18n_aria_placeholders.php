<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Sprint 4 — Localization audit. First pass: aria-label + placeholder
     * strings used across the public frontend (~45 aria + 13 placeholder
     * unique strings, identified via grep audit on 2026-05-13).
     *
     * Subsequent passes will sweep error messages, status text, page
     * headings, etc. The audit document at docs/audits/2026-05-i18n.md
     * tracks remaining categories.
     */
    public function up(): void
    {
        $now = now();

        // [key, en, ru, hy]
        $rows = [
            // ── aria.* ────────────────────────────────────────────────
            ['aria.account', 'Account', 'Аккаунт', 'Հաշիվ'],
            ['aria.add_bag', 'Add bag', 'Добавить багаж', 'Ավելացնել ուղեբեռ'],
            ['aria.back', 'Back', 'Назад', 'Հետ'],
            ['aria.booking_confirmed', 'Booking confirmed', 'Бронирование подтверждено', 'Ամրագրումը հաստատվեց'],
            ['aria.breadcrumb', 'Breadcrumb', 'Хлебные крошки', 'Նավիգացիոն շղթա'],
            ['aria.close', 'Close', 'Закрыть', 'Փակել'],
            ['aria.close_filters', 'Close filters', 'Закрыть фильтры', 'Փակել զտիչները'],
            ['aria.close_map', 'Close map', 'Закрыть карту', 'Փակել քարտեզը'],
            ['aria.close_modal', 'Close modal', 'Закрыть окно', 'Փակել պատուհանը'],
            ['aria.close_sort', 'Close sort', 'Закрыть сортировку', 'Փակել տեսակավորումը'],
            ['aria.contact_phone_options', 'Contact phone options', 'Варианты связи по телефону', 'Հեռախոսային կապի տարբերակներ'],
            ['aria.decrease', 'Decrease', 'Уменьшить', 'Նվազեցնել'],
            ['aria.decrease_adults', 'Decrease adults', 'Уменьшить взрослых', 'Նվազեցնել մեծահասակների քանակը'],
            ['aria.decrease_children', 'Decrease children', 'Уменьшить детей', 'Նվազեցնել երեխաների քանակը'],
            ['aria.email_address', 'Email address', 'Электронная почта', 'Էլ. փոստի հասցե'],
            ['aria.email_address_newsletter', 'Email address for newsletter', 'Эл. почта для рассылки', 'Էլ. փոստի հասցե տեղեկագրի համար'],
            ['aria.featured_offers_partners', 'Featured offers and partners', 'Особые предложения и партнёры', 'Հատուկ առաջարկներ և գործընկերներ'],
            ['aria.filters', 'Filters', 'Фильтры', 'Զտիչներ'],
            ['aria.hero', 'Hero', 'Главный баннер', 'Հիմնական պաստառ'],
            ['aria.home', 'Home', 'Главная', 'Գլխավոր'],
            ['aria.increase', 'Increase', 'Увеличить', 'Ավելացնել'],
            ['aria.increase_adults', 'Increase adults', 'Увеличить взрослых', 'Ավելացնել մեծահասակների քանակը'],
            ['aria.increase_children', 'Increase children', 'Увеличить детей', 'Ավելացնել երեխաների քանակը'],
            ['aria.leave_review', 'Leave review', 'Оставить отзыв', 'Թողնել կարծիք'],
            ['aria.map_view', 'Map view', 'Вид карты', 'Քարտեզի տեսք'],
            ['aria.max_price', 'Max price', 'Максимальная цена', 'Առավելագույն գին'],
            ['aria.maximum_price', 'Maximum price', 'Максимальная цена', 'Առավելագույն գին'],
            ['aria.minimum_price', 'Minimum price', 'Минимальная цена', 'Նվազագույն գին'],
            ['aria.mobile_primary', 'Mobile primary', 'Основное (мобильное)', 'Հիմնական (բջջային)'],
            ['aria.next_offers', 'Next offers', 'Следующие предложения', 'Հաջորդ առաջարկներ'],
            ['aria.open_currency_dropdown', 'Open currency dropdown', 'Открыть выбор валюты', 'Բացել արժույթի ընտրացանկը'],
            ['aria.open_language_dropdown', 'Open language dropdown', 'Открыть выбор языка', 'Բացել լեզվի ընտրացանկը'],
            ['aria.previous_offers', 'Previous offers', 'Предыдущие предложения', 'Նախորդ առաջարկներ'],
            ['aria.primary', 'Primary', 'Основное', 'Հիմնական'],
            ['aria.remove_bag', 'Remove bag', 'Удалить багаж', 'Հեռացնել ուղեբեռը'],
            ['aria.save_flight', 'Save flight', 'Сохранить рейс', 'Պահպանել թռիչքը'],
            ['aria.search_hotels', 'Search hotels', 'Поиск отелей', 'Որոնել հյուրանոցներ'],
            ['aria.share_flight', 'Share flight', 'Поделиться рейсом', 'Կիսվել թռիչքով'],
            ['aria.share_hotel', 'Share hotel', 'Поделиться отелем', 'Կիսվել հյուրանոցով'],
            ['aria.show_on_map', 'Show on map', 'Показать на карте', 'Ցույց տալ քարտեզին'],
            ['aria.sign_in_required', 'Sign in required', 'Требуется вход', 'Պահանջվում է մուտք գործել'],
            ['aria.sort', 'Sort', 'Сортировка', 'Տեսակավորում'],
            ['aria.subscribe_newsletter', 'Subscribe to our newsletter', 'Подписаться на рассылку', 'Բաժանորդագրվել մեր տեղեկագրին'],
            ['aria.swap_departure_destination', 'Swap departure and destination', 'Поменять отправление и назначение', 'Փոխանակել մեկնման և ուղղման կետերը'],
            ['aria.toggle_theme', 'Toggle theme', 'Сменить тему', 'Փոխել թեման'],

            // ── placeholder.* ────────────────────────────────────────
            ['placeholder.any', 'Any', 'Любой', 'Ցանկացած'],
            ['placeholder.armenia', 'Armenia', 'Армения', 'Հայաստան'],
            ['placeholder.email', 'Email', 'Эл. почта', 'Էլ. փոստ'],
            ['placeholder.email_address', 'Email address', 'Электронная почта', 'Էլ. փոստի հասցե'],
            ['placeholder.enter_email', 'Enter your email address', 'Введите ваш email', 'Մուտքագրեք ձեր էլ. փոստի հասցեն'],
            ['placeholder.first_given_name', 'First given name', 'Имя', 'Անուն'],
            ['placeholder.first_name', 'First name', 'Имя', 'Անուն'],
            ['placeholder.ingo_armenia', 'Ingo Armenia', 'Inго Армения', 'Ինգո Արմենիա'],
            ['placeholder.issuing_country', 'Issuing country', 'Страна выдачи', 'Տրման երկիր'],
            ['placeholder.last_name', 'Last name', 'Фамилия', 'Ազգանուն'],
            ['placeholder.license_number', 'License number', 'Номер прав', 'Իրավունքի համար'],
            ['placeholder.name', 'Name', 'Имя', 'Անուն'],
            ['placeholder.name_on_card', 'Name on card', 'Имя на карте', 'Անուն քարտի վրա'],

            // ── common.* ─ general reusable ───────────────────────────
            ['common.action.save', 'Save', 'Сохранить', 'Պահպանել'],
            ['common.action.cancel', 'Cancel', 'Отмена', 'Չեղարկել'],
            ['common.action.edit', 'Edit', 'Изменить', 'Խմբագրել'],
            ['common.action.delete', 'Delete', 'Удалить', 'Ջնջել'],
            ['common.action.remove', 'Remove', 'Удалить', 'Հեռացնել'],
            ['common.action.confirm', 'Confirm', 'Подтвердить', 'Հաստատել'],
            ['common.action.next', 'Next', 'Далее', 'Հաջորդ'],
            ['common.action.previous', 'Previous', 'Назад', 'Նախորդ'],
            ['common.action.back', 'Back', 'Назад', 'Հետ'],
            ['common.action.submit', 'Submit', 'Отправить', 'Ուղարկել'],
            ['common.action.apply', 'Apply', 'Применить', 'Կիրառել'],
            ['common.action.close', 'Close', 'Закрыть', 'Փակել'],
            ['common.action.subscribe', 'Subscribe', 'Подписаться', 'Բաժանորդագրվել'],
            ['common.status.loading', 'Loading…', 'Загрузка…', 'Բեռնվում է…'],
            ['common.status.saving', 'Saving…', 'Сохранение…', 'Պահպանվում է…'],
            ['common.status.saved', 'Saved', 'Сохранено', 'Պահպանվեց'],
            ['common.status.error', 'Something went wrong', 'Что-то пошло не так', 'Ինչ-որ բան սխալ ընթացավ'],
            ['common.status.success', 'Success', 'Успешно', 'Հաջողվեց'],
            ['common.empty.no_results', 'No results', 'Нет результатов', 'Արդյունքներ չկան'],
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
        $prefixes = ['aria.', 'placeholder.', 'common.action.', 'common.status.', 'common.empty.'];
        foreach ($prefixes as $prefix) {
            DB::table('ui_translations')->where('key', 'like', $prefix.'%')->delete();
        }
        Cache::forget('ui_translations_en');
        Cache::forget('ui_translations_hy');
        Cache::forget('ui_translations_ru');
    }
};
