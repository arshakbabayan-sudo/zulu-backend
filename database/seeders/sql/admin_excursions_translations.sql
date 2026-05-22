SET client_encoding = 'UTF8';

-- Phase 1.6 — /operator/excursions page translations

INSERT INTO ui_translations (key, language_code, value, created_at, updated_at) VALUES
  -- Field labels (used for API validation errors + form labels)
  ('admin.crud.excursions.field.form', 'en', 'Form', NOW(), NOW()),
  ('admin.crud.excursions.field.form', 'hy', 'Ձև', NOW(), NOW()),
  ('admin.crud.excursions.field.form', 'ru', 'Форма', NOW(), NOW()),

  ('admin.crud.excursions.field.offer', 'en', 'Offer', NOW(), NOW()),
  ('admin.crud.excursions.field.offer', 'hy', 'Առաջարկ', NOW(), NOW()),
  ('admin.crud.excursions.field.offer', 'ru', 'Предложение', NOW(), NOW()),

  ('admin.crud.excursions.field.company', 'en', 'Company', NOW(), NOW()),
  ('admin.crud.excursions.field.company', 'hy', 'Ընկերություն', NOW(), NOW()),
  ('admin.crud.excursions.field.company', 'ru', 'Компания', NOW(), NOW()),

  ('admin.crud.excursions.field.location', 'en', 'Location', NOW(), NOW()),
  ('admin.crud.excursions.field.location', 'hy', 'Վայր', NOW(), NOW()),
  ('admin.crud.excursions.field.location', 'ru', 'Местоположение', NOW(), NOW()),

  ('admin.crud.excursions.field.location_full', 'en', 'Location (Country → Region → City)', NOW(), NOW()),
  ('admin.crud.excursions.field.location_full', 'hy', 'Վայր (Երկիր → Շրջան → Քաղաք)', NOW(), NOW()),
  ('admin.crud.excursions.field.location_full', 'ru', 'Местоположение (Страна → Регион → Город)', NOW(), NOW()),

  ('admin.crud.excursions.field.country', 'en', 'Country', NOW(), NOW()),
  ('admin.crud.excursions.field.country', 'hy', 'Երկիր', NOW(), NOW()),
  ('admin.crud.excursions.field.country', 'ru', 'Страна', NOW(), NOW()),

  ('admin.crud.excursions.field.city', 'en', 'City', NOW(), NOW()),
  ('admin.crud.excursions.field.city', 'hy', 'Քաղաք', NOW(), NOW()),
  ('admin.crud.excursions.field.city', 'ru', 'Город', NOW(), NOW()),

  ('admin.crud.excursions.field.general_category', 'en', 'General category', NOW(), NOW()),
  ('admin.crud.excursions.field.general_category', 'hy', 'Ընդհանուր կատեգորիա', NOW(), NOW()),
  ('admin.crud.excursions.field.general_category', 'ru', 'Общая категория', NOW(), NOW()),

  ('admin.crud.excursions.field.category', 'en', 'Category', NOW(), NOW()),
  ('admin.crud.excursions.field.category', 'hy', 'Կատեգորիա', NOW(), NOW()),
  ('admin.crud.excursions.field.category', 'ru', 'Категория', NOW(), NOW()),

  ('admin.crud.excursions.field.excursion_type', 'en', 'Excursion type', NOW(), NOW()),
  ('admin.crud.excursions.field.excursion_type', 'hy', 'Էքսկուրսիայի տեսակ', NOW(), NOW()),
  ('admin.crud.excursions.field.excursion_type', 'ru', 'Тип экскурсии', NOW(), NOW()),

  ('admin.crud.excursions.field.tour_name', 'en', 'Tour name', NOW(), NOW()),
  ('admin.crud.excursions.field.tour_name', 'hy', 'Շրջագայության անվանում', NOW(), NOW()),
  ('admin.crud.excursions.field.tour_name', 'ru', 'Название тура', NOW(), NOW()),

  ('admin.crud.excursions.field.overview', 'en', 'Overview', NOW(), NOW()),
  ('admin.crud.excursions.field.overview', 'hy', 'Ընդհանուր նկարագիր', NOW(), NOW()),
  ('admin.crud.excursions.field.overview', 'ru', 'Обзор', NOW(), NOW()),

  ('admin.crud.excursions.field.duration', 'en', 'Duration', NOW(), NOW()),
  ('admin.crud.excursions.field.duration', 'hy', 'Տևողություն', NOW(), NOW()),
  ('admin.crud.excursions.field.duration', 'ru', 'Длительность', NOW(), NOW()),

  ('admin.crud.excursions.field.starts_at', 'en', 'Start time', NOW(), NOW()),
  ('admin.crud.excursions.field.starts_at', 'hy', 'Սկսելու ժամ', NOW(), NOW()),
  ('admin.crud.excursions.field.starts_at', 'ru', 'Время начала', NOW(), NOW()),

  ('admin.crud.excursions.field.ends_at', 'en', 'End time', NOW(), NOW()),
  ('admin.crud.excursions.field.ends_at', 'hy', 'Ավարտի ժամ', NOW(), NOW()),
  ('admin.crud.excursions.field.ends_at', 'ru', 'Время окончания', NOW(), NOW()),

  ('admin.crud.excursions.field.language', 'en', 'Language', NOW(), NOW()),
  ('admin.crud.excursions.field.language', 'hy', 'Լեզու', NOW(), NOW()),
  ('admin.crud.excursions.field.language', 'ru', 'Язык', NOW(), NOW()),

  ('admin.crud.excursions.field.group_size', 'en', 'Group size', NOW(), NOW()),
  ('admin.crud.excursions.field.group_size', 'hy', 'Խմբի չափ', NOW(), NOW()),
  ('admin.crud.excursions.field.group_size', 'ru', 'Размер группы', NOW(), NOW()),

  ('admin.crud.excursions.field.ticket_max_count', 'en', 'Max tickets', NOW(), NOW()),
  ('admin.crud.excursions.field.ticket_max_count', 'hy', 'Տոմսերի առավելագույն թիվ', NOW(), NOW()),
  ('admin.crud.excursions.field.ticket_max_count', 'ru', 'Макс. билетов', NOW(), NOW()),

  ('admin.crud.excursions.field.is_available', 'en', 'Available', NOW(), NOW()),
  ('admin.crud.excursions.field.is_available', 'hy', 'Հասանելի է', NOW(), NOW()),
  ('admin.crud.excursions.field.is_available', 'ru', 'Доступен', NOW(), NOW()),

  ('admin.crud.excursions.field.is_bookable', 'en', 'Bookable', NOW(), NOW()),
  ('admin.crud.excursions.field.is_bookable', 'hy', 'Ամրագրելի է', NOW(), NOW()),
  ('admin.crud.excursions.field.is_bookable', 'ru', 'Можно бронировать', NOW(), NOW()),

  ('admin.crud.excursions.field.meeting_pickup', 'en', 'Meeting / pickup', NOW(), NOW()),
  ('admin.crud.excursions.field.meeting_pickup', 'hy', 'Հանդիպման / վերցնելու վայր', NOW(), NOW()),
  ('admin.crud.excursions.field.meeting_pickup', 'ru', 'Место встречи / посадка', NOW(), NOW()),

  ('admin.crud.excursions.field.additional_info', 'en', 'Additional info', NOW(), NOW()),
  ('admin.crud.excursions.field.additional_info', 'hy', 'Լրացուցիչ տեղեկություն', NOW(), NOW()),
  ('admin.crud.excursions.field.additional_info', 'ru', 'Дополнительная информация', NOW(), NOW()),

  ('admin.crud.excursions.field.cancellation_policy', 'en', 'Cancellation policy', NOW(), NOW()),
  ('admin.crud.excursions.field.cancellation_policy', 'hy', 'Չեղարկման կանոն', NOW(), NOW()),
  ('admin.crud.excursions.field.cancellation_policy', 'ru', 'Правило отмены', NOW(), NOW()),

  ('admin.crud.excursions.field.includes', 'en', 'Includes', NOW(), NOW()),
  ('admin.crud.excursions.field.includes', 'hy', 'Ներառում է', NOW(), NOW()),
  ('admin.crud.excursions.field.includes', 'ru', 'Включает', NOW(), NOW()),

  ('admin.crud.excursions.field.photos', 'en', 'Photos', NOW(), NOW()),
  ('admin.crud.excursions.field.photos', 'hy', 'Լուսանկարներ', NOW(), NOW()),
  ('admin.crud.excursions.field.photos', 'ru', 'Фотографии', NOW(), NOW()),

  ('admin.crud.excursions.field.price_by_dates', 'en', 'Price by dates', NOW(), NOW()),
  ('admin.crud.excursions.field.price_by_dates', 'hy', 'Գին ըստ ամսաթվերի', NOW(), NOW()),
  ('admin.crud.excursions.field.price_by_dates', 'ru', 'Цена по датам', NOW(), NOW()),

  ('admin.crud.excursions.field.visibility_rule', 'en', 'Visibility rule', NOW(), NOW()),
  ('admin.crud.excursions.field.visibility_rule', 'hy', 'Տեսանելիության կանոն', NOW(), NOW()),
  ('admin.crud.excursions.field.visibility_rule', 'ru', 'Правило видимости', NOW(), NOW()),

  ('admin.crud.excursions.field.appears_in_web', 'en', 'Show on web', NOW(), NOW()),
  ('admin.crud.excursions.field.appears_in_web', 'hy', 'Ցույց տալ վեբում', NOW(), NOW()),
  ('admin.crud.excursions.field.appears_in_web', 'ru', 'Показывать на сайте', NOW(), NOW()),

  ('admin.crud.excursions.field.appears_in_admin', 'en', 'Show in operator admin', NOW(), NOW()),
  ('admin.crud.excursions.field.appears_in_admin', 'hy', 'Ցույց տալ օպերատորի ադմինում', NOW(), NOW()),
  ('admin.crud.excursions.field.appears_in_admin', 'ru', 'Показывать в админке оператора', NOW(), NOW()),

  ('admin.crud.excursions.field.appears_in_zulu_admin', 'en', 'Show in Zulu admin inventory', NOW(), NOW()),
  ('admin.crud.excursions.field.appears_in_zulu_admin', 'hy', 'Ցույց տալ Zulu ադմինի ինվենտարում', NOW(), NOW()),
  ('admin.crud.excursions.field.appears_in_zulu_admin', 'ru', 'Показывать в инвентаре админа Zulu', NOW(), NOW()),

  ('admin.crud.excursions.field.price', 'en', 'Price', NOW(), NOW()),
  ('admin.crud.excursions.field.price', 'hy', 'Գին', NOW(), NOW()),
  ('admin.crud.excursions.field.price', 'ru', 'Цена', NOW(), NOW()),

  ('admin.crud.excursions.field.title', 'en', 'Title', NOW(), NOW()),
  ('admin.crud.excursions.field.title', 'hy', 'Վերնագիր', NOW(), NOW()),
  ('admin.crud.excursions.field.title', 'ru', 'Заголовок', NOW(), NOW()),

  ('admin.crud.excursions.field.description', 'en', 'Description', NOW(), NOW()),
  ('admin.crud.excursions.field.description', 'hy', 'Նկարագրություն', NOW(), NOW()),
  ('admin.crud.excursions.field.description', 'ru', 'Описание', NOW(), NOW()),

  ('admin.crud.excursions.field.highlights', 'en', 'Highlights', NOW(), NOW()),
  ('admin.crud.excursions.field.highlights', 'hy', 'Կարևորագույն կետեր', NOW(), NOW()),
  ('admin.crud.excursions.field.highlights', 'ru', 'Особенности', NOW(), NOW()),

  ('admin.crud.excursions.main_image_alt', 'en', 'Excursion preview', NOW(), NOW()),
  ('admin.crud.excursions.main_image_alt', 'hy', 'Էքսկուրսիայի նախադիտում', NOW(), NOW()),
  ('admin.crud.excursions.main_image_alt', 'ru', 'Превью экскурсии', NOW(), NOW()),

  ('admin.crud.excursions.fix_highlighted', 'en', 'Please fix the highlighted fields.', NOW(), NOW()),
  ('admin.crud.excursions.fix_highlighted', 'hy', 'Խնդրում ենք ուղղել նշված դաշտերը։', NOW(), NOW()),
  ('admin.crud.excursions.fix_highlighted', 'ru', 'Пожалуйста, исправьте выделенные поля.', NOW(), NOW())

ON CONFLICT (key, language_code) DO UPDATE
  SET value = EXCLUDED.value,
      updated_at = NOW();
