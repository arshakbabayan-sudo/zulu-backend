<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Follow-up to 2026_05_17_030000_seed_account_profile_zulu10_keys.php which
 * was committed with a stale (partial) row list — the on-disk file had been
 * extended after the initial `git add`, so 54 keys × 3 langs never reached
 * the previous migration.
 *
 * This file re-seeds the missing Trips / Saved / Reviews / Payment / Review
 * modal translation rows used by the Zulu_10 account re-skin. Idempotent:
 * existing rows are updated, missing rows are inserted.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $rows = [
            // Trips
            ['en', 'account.trips.heading', 'Trips'],
            ['hy', 'account.trips.heading', 'Ուղևորություններ'],
            ['ru', 'account.trips.heading', 'Поездки'],

            ['en', 'account.trips.duration_days', '{days} days'],
            ['hy', 'account.trips.duration_days', '{days} օր'],
            ['ru', 'account.trips.duration_days', '{days} дн.'],

            ['en', 'account.trips.write_review', 'Write review'],
            ['hy', 'account.trips.write_review', 'Գրել կարծիք'],
            ['ru', 'account.trips.write_review', 'Написать отзыв'],

            ['en', 'common.page', 'Page'],
            ['hy', 'common.page', 'Էջ'],
            ['ru', 'common.page', 'Стр.'],

            // Saved tabs + actions
            ['en', 'account.saved.tab.all', 'All'],
            ['hy', 'account.saved.tab.all', 'Բոլորը'],
            ['ru', 'account.saved.tab.all', 'Все'],

            ['en', 'account.saved.tab.hotels', 'Hotels'],
            ['hy', 'account.saved.tab.hotels', 'Հյուրանոցներ'],
            ['ru', 'account.saved.tab.hotels', 'Отели'],

            ['en', 'account.saved.tab.flights', 'Flights'],
            ['hy', 'account.saved.tab.flights', 'Չվերթներ'],
            ['ru', 'account.saved.tab.flights', 'Рейсы'],

            ['en', 'account.saved.tab.excursions', 'Excursions'],
            ['hy', 'account.saved.tab.excursions', 'Էքսկուրսիաներ'],
            ['ru', 'account.saved.tab.excursions', 'Экскурсии'],

            ['en', 'account.saved.tab.transfers', 'Transfers'],
            ['hy', 'account.saved.tab.transfers', 'Տրանսֆեր'],
            ['ru', 'account.saved.tab.transfers', 'Трансферы'],

            ['en', 'account.saved.tab.cars', 'Cars'],
            ['hy', 'account.saved.tab.cars', 'Մեքենաներ'],
            ['ru', 'account.saved.tab.cars', 'Автомобили'],

            ['en', 'account.saved.tab.packages', 'Packages'],
            ['hy', 'account.saved.tab.packages', 'Փաթեթներ'],
            ['ru', 'account.saved.tab.packages', 'Пакеты'],

            ['en', 'account.saved.view_property', 'View property'],
            ['hy', 'account.saved.view_property', 'Տեսնել առաջարկը'],
            ['ru', 'account.saved.view_property', 'Подробнее'],

            ['en', 'account.saved.remove', 'Remove from saved'],
            ['hy', 'account.saved.remove', 'Հանել պահպանվածներից'],
            ['ru', 'account.saved.remove', 'Удалить из сохранённых'],

            // Reviews
            ['en', 'account.reviews.no_text', 'No comment provided.'],
            ['hy', 'account.reviews.no_text', 'Մեկնաբանություն չկա։'],
            ['ru', 'account.reviews.no_text', 'Комментарий не оставлен.'],

            // Payment
            ['en', 'account.payment.section_title', 'Saved payment methods'],
            ['hy', 'account.payment.section_title', 'Պահպանված վճարման եղանակները'],
            ['ru', 'account.payment.section_title', 'Сохранённые способы оплаты'],

            ['en', 'account.payment.expires', 'Expires'],
            ['hy', 'account.payment.expires', 'Ժամկետը՝'],
            ['ru', 'account.payment.expires', 'Истекает'],

            ['en', 'account.payment.default', 'Default'],
            ['hy', 'account.payment.default', 'Հիմնական'],
            ['ru', 'account.payment.default', 'Основной'],

            ['en', 'account.payment.add_card', 'Add a credit card'],
            ['hy', 'account.payment.add_card', 'Ավելացնել քարտ'],
            ['ru', 'account.payment.add_card', 'Добавить карту'],

            ['en', 'account.payment.add_card_title', 'Add a credit card'],
            ['hy', 'account.payment.add_card_title', 'Ավելացնել քարտ'],
            ['ru', 'account.payment.add_card_title', 'Добавить карту'],

            ['en', 'account.payment.add', 'Add'],
            ['hy', 'account.payment.add', 'Ավելացնել'],
            ['ru', 'account.payment.add', 'Добавить'],

            ['en', 'account.payment.field.name_on_card', 'Name on card'],
            ['hy', 'account.payment.field.name_on_card', 'Քարտի վրա գրված անունը'],
            ['ru', 'account.payment.field.name_on_card', 'Имя на карте'],

            ['en', 'account.payment.field.card_number', 'Card number'],
            ['hy', 'account.payment.field.card_number', 'Քարտի համարը'],
            ['ru', 'account.payment.field.card_number', 'Номер карты'],

            ['en', 'account.payment.field.expiration_date', 'Expiration date'],
            ['hy', 'account.payment.field.expiration_date', 'Ժամկետը'],
            ['ru', 'account.payment.field.expiration_date', 'Срок действия'],

            ['en', 'account.payment.field.address_line', 'Address line'],
            ['hy', 'account.payment.field.address_line', 'Հասցե'],
            ['ru', 'account.payment.field.address_line', 'Адрес'],

            ['en', 'account.payment.field.postal_code', 'Postal code'],
            ['hy', 'account.payment.field.postal_code', 'Փոստային ինդեքս'],
            ['ru', 'account.payment.field.postal_code', 'Почтовый индекс'],

            ['en', 'account.payment.field.city', 'City'],
            ['hy', 'account.payment.field.city', 'Քաղաք'],
            ['ru', 'account.payment.field.city', 'Город'],

            ['en', 'account.payment.field.state_region', 'State/Region'],
            ['hy', 'account.payment.field.state_region', 'Մարզ/նահանգ'],
            ['ru', 'account.payment.field.state_region', 'Регион'],

            ['en', 'account.payment.field.country', 'Country'],
            ['hy', 'account.payment.field.country', 'Երկիր'],
            ['ru', 'account.payment.field.country', 'Страна'],

            // Review modal
            ['en', 'review_modal.title', 'Leave Review'],
            ['hy', 'review_modal.title', 'Թողնել կարծիք'],
            ['ru', 'review_modal.title', 'Оставить отзыв'],

            ['en', 'review_modal.success', 'Thank you for your review!'],
            ['hy', 'review_modal.success', 'Շնորհակալություն ձեր կարծիքի համար։'],
            ['ru', 'review_modal.success', 'Спасибо за ваш отзыв!'],

            ['en', 'review_modal.cat.cleanliness', 'Cleanliness'],
            ['hy', 'review_modal.cat.cleanliness', 'Մաքրություն'],
            ['ru', 'review_modal.cat.cleanliness', 'Чистота'],

            ['en', 'review_modal.cat.staff', 'Staff and service'],
            ['hy', 'review_modal.cat.staff', 'Անձնակազմ և սպասարկում'],
            ['ru', 'review_modal.cat.staff', 'Персонал и сервис'],

            ['en', 'review_modal.cat.amenities', 'Amenities'],
            ['hy', 'review_modal.cat.amenities', 'Հարմարություններ'],
            ['ru', 'review_modal.cat.amenities', 'Удобства'],

            ['en', 'review_modal.cat.property', 'Property conditions & facilities'],
            ['hy', 'review_modal.cat.property', 'Հյուրանոցի վիճակ և հարմարություններ'],
            ['ru', 'review_modal.cat.property', 'Состояние и оснащение объекта'],

            ['en', 'review_modal.cat.eco', 'Eco-friendliness'],
            ['hy', 'review_modal.cat.eco', 'Բնապահպանական մոտեցում'],
            ['ru', 'review_modal.cat.eco', 'Экологичность'],

            ['en', 'review_modal.write_review', 'Write your review'],
            ['hy', 'review_modal.write_review', 'Գրեք ձեր կարծիքը'],
            ['ru', 'review_modal.write_review', 'Напишите ваш отзыв'],

            ['en', 'review_modal.placeholder', 'Share your experience…'],
            ['hy', 'review_modal.placeholder', 'Կիսվեք ձեր փորձով…'],
            ['ru', 'review_modal.placeholder', 'Поделитесь впечатлениями…'],

            ['en', 'review_modal.too_short', 'Review text must be at least 10 characters.'],
            ['hy', 'review_modal.too_short', 'Կարծիքը պետք է լինի առնվազն 10 նիշ։'],
            ['ru', 'review_modal.too_short', 'Отзыв должен содержать минимум 10 символов.'],

            ['en', 'review_modal.too_long', 'Review text cannot exceed 500 characters.'],
            ['hy', 'review_modal.too_long', 'Կարծիքը չի կարող գերազանցել 500 նիշը։'],
            ['ru', 'review_modal.too_long', 'Отзыв не должен превышать 500 символов.'],

            ['en', 'review_modal.recommend.q', 'Would you recommend this hotel?'],
            ['hy', 'review_modal.recommend.q', 'Կառաջարկե՞ք այս հյուրանոցը'],
            ['ru', 'review_modal.recommend.q', 'Рекомендуете ли этот отель?'],

            ['en', 'review_modal.recommend.no', 'NO'],
            ['hy', 'review_modal.recommend.no', 'ՈՉ'],
            ['ru', 'review_modal.recommend.no', 'НЕТ'],

            ['en', 'review_modal.recommend.yes', 'Yes'],
            ['hy', 'review_modal.recommend.yes', 'Այո'],
            ['ru', 'review_modal.recommend.yes', 'Да'],

            ['en', 'review_modal.companion.q', 'Who did you go with?'],
            ['hy', 'review_modal.companion.q', 'Ո՞ւմ հետ եք եղել'],
            ['ru', 'review_modal.companion.q', 'С кем вы путешествовали?'],

            ['en', 'review_modal.companion.couples', 'Couples'],
            ['hy', 'review_modal.companion.couples', 'Զույգով'],
            ['ru', 'review_modal.companion.couples', 'Пара'],

            ['en', 'review_modal.companion.family', 'Family'],
            ['hy', 'review_modal.companion.family', 'Ընտանիքով'],
            ['ru', 'review_modal.companion.family', 'Семья'],

            ['en', 'review_modal.companion.friends', 'Friends'],
            ['hy', 'review_modal.companion.friends', 'Ընկերներով'],
            ['ru', 'review_modal.companion.friends', 'Друзья'],

            ['en', 'review_modal.companion.business', 'Business'],
            ['hy', 'review_modal.companion.business', 'Գործով'],
            ['ru', 'review_modal.companion.business', 'Бизнес'],

            ['en', 'review_modal.companion.solo', 'Solo'],
            ['hy', 'review_modal.companion.solo', 'Մենակ'],
            ['ru', 'review_modal.companion.solo', 'Один'],

            ['en', 'review_modal.photos.label', 'Add some photos'],
            ['hy', 'review_modal.photos.label', 'Ավելացրեք լուսանկարներ'],
            ['ru', 'review_modal.photos.label', 'Добавьте фотографии'],

            ['en', 'review_modal.photos.optional', 'optional'],
            ['hy', 'review_modal.photos.optional', 'ընտրովի'],
            ['ru', 'review_modal.photos.optional', 'необязательно'],

            ['en', 'review_modal.photos.cta', 'Upload your image'],
            ['hy', 'review_modal.photos.cta', 'Բեռնել նկար'],
            ['ru', 'review_modal.photos.cta', 'Загрузите изображение'],

            ['en', 'review_modal.need_signin', 'You need to be signed in to submit a review.'],
            ['hy', 'review_modal.need_signin', 'Կարծիք թողնելու համար պետք է մուտք գործել։'],
            ['ru', 'review_modal.need_signin', 'Для отправки отзыва нужно войти в аккаунт.'],

            ['en', 'review_modal.submitting', 'Submitting…'],
            ['hy', 'review_modal.submitting', 'Ուղարկվում է…'],
            ['ru', 'review_modal.submitting', 'Отправка…'],

            ['en', 'review_modal.submit', 'Submit review'],
            ['hy', 'review_modal.submit', 'Ուղարկել կարծիք'],
            ['ru', 'review_modal.submit', 'Отправить отзыв'],
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
        // Intentionally no-op: rollback would conflict with the 030000 migration
        // since both reference these keys; rely on the 030000 down() if needed.
    }
};
