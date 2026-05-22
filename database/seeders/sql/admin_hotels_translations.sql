SET client_encoding = 'UTF8';

-- Phase 1.3 — /operator/hotels page translations + character encoding fixes
-- Source: docs/roadmaps/admin-audit-roadmap-2026-05-22.md
-- Page already had ~56 t() calls; this adds the remaining ~40 strings.

INSERT INTO ui_translations (key, language_code, value, created_at, updated_at) VALUES
  -- Common fallback (shared across multiple pages)
  ('admin.crud.common.failed', 'en', 'Failed', NOW(), NOW()),
  ('admin.crud.common.failed', 'hy', 'Ձախողվեց', NOW(), NOW()),
  ('admin.crud.common.failed', 'ru', 'Не удалось', NOW(), NOW()),

  ('admin.crud.common.status', 'en', 'Status', NOW(), NOW()),
  ('admin.crud.common.status', 'hy', 'Կարգավիճակ', NOW(), NOW()),
  ('admin.crud.common.status', 'ru', 'Статус', NOW(), NOW()),

  ('admin.crud.common.submit_for_review', 'en', 'Submit for review', NOW(), NOW()),
  ('admin.crud.common.submit_for_review', 'hy', 'Ուղարկել վերանայման', NOW(), NOW()),
  ('admin.crud.common.submit_for_review', 'ru', 'Отправить на проверку', NOW(), NOW()),

  -- Hotel-specific errors
  ('admin.crud.hotels.err_load_one', 'en', 'Failed to load hotel', NOW(), NOW()),
  ('admin.crud.hotels.err_load_one', 'hy', 'Չհաջողվեց բեռնել հյուրանոցը', NOW(), NOW()),
  ('admin.crud.hotels.err_load_one', 'ru', 'Не удалось загрузить отель', NOW(), NOW()),

  ('admin.crud.hotels.err_submit_failed', 'en', 'Submit failed.', NOW(), NOW()),
  ('admin.crud.hotels.err_submit_failed', 'hy', 'Ուղարկումը ձախողվեց։', NOW(), NOW()),
  ('admin.crud.hotels.err_submit_failed', 'ru', 'Отправка не удалась.', NOW(), NOW()),

  ('admin.crud.hotels.err_template_failed', 'en', 'Template download failed', NOW(), NOW()),
  ('admin.crud.hotels.err_template_failed', 'hy', 'Կաղապարի ներբեռնումը ձախողվեց', NOW(), NOW()),
  ('admin.crud.hotels.err_template_failed', 'ru', 'Не удалось скачать шаблон', NOW(), NOW()),

  ('admin.crud.hotels.err_export_failed', 'en', 'Export failed', NOW(), NOW()),
  ('admin.crud.hotels.err_export_failed', 'hy', 'Արտահանումը ձախողվեց', NOW(), NOW()),
  ('admin.crud.hotels.err_export_failed', 'ru', 'Экспорт не удался', NOW(), NOW()),

  -- Form labels
  ('admin.crud.hotels.field.accommodation_type', 'en', 'Accommodation type', NOW(), NOW()),
  ('admin.crud.hotels.field.accommodation_type', 'hy', 'Բնակեցման տեսակ', NOW(), NOW()),
  ('admin.crud.hotels.field.accommodation_type', 'ru', 'Тип размещения', NOW(), NOW()),

  ('admin.crud.hotels.field.main_image', 'en', 'Main image', NOW(), NOW()),
  ('admin.crud.hotels.field.main_image', 'hy', 'Հիմնական նկար', NOW(), NOW()),
  ('admin.crud.hotels.field.main_image', 'ru', 'Главное изображение', NOW(), NOW()),

  ('admin.crud.hotels.main_image_alt', 'en', 'Hotel preview', NOW(), NOW()),
  ('admin.crud.hotels.main_image_alt', 'hy', 'Հյուրանոցի նախադիտում', NOW(), NOW()),
  ('admin.crud.hotels.main_image_alt', 'ru', 'Превью отеля', NOW(), NOW()),

  ('admin.crud.hotels.field.short_description', 'en', 'Short description', NOW(), NOW()),
  ('admin.crud.hotels.field.short_description', 'hy', 'Կարճ նկարագրություն', NOW(), NOW()),
  ('admin.crud.hotels.field.short_description', 'ru', 'Краткое описание', NOW(), NOW()),

  ('admin.crud.hotels.short_description_hint', 'en', '(short description on the hotel page — shows above "About the hotel" section)', NOW(), NOW()),
  ('admin.crud.hotels.short_description_hint', 'hy', '(կարճ նկարագրություն հյուրանոցի մասին՝ ցույց է տրվում "Հյուրանոցի մասին" բաժնում)', NOW(), NOW()),
  ('admin.crud.hotels.short_description_hint', 'ru', '(краткое описание отеля — отображается над разделом "Об отеле")', NOW(), NOW()),

  ('admin.crud.hotels.short_description_placeholder', 'en', 'Royal Manotel, Geneva is just 3.5 mi from the airport...', NOW(), NOW()),
  ('admin.crud.hotels.short_description_placeholder', 'hy', 'Օրինակ՝ Royal Manotel-ը Ժնևից 3.5 մղոն հեռու է...', NOW(), NOW()),
  ('admin.crud.hotels.short_description_placeholder', 'ru', 'Например: Royal Manotel в Женеве находится в 3.5 милях от аэропорта...', NOW(), NOW()),

  ('admin.crud.hotels.field.location_label', 'en', 'Location (Country → Region → City)', NOW(), NOW()),
  ('admin.crud.hotels.field.location_label', 'hy', 'Տեղադրությունը (Երկիր → Շրջան → Քաղաք)', NOW(), NOW()),
  ('admin.crud.hotels.field.location_label', 'ru', 'Местоположение (Страна → Регион → Город)', NOW(), NOW()),

  ('admin.crud.hotels.star_rating_hint', 'en', 'Optional (1–5) — API field', NOW(), NOW()),
  ('admin.crud.hotels.star_rating_hint', 'hy', 'Ընտրովի (1–5) — API դաշտ', NOW(), NOW()),
  ('admin.crud.hotels.star_rating_hint', 'ru', 'Необязательно (1–5) — поле API', NOW(), NOW()),

  -- Room form labels
  ('admin.crud.hotels.field.max_adults', 'en', 'Max adults', NOW(), NOW()),
  ('admin.crud.hotels.field.max_adults', 'hy', 'Մեծահասակների առավելագույն թիվ', NOW(), NOW()),
  ('admin.crud.hotels.field.max_adults', 'ru', 'Макс. взрослых', NOW(), NOW()),

  ('admin.crud.hotels.field.max_children', 'en', 'Max children', NOW(), NOW()),
  ('admin.crud.hotels.field.max_children', 'hy', 'Երեխաների առավելագույն թիվ', NOW(), NOW()),
  ('admin.crud.hotels.field.max_children', 'ru', 'Макс. детей', NOW(), NOW()),

  ('admin.crud.hotels.field.max_total_guests', 'en', 'Max total guests', NOW(), NOW()),
  ('admin.crud.hotels.field.max_total_guests', 'hy', 'Հյուրերի ընդհանուր առավելագույն թիվ', NOW(), NOW()),
  ('admin.crud.hotels.field.max_total_guests', 'ru', 'Макс. гостей всего', NOW(), NOW()),

  ('admin.crud.hotels.field.bed_type', 'en', 'Bed type', NOW(), NOW()),
  ('admin.crud.hotels.field.bed_type', 'hy', 'Մահճակալի տեսակ', NOW(), NOW()),
  ('admin.crud.hotels.field.bed_type', 'ru', 'Тип кровати', NOW(), NOW()),

  ('admin.crud.hotels.bed_type_placeholder', 'en', 'double / twin / king', NOW(), NOW()),
  ('admin.crud.hotels.bed_type_placeholder', 'hy', 'կրկնակի / թվին / քինգ', NOW(), NOW()),
  ('admin.crud.hotels.bed_type_placeholder', 'ru', 'двуспальная / твин / кинг', NOW(), NOW()),

  ('admin.crud.hotels.field.bed_count', 'en', 'Bed count', NOW(), NOW()),
  ('admin.crud.hotels.field.bed_count', 'hy', 'Մահճակալների քանակ', NOW(), NOW()),
  ('admin.crud.hotels.field.bed_count', 'ru', 'Кол-во кроватей', NOW(), NOW()),

  ('admin.crud.hotels.field.room_size', 'en', 'Room size (m²)', NOW(), NOW()),
  ('admin.crud.hotels.field.room_size', 'hy', 'Սենյակի մակերես (մ²)', NOW(), NOW()),
  ('admin.crud.hotels.field.room_size', 'ru', 'Размер комнаты (м²)', NOW(), NOW()),

  ('admin.crud.hotels.field.room_view', 'en', 'Room view', NOW(), NOW()),
  ('admin.crud.hotels.field.room_view', 'hy', 'Սենյակից տեսարան', NOW(), NOW()),
  ('admin.crud.hotels.field.room_view', 'ru', 'Вид из комнаты', NOW(), NOW()),

  ('admin.crud.hotels.field.view_type', 'en', 'View type', NOW(), NOW()),
  ('admin.crud.hotels.field.view_type', 'hy', 'Տեսարանի տեսակ', NOW(), NOW()),
  ('admin.crud.hotels.field.view_type', 'ru', 'Тип вида', NOW(), NOW()),

  ('admin.crud.hotels.view_type_placeholder', 'en', 'sea / mountain / city / garden', NOW(), NOW()),
  ('admin.crud.hotels.view_type_placeholder', 'hy', 'ծով / լեռ / քաղաք / այգի', NOW(), NOW()),
  ('admin.crud.hotels.view_type_placeholder', 'ru', 'море / горы / город / сад', NOW(), NOW()),

  ('admin.crud.hotels.field.inventory_count', 'en', 'Inventory count', NOW(), NOW()),
  ('admin.crud.hotels.field.inventory_count', 'hy', 'Հասանելի քանակ', NOW(), NOW()),
  ('admin.crud.hotels.field.inventory_count', 'ru', 'Доступное кол-во', NOW(), NOW()),

  ('admin.crud.hotels.room_status_placeholder', 'en', 'active / inactive', NOW(), NOW()),
  ('admin.crud.hotels.room_status_placeholder', 'hy', 'ակտիվ / ոչ ակտիվ', NOW(), NOW()),
  ('admin.crud.hotels.room_status_placeholder', 'ru', 'активный / неактивный', NOW(), NOW()),

  ('admin.crud.hotels.field.room_images', 'en', 'Room images (one URL per line)', NOW(), NOW()),
  ('admin.crud.hotels.field.room_images', 'hy', 'Սենյակի նկարներ (յուրաքանչյուր տողում մեկ URL)', NOW(), NOW()),
  ('admin.crud.hotels.field.room_images', 'ru', 'Изображения комнаты (по одному URL на строку)', NOW(), NOW()),

  -- Section headers
  ('admin.crud.hotels.section.bathroom', 'en', 'Bathroom', NOW(), NOW()),
  ('admin.crud.hotels.section.bathroom', 'hy', 'Լոգարան', NOW(), NOW()),
  ('admin.crud.hotels.section.bathroom', 'ru', 'Ванная', NOW(), NOW()),

  ('admin.crud.hotels.section.in_room_amenities', 'en', 'In-room amenities', NOW(), NOW()),
  ('admin.crud.hotels.section.in_room_amenities', 'hy', 'Սենյակի հարմարություններ', NOW(), NOW()),
  ('admin.crud.hotels.section.in_room_amenities', 'ru', 'Удобства в номере', NOW(), NOW()),

  ('admin.crud.hotels.section.policy', 'en', 'Policy', NOW(), NOW()),
  ('admin.crud.hotels.section.policy', 'hy', 'Կանոններ', NOW(), NOW()),
  ('admin.crud.hotels.section.policy', 'ru', 'Правила', NOW(), NOW()),

  -- Amenities
  ('admin.crud.hotels.amenity.private_bathroom', 'en', 'Private bathroom', NOW(), NOW()),
  ('admin.crud.hotels.amenity.private_bathroom', 'hy', 'Անձնական լոգարան', NOW(), NOW()),
  ('admin.crud.hotels.amenity.private_bathroom', 'ru', 'Отдельная ванная', NOW(), NOW()),

  ('admin.crud.hotels.amenity.bathtub', 'en', 'Bathtub', NOW(), NOW()),
  ('admin.crud.hotels.amenity.bathtub', 'hy', 'Լոգարան', NOW(), NOW()),
  ('admin.crud.hotels.amenity.bathtub', 'ru', 'Ванна', NOW(), NOW()),

  ('admin.crud.hotels.amenity.shower', 'en', 'Shower', NOW(), NOW()),
  ('admin.crud.hotels.amenity.shower', 'hy', 'Ցնցուղ', NOW(), NOW()),
  ('admin.crud.hotels.amenity.shower', 'ru', 'Душ', NOW(), NOW()),

  ('admin.crud.hotels.amenity.air_conditioning', 'en', 'Air conditioning', NOW(), NOW()),
  ('admin.crud.hotels.amenity.air_conditioning', 'hy', 'Օդորակիչ', NOW(), NOW()),
  ('admin.crud.hotels.amenity.air_conditioning', 'ru', 'Кондиционер', NOW(), NOW()),

  ('admin.crud.hotels.amenity.wifi', 'en', 'Wi-Fi', NOW(), NOW()),
  ('admin.crud.hotels.amenity.wifi', 'hy', 'Wi-Fi', NOW(), NOW()),
  ('admin.crud.hotels.amenity.wifi', 'ru', 'Wi-Fi', NOW(), NOW()),

  ('admin.crud.hotels.amenity.tv', 'en', 'TV', NOW(), NOW()),
  ('admin.crud.hotels.amenity.tv', 'hy', 'Հեռուստացույց', NOW(), NOW()),
  ('admin.crud.hotels.amenity.tv', 'ru', 'Телевизор', NOW(), NOW()),

  ('admin.crud.hotels.amenity.mini_fridge', 'en', 'Mini-fridge', NOW(), NOW()),
  ('admin.crud.hotels.amenity.mini_fridge', 'hy', 'Մինի-սառնարան', NOW(), NOW()),
  ('admin.crud.hotels.amenity.mini_fridge', 'ru', 'Мини-холодильник', NOW(), NOW()),

  ('admin.crud.hotels.amenity.tea_coffee_maker', 'en', 'Tea/coffee maker', NOW(), NOW()),
  ('admin.crud.hotels.amenity.tea_coffee_maker', 'hy', 'Թեյ/սուրճ պատրաստող', NOW(), NOW()),
  ('admin.crud.hotels.amenity.tea_coffee_maker', 'ru', 'Чайник/кофеварка', NOW(), NOW()),

  ('admin.crud.hotels.amenity.kettle', 'en', 'Kettle', NOW(), NOW()),
  ('admin.crud.hotels.amenity.kettle', 'hy', 'Թեյնիկ', NOW(), NOW()),
  ('admin.crud.hotels.amenity.kettle', 'ru', 'Чайник', NOW(), NOW()),

  ('admin.crud.hotels.amenity.washing_machine', 'en', 'Washing machine', NOW(), NOW()),
  ('admin.crud.hotels.amenity.washing_machine', 'hy', 'Լվացքի մեքենա', NOW(), NOW()),
  ('admin.crud.hotels.amenity.washing_machine', 'ru', 'Стиральная машина', NOW(), NOW()),

  ('admin.crud.hotels.amenity.soundproofing', 'en', 'Soundproofing', NOW(), NOW()),
  ('admin.crud.hotels.amenity.soundproofing', 'hy', 'Ձայնամեկուսացում', NOW(), NOW()),
  ('admin.crud.hotels.amenity.soundproofing', 'ru', 'Звукоизоляция', NOW(), NOW()),

  ('admin.crud.hotels.amenity.terrace_or_balcony', 'en', 'Terrace / balcony', NOW(), NOW()),
  ('admin.crud.hotels.amenity.terrace_or_balcony', 'hy', 'Տեռաս / պատշգամբ', NOW(), NOW()),
  ('admin.crud.hotels.amenity.terrace_or_balcony', 'ru', 'Терраса / балкон', NOW(), NOW()),

  ('admin.crud.hotels.amenity.patio', 'en', 'Patio', NOW(), NOW()),
  ('admin.crud.hotels.amenity.patio', 'hy', 'Բակային հարթակ', NOW(), NOW()),
  ('admin.crud.hotels.amenity.patio', 'ru', 'Патио', NOW(), NOW()),

  ('admin.crud.hotels.amenity.smoking_allowed', 'en', 'Smoking allowed', NOW(), NOW()),
  ('admin.crud.hotels.amenity.smoking_allowed', 'hy', 'Ծխելը թույլատրված է', NOW(), NOW()),
  ('admin.crud.hotels.amenity.smoking_allowed', 'ru', 'Курение разрешено', NOW(), NOW()),

  -- Translation section (was corrupted Armenian, now properly encoded)
  ('admin.crud.hotels.translations_title', 'en', 'Content in all languages', NOW(), NOW()),
  ('admin.crud.hotels.translations_title', 'hy', 'Բովանդակություն բոլոր լեզուներով', NOW(), NOW()),
  ('admin.crud.hotels.translations_title', 'ru', 'Контент на всех языках', NOW(), NOW()),

  ('admin.crud.hotels.translations_hint', 'en', '(all languages are equal — the source language is the entry point)', NOW(), NOW()),
  ('admin.crud.hotels.translations_hint', 'hy', '(բոլոր լեզուները հավասար են — ընտրիր սկզբնական լեզուն)', NOW(), NOW()),
  ('admin.crud.hotels.translations_hint', 'ru', '(все языки равны — выберите исходный язык)', NOW(), NOW()),

  -- Translation field labels (were corrupted Armenian)
  ('admin.crud.hotels.field.hotel_name', 'en', 'Hotel name', NOW(), NOW()),
  ('admin.crud.hotels.field.hotel_name', 'hy', 'Հյուրանոցի անունը', NOW(), NOW()),
  ('admin.crud.hotels.field.hotel_name', 'ru', 'Название отеля', NOW(), NOW()),

  ('admin.crud.hotels.field.full_address', 'en', 'Full address', NOW(), NOW()),
  ('admin.crud.hotels.field.full_address', 'hy', 'Ամբողջական հասցե', NOW(), NOW()),
  ('admin.crud.hotels.field.full_address', 'ru', 'Полный адрес', NOW(), NOW()),

  ('admin.crud.hotels.field.district_or_area', 'en', 'District / area', NOW(), NOW()),
  ('admin.crud.hotels.field.district_or_area', 'hy', 'Շրջան / տարածք', NOW(), NOW()),
  ('admin.crud.hotels.field.district_or_area', 'ru', 'Район / местность', NOW(), NOW()),

  ('admin.crud.hotels.field.review_label', 'en', 'Review label', NOW(), NOW()),
  ('admin.crud.hotels.field.review_label', 'hy', 'Գնահատման պիտակ', NOW(), NOW()),
  ('admin.crud.hotels.field.review_label', 'ru', 'Метка оценки', NOW(), NOW())

ON CONFLICT (key, language_code) DO UPDATE
  SET value = EXCLUDED.value,
      updated_at = NOW();
