SET client_encoding = 'UTF8';

-- Phase 1.4 — /operator/flights page translations
-- Source: docs/roadmaps/admin-audit-roadmap-2026-05-22.md

INSERT INTO ui_translations (key, language_code, value, created_at, updated_at) VALUES
  -- Common (reused, but added here in case missing)
  ('admin.crud.common.fix_following', 'en', 'Please fix the following:', NOW(), NOW()),
  ('admin.crud.common.fix_following', 'hy', 'Ուղղեք հետևյալը՝', NOW(), NOW()),
  ('admin.crud.common.fix_following', 'ru', 'Пожалуйста, исправьте следующее:', NOW(), NOW()),

  ('admin.crud.common.not_signed_in', 'en', 'Not signed in.', NOW(), NOW()),
  ('admin.crud.common.not_signed_in', 'hy', 'Մուտք գործված չեք։', NOW(), NOW()),
  ('admin.crud.common.not_signed_in', 'ru', 'Вы не вошли в систему.', NOW(), NOW()),

  -- Flight page errors
  ('admin.crud.flights.err_load_one', 'en', 'Failed to load flight', NOW(), NOW()),
  ('admin.crud.flights.err_load_one', 'hy', 'Չհաջողվեց բեռնել թռիչքը', NOW(), NOW()),
  ('admin.crud.flights.err_load_one', 'ru', 'Не удалось загрузить рейс', NOW(), NOW()),

  ('admin.crud.flights.err_submit_failed', 'en', 'Submit failed.', NOW(), NOW()),
  ('admin.crud.flights.err_submit_failed', 'hy', 'Ուղարկումը ձախողվեց։', NOW(), NOW()),
  ('admin.crud.flights.err_submit_failed', 'ru', 'Отправка не удалась.', NOW(), NOW()),

  ('admin.crud.flights.err_export_failed', 'en', 'Export failed', NOW(), NOW()),
  ('admin.crud.flights.err_export_failed', 'hy', 'Արտահանումը ձախողվեց', NOW(), NOW()),
  ('admin.crud.flights.err_export_failed', 'ru', 'Экспорт не удался', NOW(), NOW()),

  -- Loading + form headers
  ('admin.crud.flights.loading', 'en', 'Loading flight…', NOW(), NOW()),
  ('admin.crud.flights.loading', 'hy', 'Թռիչքը բեռնվում է…', NOW(), NOW()),
  ('admin.crud.flights.loading', 'ru', 'Загрузка рейса…', NOW(), NOW()),

  ('admin.crud.flights.form_edit', 'en', 'Edit flight', NOW(), NOW()),
  ('admin.crud.flights.form_edit', 'hy', 'Խմբագրել թռիչքը', NOW(), NOW()),
  ('admin.crud.flights.form_edit', 'ru', 'Редактировать рейс', NOW(), NOW()),

  ('admin.crud.flights.form_new', 'en', 'New flight', NOW(), NOW()),
  ('admin.crud.flights.form_new', 'hy', 'Նոր թռիչք', NOW(), NOW()),
  ('admin.crud.flights.form_new', 'ru', 'Новый рейс', NOW(), NOW()),

  -- Sections
  ('admin.crud.flights.section.general', 'en', '1) General', NOW(), NOW()),
  ('admin.crud.flights.section.general', 'hy', '1) Ընդհանուր', NOW(), NOW()),
  ('admin.crud.flights.section.general', 'ru', '1) Общее', NOW(), NOW()),

  ('admin.crud.flights.section.departure', 'en', '2) Departure', NOW(), NOW()),
  ('admin.crud.flights.section.departure', 'hy', '2) Մեկնում', NOW(), NOW()),
  ('admin.crud.flights.section.departure', 'ru', '2) Отправление', NOW(), NOW()),

  ('admin.crud.flights.section.arrival', 'en', '3) Arrival', NOW(), NOW()),
  ('admin.crud.flights.section.arrival', 'hy', '3) Ժամանում', NOW(), NOW()),
  ('admin.crud.flights.section.arrival', 'ru', '3) Прибытие', NOW(), NOW()),

  ('admin.crud.flights.section.schedule', 'en', '4) Schedule & route', NOW(), NOW()),
  ('admin.crud.flights.section.schedule', 'hy', '4) Ժամանակացույց և երթուղի', NOW(), NOW()),
  ('admin.crud.flights.section.schedule', 'ru', '4) Расписание и маршрут', NOW(), NOW()),

  ('admin.crud.flights.section.ages', 'en', '5) Passenger age tiers', NOW(), NOW()),
  ('admin.crud.flights.section.ages', 'hy', '5) Ուղևորների տարիքային խմբեր', NOW(), NOW()),
  ('admin.crud.flights.section.ages', 'ru', '5) Возрастные категории пассажиров', NOW(), NOW()),

  ('admin.crud.flights.section.cabins', 'en', '6) Cabin classes & pricing', NOW(), NOW()),
  ('admin.crud.flights.section.cabins', 'hy', '6) Կաբինի դասերը և գին', NOW(), NOW()),
  ('admin.crud.flights.section.cabins', 'ru', '6) Классы салона и цены', NOW(), NOW()),

  ('admin.crud.flights.section.policies', 'en', '7) Booking policies', NOW(), NOW()),
  ('admin.crud.flights.section.policies', 'hy', '7) Ամրագրման կանոններ', NOW(), NOW()),
  ('admin.crud.flights.section.policies', 'ru', '7) Правила бронирования', NOW(), NOW()),

  ('admin.crud.flights.section.visibility', 'en', '8) Visibility & lifecycle', NOW(), NOW()),
  ('admin.crud.flights.section.visibility', 'hy', '8) Տեսանելիություն և կյանքի ցիկլ', NOW(), NOW()),
  ('admin.crud.flights.section.visibility', 'ru', '8) Видимость и жизненный цикл', NOW(), NOW()),

  -- Section 1: General fields
  ('admin.crud.flights.field.offer_id', 'en', 'Offer ID', NOW(), NOW()),
  ('admin.crud.flights.field.offer_id', 'hy', 'Առաջարկի ID', NOW(), NOW()),
  ('admin.crud.flights.field.offer_id', 'ru', 'ID предложения', NOW(), NOW()),

  ('admin.crud.flights.field.offer_id_hint', 'en', 'Must be an existing offer of type=flight', NOW(), NOW()),
  ('admin.crud.flights.field.offer_id_hint', 'hy', 'Պետք է լինի առկա առաջարկ՝ type=flight տեսակի', NOW(), NOW()),
  ('admin.crud.flights.field.offer_id_hint', 'ru', 'Должно быть существующее предложение типа flight', NOW(), NOW()),

  ('admin.crud.flights.field.flight_code', 'en', 'Flight code (internal)', NOW(), NOW()),
  ('admin.crud.flights.field.flight_code', 'hy', 'Թռիչքի կոդ (ներքին)', NOW(), NOW()),
  ('admin.crud.flights.field.flight_code', 'ru', 'Код рейса (внутренний)', NOW(), NOW()),

  ('admin.crud.flights.field.flight_code_hint', 'en', 'e.g. SU1869 / W6361', NOW(), NOW()),
  ('admin.crud.flights.field.flight_code_hint', 'hy', 'օր. SU1869 / W6361', NOW(), NOW()),
  ('admin.crud.flights.field.flight_code_hint', 'ru', 'напр. SU1869 / W6361', NOW(), NOW()),

  ('admin.crud.flights.field.service_type', 'en', 'Service type', NOW(), NOW()),
  ('admin.crud.flights.field.service_type', 'hy', 'Ծառայության տեսակ', NOW(), NOW()),
  ('admin.crud.flights.field.service_type', 'ru', 'Тип услуги', NOW(), NOW()),

  ('admin.crud.flights.field.lifecycle_status', 'en', 'Lifecycle status', NOW(), NOW()),
  ('admin.crud.flights.field.lifecycle_status', 'hy', 'Կյանքի ցիկլի կարգավիճակ', NOW(), NOW()),
  ('admin.crud.flights.field.lifecycle_status', 'ru', 'Статус жизненного цикла', NOW(), NOW()),

  ('admin.crud.flights.field.main_image', 'en', 'Main image', NOW(), NOW()),
  ('admin.crud.flights.field.main_image', 'hy', 'Հիմնական նկար', NOW(), NOW()),
  ('admin.crud.flights.field.main_image', 'ru', 'Главное изображение', NOW(), NOW()),

  ('admin.crud.flights.main_image_alt', 'en', 'Flight preview', NOW(), NOW()),
  ('admin.crud.flights.main_image_alt', 'hy', 'Թռիչքի նախադիտում', NOW(), NOW()),
  ('admin.crud.flights.main_image_alt', 'ru', 'Превью рейса', NOW(), NOW()),

  ('admin.crud.flights.field.short_description', 'en', 'Short description', NOW(), NOW()),
  ('admin.crud.flights.field.short_description', 'hy', 'Կարճ նկարագրություն', NOW(), NOW()),
  ('admin.crud.flights.field.short_description', 'ru', 'Краткое описание', NOW(), NOW()),

  ('admin.crud.flights.field.short_description_hint', 'en', '(short description about the flight — shown on the card / detail page)', NOW(), NOW()),
  ('admin.crud.flights.field.short_description_hint', 'hy', '(կարճ նկարագրություն թռիչքի մասին՝ ցույց է տրվում քարտի / մանրամասների էջում)', NOW(), NOW()),
  ('admin.crud.flights.field.short_description_hint', 'ru', '(краткое описание рейса — отображается на карточке / странице деталей)', NOW(), NOW()),

  ('admin.crud.flights.field.short_description_placeholder', 'en', 'Direct flight from Yerevan to Moscow, Aeroflot operated.', NOW(), NOW()),
  ('admin.crud.flights.field.short_description_placeholder', 'hy', 'Օրինակ՝ Ուղիղ թռիչք Երևանից Մոսկվա, Aeroflot ընկերության կողմից։', NOW(), NOW()),
  ('admin.crud.flights.field.short_description_placeholder', 'ru', 'Например: Прямой рейс из Еревана в Москву, оператор Aeroflot.', NOW(), NOW()),

  -- Section 2-3: Departure + Arrival fields
  ('admin.crud.flights.field.departure_location', 'en', 'Departure location (Country → Region → City) ★', NOW(), NOW()),
  ('admin.crud.flights.field.departure_location', 'hy', 'Մեկնման վայր (Երկիր → Շրջան → Քաղաք) ★', NOW(), NOW()),
  ('admin.crud.flights.field.departure_location', 'ru', 'Место отправления (Страна → Регион → Город) ★', NOW(), NOW()),

  ('admin.crud.flights.field.departure_airport', 'en', 'Departure airport', NOW(), NOW()),
  ('admin.crud.flights.field.departure_airport', 'hy', 'Մեկնման օդանավակայան', NOW(), NOW()),
  ('admin.crud.flights.field.departure_airport', 'ru', 'Аэропорт отправления', NOW(), NOW()),

  ('admin.crud.flights.field.departure_airport_hint', 'en', 'e.g. Zvartnots International Airport', NOW(), NOW()),
  ('admin.crud.flights.field.departure_airport_hint', 'hy', 'օր. Զվարթնոց միջազգային օդանավակայան', NOW(), NOW()),
  ('admin.crud.flights.field.departure_airport_hint', 'ru', 'напр. Международный аэропорт Звартноц', NOW(), NOW()),

  ('admin.crud.flights.field.airport_iata', 'en', 'Airport IATA code', NOW(), NOW()),
  ('admin.crud.flights.field.airport_iata', 'hy', 'IATA կոդ', NOW(), NOW()),
  ('admin.crud.flights.field.airport_iata', 'ru', 'Код IATA', NOW(), NOW()),

  ('admin.crud.flights.field.airport_iata_hint', 'en', 'e.g. EVN, SVO, DXB', NOW(), NOW()),
  ('admin.crud.flights.field.airport_iata_hint', 'hy', 'օր. EVN, SVO, DXB', NOW(), NOW()),
  ('admin.crud.flights.field.airport_iata_hint', 'ru', 'напр. EVN, SVO, DXB', NOW(), NOW()),

  ('admin.crud.flights.field.departure_terminal', 'en', 'Departure terminal', NOW(), NOW()),
  ('admin.crud.flights.field.departure_terminal', 'hy', 'Մեկնման տերմինալ', NOW(), NOW()),
  ('admin.crud.flights.field.departure_terminal', 'ru', 'Терминал отправления', NOW(), NOW()),

  ('admin.crud.flights.field.departure_city_override', 'en', 'Departure city (override)', NOW(), NOW()),
  ('admin.crud.flights.field.departure_city_override', 'hy', 'Մեկնման քաղաք (override)', NOW(), NOW()),
  ('admin.crud.flights.field.departure_city_override', 'ru', 'Город отправления (override)', NOW(), NOW()),

  ('admin.crud.flights.field.departure_country_override', 'en', 'Departure country (override)', NOW(), NOW()),
  ('admin.crud.flights.field.departure_country_override', 'hy', 'Մեկնման երկիր (override)', NOW(), NOW()),
  ('admin.crud.flights.field.departure_country_override', 'ru', 'Страна отправления (override)', NOW(), NOW()),

  ('admin.crud.flights.field.arrival_location', 'en', 'Arrival location (Country → Region → City) ★', NOW(), NOW()),
  ('admin.crud.flights.field.arrival_location', 'hy', 'Ժամանման վայր (Երկիր → Շրջան → Քաղաք) ★', NOW(), NOW()),
  ('admin.crud.flights.field.arrival_location', 'ru', 'Место прибытия (Страна → Регион → Город) ★', NOW(), NOW()),

  ('admin.crud.flights.field.arrival_airport', 'en', 'Arrival airport', NOW(), NOW()),
  ('admin.crud.flights.field.arrival_airport', 'hy', 'Ժամանման օդանավակայան', NOW(), NOW()),
  ('admin.crud.flights.field.arrival_airport', 'ru', 'Аэропорт прибытия', NOW(), NOW()),

  ('admin.crud.flights.field.arrival_terminal', 'en', 'Arrival terminal', NOW(), NOW()),
  ('admin.crud.flights.field.arrival_terminal', 'hy', 'Ժամանման տերմինալ', NOW(), NOW()),
  ('admin.crud.flights.field.arrival_terminal', 'ru', 'Терминал прибытия', NOW(), NOW()),

  ('admin.crud.flights.field.arrival_city_override', 'en', 'Arrival city (override)', NOW(), NOW()),
  ('admin.crud.flights.field.arrival_city_override', 'hy', 'Ժամանման քաղաք (override)', NOW(), NOW()),
  ('admin.crud.flights.field.arrival_city_override', 'ru', 'Город прибытия (override)', NOW(), NOW()),

  ('admin.crud.flights.field.arrival_country_override', 'en', 'Arrival country (override)', NOW(), NOW()),
  ('admin.crud.flights.field.arrival_country_override', 'hy', 'Ժամանման երկիր (override)', NOW(), NOW()),
  ('admin.crud.flights.field.arrival_country_override', 'ru', 'Страна прибытия (override)', NOW(), NOW()),

  -- Section 4: Schedule
  ('admin.crud.flights.field.departure_at', 'en', 'Departure at', NOW(), NOW()),
  ('admin.crud.flights.field.departure_at', 'hy', 'Մեկնման ժամ', NOW(), NOW()),
  ('admin.crud.flights.field.departure_at', 'ru', 'Время отправления', NOW(), NOW()),

  ('admin.crud.flights.field.arrival_at', 'en', 'Arrival at', NOW(), NOW()),
  ('admin.crud.flights.field.arrival_at', 'hy', 'Ժամանման ժամ', NOW(), NOW()),
  ('admin.crud.flights.field.arrival_at', 'ru', 'Время прибытия', NOW(), NOW()),

  ('admin.crud.flights.field.duration_minutes', 'en', 'Duration (minutes)', NOW(), NOW()),
  ('admin.crud.flights.field.duration_minutes', 'hy', 'Տևողություն (րոպե)', NOW(), NOW()),
  ('admin.crud.flights.field.duration_minutes', 'ru', 'Длительность (минуты)', NOW(), NOW()),

  ('admin.crud.flights.field.timezone_context', 'en', 'Timezone context', NOW(), NOW()),
  ('admin.crud.flights.field.timezone_context', 'hy', 'Ժամային գոտի', NOW(), NOW()),
  ('admin.crud.flights.field.timezone_context', 'ru', 'Часовой пояс', NOW(), NOW()),

  ('admin.crud.flights.field.timezone_context_hint', 'en', 'e.g. Europe/Moscow → useful for displaying arrival in local time', NOW(), NOW()),
  ('admin.crud.flights.field.timezone_context_hint', 'hy', 'օր. Europe/Moscow → օգտակար՝ ժամանումը տեղական ժամանակով ցույց տալու համար', NOW(), NOW()),
  ('admin.crud.flights.field.timezone_context_hint', 'ru', 'напр. Europe/Moscow → полезно для отображения времени прибытия локально', NOW(), NOW()),

  ('admin.crud.flights.field.check_in_close_at', 'en', 'Check-in closes at', NOW(), NOW()),
  ('admin.crud.flights.field.check_in_close_at', 'hy', 'Գրանցումը փակվում է', NOW(), NOW()),
  ('admin.crud.flights.field.check_in_close_at', 'ru', 'Регистрация закрывается', NOW(), NOW()),

  ('admin.crud.flights.field.boarding_close_at', 'en', 'Boarding closes at', NOW(), NOW()),
  ('admin.crud.flights.field.boarding_close_at', 'hy', 'Նստումը փակվում է', NOW(), NOW()),
  ('admin.crud.flights.field.boarding_close_at', 'ru', 'Посадка закрывается', NOW(), NOW()),

  ('admin.crud.flights.field.connection_type', 'en', 'Connection type', NOW(), NOW()),
  ('admin.crud.flights.field.connection_type', 'hy', 'Միացման տեսակ', NOW(), NOW()),
  ('admin.crud.flights.field.connection_type', 'ru', 'Тип соединения', NOW(), NOW()),

  ('admin.crud.flights.field.stops_count', 'en', 'Stops count', NOW(), NOW()),
  ('admin.crud.flights.field.stops_count', 'hy', 'Կանգառների քանակ', NOW(), NOW()),
  ('admin.crud.flights.field.stops_count', 'ru', 'Количество остановок', NOW(), NOW()),

  ('admin.crud.flights.field.connection_notes', 'en', 'Connection notes', NOW(), NOW()),
  ('admin.crud.flights.field.connection_notes', 'hy', 'Միացման ծանոթագրություններ', NOW(), NOW()),
  ('admin.crud.flights.field.connection_notes', 'ru', 'Примечания о соединениях', NOW(), NOW()),

  ('admin.crud.flights.field.layover_summary', 'en', 'Layover summary', NOW(), NOW()),
  ('admin.crud.flights.field.layover_summary', 'hy', 'Կանգառի ամփոփում', NOW(), NOW()),
  ('admin.crud.flights.field.layover_summary', 'ru', 'Сводка по пересадке', NOW(), NOW()),

  -- Section 5: Ages
  ('admin.crud.flights.field.adult_age_from', 'en', 'Adult age from', NOW(), NOW()),
  ('admin.crud.flights.field.adult_age_from', 'hy', 'Մեծահասակի տարիքից', NOW(), NOW()),
  ('admin.crud.flights.field.adult_age_from', 'ru', 'Возраст взрослого от', NOW(), NOW()),

  ('admin.crud.flights.field.child_age_from', 'en', 'Child age from', NOW(), NOW()),
  ('admin.crud.flights.field.child_age_from', 'hy', 'Երեխայի տարիքից', NOW(), NOW()),
  ('admin.crud.flights.field.child_age_from', 'ru', 'Возраст ребёнка от', NOW(), NOW()),

  ('admin.crud.flights.field.child_age_to', 'en', 'Child age to', NOW(), NOW()),
  ('admin.crud.flights.field.child_age_to', 'hy', 'Երեխայի տարիքը մինչև', NOW(), NOW()),
  ('admin.crud.flights.field.child_age_to', 'ru', 'Возраст ребёнка до', NOW(), NOW()),

  ('admin.crud.flights.field.infant_age_from', 'en', 'Infant age from', NOW(), NOW()),
  ('admin.crud.flights.field.infant_age_from', 'hy', 'Նորածնի տարիքից', NOW(), NOW()),
  ('admin.crud.flights.field.infant_age_from', 'ru', 'Возраст младенца от', NOW(), NOW()),

  ('admin.crud.flights.field.infant_age_to', 'en', 'Infant age to', NOW(), NOW()),
  ('admin.crud.flights.field.infant_age_to', 'hy', 'Նորածնի տարիքը մինչև', NOW(), NOW()),
  ('admin.crud.flights.field.infant_age_to', 'ru', 'Возраст младенца до', NOW(), NOW()),

  -- Section 6: Cabins
  ('admin.crud.flights.cabins_intro', 'en', 'Add one row per cabin class (Economy, Business, …). The first row is the primary cabin and is shown as the default price on the card.', NOW(), NOW()),
  ('admin.crud.flights.cabins_intro', 'hy', 'Ավելացրու մեկ տող յուրաքանչյուր կաբինի դասի համար (Էկոնոմ, Բիզնես, …)։ Առաջին տողը հիմնական կաբինն է, որի գինը երևում է քարտի վրա։', NOW(), NOW()),
  ('admin.crud.flights.cabins_intro', 'ru', 'Добавьте по одной строке на каждый класс салона (Эконом, Бизнес, …). Первая строка — основной салон, его цена отображается на карточке.', NOW(), NOW()),

  ('admin.crud.flights.cabin_n', 'en', 'Cabin #{n}', NOW(), NOW()),
  ('admin.crud.flights.cabin_n', 'hy', 'Կաբին #{n}', NOW(), NOW()),
  ('admin.crud.flights.cabin_n', 'ru', 'Салон #{n}', NOW(), NOW()),

  ('admin.crud.flights.cabin_primary', 'en', 'primary', NOW(), NOW()),
  ('admin.crud.flights.cabin_primary', 'hy', 'հիմնական', NOW(), NOW()),
  ('admin.crud.flights.cabin_primary', 'ru', 'основной', NOW(), NOW()),

  ('admin.crud.flights.field.cabin_class', 'en', 'Cabin class', NOW(), NOW()),
  ('admin.crud.flights.field.cabin_class', 'hy', 'Կաբինի դաս', NOW(), NOW()),
  ('admin.crud.flights.field.cabin_class', 'ru', 'Класс салона', NOW(), NOW()),

  ('admin.crud.flights.field.seats_total', 'en', 'Seats total', NOW(), NOW()),
  ('admin.crud.flights.field.seats_total', 'hy', 'Տեղեր՝ ընդհանուր', NOW(), NOW()),
  ('admin.crud.flights.field.seats_total', 'ru', 'Мест всего', NOW(), NOW()),

  ('admin.crud.flights.field.seats_available', 'en', 'Seats available', NOW(), NOW()),
  ('admin.crud.flights.field.seats_available', 'hy', 'Տեղեր՝ ազատ', NOW(), NOW()),
  ('admin.crud.flights.field.seats_available', 'ru', 'Мест свободно', NOW(), NOW()),

  ('admin.crud.flights.field.adult_price', 'en', 'Adult price (USD)', NOW(), NOW()),
  ('admin.crud.flights.field.adult_price', 'hy', 'Մեծահասակի գին (USD)', NOW(), NOW()),
  ('admin.crud.flights.field.adult_price', 'ru', 'Цена взрослого (USD)', NOW(), NOW()),

  ('admin.crud.flights.field.child_price', 'en', 'Child price (USD)', NOW(), NOW()),
  ('admin.crud.flights.field.child_price', 'hy', 'Երեխայի գին (USD)', NOW(), NOW()),
  ('admin.crud.flights.field.child_price', 'ru', 'Цена ребёнка (USD)', NOW(), NOW()),

  ('admin.crud.flights.field.infant_price', 'en', 'Infant price (USD)', NOW(), NOW()),
  ('admin.crud.flights.field.infant_price', 'hy', 'Նորածնի գին (USD)', NOW(), NOW()),
  ('admin.crud.flights.field.infant_price', 'ru', 'Цена младенца (USD)', NOW(), NOW()),

  ('admin.crud.flights.field.fare_family', 'en', 'Fare family', NOW(), NOW()),
  ('admin.crud.flights.field.fare_family', 'hy', 'Սակագնային խումբ', NOW(), NOW()),
  ('admin.crud.flights.field.fare_family', 'ru', 'Тарифная группа', NOW(), NOW()),

  ('admin.crud.flights.field.fare_family_placeholder', 'en', 'e.g. Lite, Standard, Flex', NOW(), NOW()),
  ('admin.crud.flights.field.fare_family_placeholder', 'hy', 'օր. Lite, Standard, Flex', NOW(), NOW()),
  ('admin.crud.flights.field.fare_family_placeholder', 'ru', 'напр. Lite, Standard, Flex', NOW(), NOW()),

  ('admin.crud.flights.field.hand_baggage_weight', 'en', 'Hand baggage weight', NOW(), NOW()),
  ('admin.crud.flights.field.hand_baggage_weight', 'hy', 'Ձեռքի բեռի քաշ', NOW(), NOW()),
  ('admin.crud.flights.field.hand_baggage_weight', 'ru', 'Вес ручной клади', NOW(), NOW()),

  ('admin.crud.flights.field.hand_baggage_weight_placeholder', 'en', 'e.g. 8kg', NOW(), NOW()),
  ('admin.crud.flights.field.hand_baggage_weight_placeholder', 'hy', 'օր. 8կգ', NOW(), NOW()),
  ('admin.crud.flights.field.hand_baggage_weight_placeholder', 'ru', 'напр. 8кг', NOW(), NOW()),

  ('admin.crud.flights.field.checked_baggage_weight', 'en', 'Checked baggage weight', NOW(), NOW()),
  ('admin.crud.flights.field.checked_baggage_weight', 'hy', 'Հանձնված բեռի քաշ', NOW(), NOW()),
  ('admin.crud.flights.field.checked_baggage_weight', 'ru', 'Вес зарегистрированного багажа', NOW(), NOW()),

  ('admin.crud.flights.field.checked_baggage_weight_placeholder', 'en', 'e.g. 23kg', NOW(), NOW()),
  ('admin.crud.flights.field.checked_baggage_weight_placeholder', 'hy', 'օր. 23կգ', NOW(), NOW()),
  ('admin.crud.flights.field.checked_baggage_weight_placeholder', 'ru', 'напр. 23кг', NOW(), NOW()),

  ('admin.crud.flights.cabin.hand_baggage_included', 'en', 'Hand baggage included', NOW(), NOW()),
  ('admin.crud.flights.cabin.hand_baggage_included', 'hy', 'Ձեռքի բեռը ներառված է', NOW(), NOW()),
  ('admin.crud.flights.cabin.hand_baggage_included', 'ru', 'Ручная кладь включена', NOW(), NOW()),

  ('admin.crud.flights.cabin.checked_baggage_included', 'en', 'Checked baggage included', NOW(), NOW()),
  ('admin.crud.flights.cabin.checked_baggage_included', 'hy', 'Հանձնված բեռը ներառված է', NOW(), NOW()),
  ('admin.crud.flights.cabin.checked_baggage_included', 'ru', 'Зарегистрированный багаж включён', NOW(), NOW()),

  ('admin.crud.flights.cabin.extra_baggage_allowed', 'en', 'Extra baggage allowed', NOW(), NOW()),
  ('admin.crud.flights.cabin.extra_baggage_allowed', 'hy', 'Թույլատրված է հավելյալ բեռ', NOW(), NOW()),
  ('admin.crud.flights.cabin.extra_baggage_allowed', 'ru', 'Разрешён дополнительный багаж', NOW(), NOW()),

  ('admin.crud.flights.cabin.seat_map_available', 'en', 'Seat map available', NOW(), NOW()),
  ('admin.crud.flights.cabin.seat_map_available', 'hy', 'Տեղերի քարտեզը հասանելի է', NOW(), NOW()),
  ('admin.crud.flights.cabin.seat_map_available', 'ru', 'Карта мест доступна', NOW(), NOW()),

  ('admin.crud.flights.add_cabin', 'en', '+ Add cabin class', NOW(), NOW()),
  ('admin.crud.flights.add_cabin', 'hy', '+ Ավելացնել կաբինի դաս', NOW(), NOW()),
  ('admin.crud.flights.add_cabin', 'ru', '+ Добавить класс салона', NOW(), NOW()),

  -- Section 7: Policies
  ('admin.crud.flights.field.cancellation_policy', 'en', 'Cancellation policy', NOW(), NOW()),
  ('admin.crud.flights.field.cancellation_policy', 'hy', 'Չեղարկման կանոն', NOW(), NOW()),
  ('admin.crud.flights.field.cancellation_policy', 'ru', 'Правило отмены', NOW(), NOW()),

  ('admin.crud.flights.field.change_policy', 'en', 'Change policy', NOW(), NOW()),
  ('admin.crud.flights.field.change_policy', 'hy', 'Փոփոխման կանոն', NOW(), NOW()),
  ('admin.crud.flights.field.change_policy', 'ru', 'Правило изменения', NOW(), NOW()),

  ('admin.crud.flights.field.reservation_deadline', 'en', 'Reservation deadline', NOW(), NOW()),
  ('admin.crud.flights.field.reservation_deadline', 'hy', 'Ամրագրման վերջնաժամկետ', NOW(), NOW()),
  ('admin.crud.flights.field.reservation_deadline', 'ru', 'Крайний срок бронирования', NOW(), NOW()),

  ('admin.crud.flights.field.cancellation_deadline', 'en', 'Cancellation deadline', NOW(), NOW()),
  ('admin.crud.flights.field.cancellation_deadline', 'hy', 'Չեղարկման վերջնաժամկետ', NOW(), NOW()),
  ('admin.crud.flights.field.cancellation_deadline', 'ru', 'Крайний срок отмены', NOW(), NOW()),

  ('admin.crud.flights.field.change_deadline', 'en', 'Change deadline', NOW(), NOW()),
  ('admin.crud.flights.field.change_deadline', 'hy', 'Փոփոխման վերջնաժամկետ', NOW(), NOW()),
  ('admin.crud.flights.field.change_deadline', 'ru', 'Крайний срок изменения', NOW(), NOW()),

  ('admin.crud.flights.field.policy_notes', 'en', 'Policy notes', NOW(), NOW()),
  ('admin.crud.flights.field.policy_notes', 'hy', 'Կանոնների ծանոթագրություն', NOW(), NOW()),
  ('admin.crud.flights.field.policy_notes', 'ru', 'Примечания к правилам', NOW(), NOW()),

  ('admin.crud.flights.policy.reservation_allowed', 'en', 'Reservation allowed', NOW(), NOW()),
  ('admin.crud.flights.policy.reservation_allowed', 'hy', 'Ամրագրումը թույլատրված է', NOW(), NOW()),
  ('admin.crud.flights.policy.reservation_allowed', 'ru', 'Бронирование разрешено', NOW(), NOW()),

  ('admin.crud.flights.policy.online_checkin_allowed', 'en', 'Online check-in allowed', NOW(), NOW()),
  ('admin.crud.flights.policy.online_checkin_allowed', 'hy', 'Օնլայն գրանցումը թույլատրված է', NOW(), NOW()),
  ('admin.crud.flights.policy.online_checkin_allowed', 'ru', 'Онлайн-регистрация разрешена', NOW(), NOW()),

  ('admin.crud.flights.policy.airport_checkin_allowed', 'en', 'Airport check-in allowed', NOW(), NOW()),
  ('admin.crud.flights.policy.airport_checkin_allowed', 'hy', 'Օդանավակայանում գրանցումը թույլատրված է', NOW(), NOW()),
  ('admin.crud.flights.policy.airport_checkin_allowed', 'ru', 'Регистрация в аэропорту разрешена', NOW(), NOW()),

  -- Section 8: Visibility
  ('admin.crud.flights.visibility.package_eligible', 'en', 'Eligible for use inside packages', NOW(), NOW()),
  ('admin.crud.flights.visibility.package_eligible', 'hy', 'Թույլատրված է փաթեթներում օգտագործելու համար', NOW(), NOW()),
  ('admin.crud.flights.visibility.package_eligible', 'ru', 'Разрешено для использования в пакетах', NOW(), NOW()),

  ('admin.crud.flights.visibility.appears_in_web', 'en', 'Appears on public website (zulu.am)', NOW(), NOW()),
  ('admin.crud.flights.visibility.appears_in_web', 'hy', 'Երևում է հանրային կայքում (zulu.am)', NOW(), NOW()),
  ('admin.crud.flights.visibility.appears_in_web', 'ru', 'Отображается на публичном сайте (zulu.am)', NOW(), NOW()),

  ('admin.crud.flights.visibility.appears_in_admin', 'en', 'Appears in operator admin lists', NOW(), NOW()),
  ('admin.crud.flights.visibility.appears_in_admin', 'hy', 'Երևում է օպերատորի ադմինի ցուցակներում', NOW(), NOW()),
  ('admin.crud.flights.visibility.appears_in_admin', 'ru', 'Отображается в списках админа оператора', NOW(), NOW()),

  ('admin.crud.flights.visibility.appears_in_zulu_admin', 'en', 'Appears in ZULU super-admin lists', NOW(), NOW()),
  ('admin.crud.flights.visibility.appears_in_zulu_admin', 'hy', 'Երևում է ZULU գերադմինի ցուցակներում', NOW(), NOW()),
  ('admin.crud.flights.visibility.appears_in_zulu_admin', 'ru', 'Отображается в списках супер-админа ZULU', NOW(), NOW()),

  -- Translations + table
  ('admin.crud.flights.translations_title', 'en', 'Translations', NOW(), NOW()),
  ('admin.crud.flights.translations_title', 'hy', 'Թարգմանություններ', NOW(), NOW()),
  ('admin.crud.flights.translations_title', 'ru', 'Переводы', NOW(), NOW()),

  ('admin.crud.flights.translations_hint', 'en', '(beyond EN: RU / HY)', NOW(), NOW()),
  ('admin.crud.flights.translations_hint', 'hy', '(EN-ից բացի՝ RU / HY)', NOW(), NOW()),
  ('admin.crud.flights.translations_hint', 'ru', '(кроме EN: RU / HY)', NOW(), NOW()),

  ('admin.crud.flights.field.title', 'en', 'Title', NOW(), NOW()),
  ('admin.crud.flights.field.title', 'hy', 'Վերնագիր', NOW(), NOW()),
  ('admin.crud.flights.field.title', 'ru', 'Заголовок', NOW(), NOW()),

  ('admin.crud.flights.field.description', 'en', 'Description', NOW(), NOW()),
  ('admin.crud.flights.field.description', 'hy', 'Նկարագրություն', NOW(), NOW()),
  ('admin.crud.flights.field.description', 'ru', 'Описание', NOW(), NOW()),

  ('admin.crud.flights.col.code', 'en', 'Code', NOW(), NOW()),
  ('admin.crud.flights.col.code', 'hy', 'Կոդ', NOW(), NOW()),
  ('admin.crud.flights.col.code', 'ru', 'Код', NOW(), NOW()),

  ('admin.crud.flights.col.route', 'en', 'Route', NOW(), NOW()),
  ('admin.crud.flights.col.route', 'hy', 'Երթուղի', NOW(), NOW()),
  ('admin.crud.flights.col.route', 'ru', 'Маршрут', NOW(), NOW()),

  ('admin.crud.flights.col.departure', 'en', 'Departure', NOW(), NOW()),
  ('admin.crud.flights.col.departure', 'hy', 'Մեկնում', NOW(), NOW()),
  ('admin.crud.flights.col.departure', 'ru', 'Отправление', NOW(), NOW()),

  ('admin.crud.flights.col.review', 'en', 'Review', NOW(), NOW()),
  ('admin.crud.flights.col.review', 'hy', 'Վերանայում', NOW(), NOW()),
  ('admin.crud.flights.col.review', 'ru', 'Проверка', NOW(), NOW()),

  ('admin.crud.flights.empty', 'en', 'No flights yet.', NOW(), NOW()),
  ('admin.crud.flights.empty', 'hy', 'Դեռ թռիչքներ չկան։', NOW(), NOW()),
  ('admin.crud.flights.empty', 'ru', 'Рейсов пока нет.', NOW(), NOW())

ON CONFLICT (key, language_code) DO UPDATE
  SET value = EXCLUDED.value,
      updated_at = NOW();
