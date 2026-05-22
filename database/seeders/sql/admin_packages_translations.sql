SET client_encoding = 'UTF8';

-- Phase 1.9 — /operator/packages page translations

INSERT INTO ui_translations (key, language_code, value, created_at, updated_at) VALUES
  ('admin.crud.packages.field.subtitle', 'en', 'Subtitle', NOW(), NOW()),
  ('admin.crud.packages.field.subtitle', 'hy', 'Ենթավերնագիր', NOW(), NOW()),
  ('admin.crud.packages.field.subtitle', 'ru', 'Подзаголовок', NOW(), NOW()),

  ('admin.crud.packages.field.subtitle_placeholder', 'en', 'Short tagline shown under the title (e.g. ''Garni + Sevan + Tucson SUV'')', NOW(), NOW()),
  ('admin.crud.packages.field.subtitle_placeholder', 'hy', 'Կարճ բացատրություն՝ վերնագրի տակ (օր. ''Գառնի + Սևան + Tucson SUV'')', NOW(), NOW()),
  ('admin.crud.packages.field.subtitle_placeholder', 'ru', 'Короткий слоган под заголовком (напр. ''Гарни + Севан + Tucson SUV'')', NOW(), NOW()),

  ('admin.crud.packages.field.destination_location', 'en', 'Destination location', NOW(), NOW()),
  ('admin.crud.packages.field.destination_location', 'hy', 'Նպատակակետ', NOW(), NOW()),
  ('admin.crud.packages.field.destination_location', 'ru', 'Пункт назначения', NOW(), NOW()),

  ('admin.crud.packages.field.base_price', 'en', 'Base price', NOW(), NOW()),
  ('admin.crud.packages.field.base_price', 'hy', 'Հիմնական գին', NOW(), NOW()),
  ('admin.crud.packages.field.base_price', 'ru', 'Базовая цена', NOW(), NOW()),

  ('admin.crud.packages.field.min_nights', 'en', 'Min nights', NOW(), NOW()),
  ('admin.crud.packages.field.min_nights', 'hy', 'Նվազագույն գիշերներ', NOW(), NOW()),
  ('admin.crud.packages.field.min_nights', 'ru', 'Мин. ночей', NOW(), NOW()),

  ('admin.crud.packages.field.min_nights_placeholder', 'en', 'Hotel nights included', NOW(), NOW()),
  ('admin.crud.packages.field.min_nights_placeholder', 'hy', 'Ներառված հյուրանոցային գիշերներ', NOW(), NOW()),
  ('admin.crud.packages.field.min_nights_placeholder', 'ru', 'Включённые гостиничные ночи', NOW(), NOW()),

  ('admin.crud.packages.field.adults', 'en', 'Adults', NOW(), NOW()),
  ('admin.crud.packages.field.adults', 'hy', 'Մեծահասակներ', NOW(), NOW()),
  ('admin.crud.packages.field.adults', 'ru', 'Взрослые', NOW(), NOW()),

  ('admin.crud.packages.field.children', 'en', 'Children', NOW(), NOW()),
  ('admin.crud.packages.field.children', 'hy', 'Երեխաներ', NOW(), NOW()),
  ('admin.crud.packages.field.children', 'ru', 'Дети', NOW(), NOW()),

  ('admin.crud.packages.main_image_alt', 'en', 'Package preview', NOW(), NOW()),
  ('admin.crud.packages.main_image_alt', 'hy', 'Փաթեթի նախադիտում', NOW(), NOW()),
  ('admin.crud.packages.main_image_alt', 'ru', 'Превью пакета', NOW(), NOW()),

  ('admin.crud.packages.field.package_title', 'en', 'Package title', NOW(), NOW()),
  ('admin.crud.packages.field.package_title', 'hy', 'Փաթեթի վերնագիր', NOW(), NOW()),
  ('admin.crud.packages.field.package_title', 'ru', 'Заголовок пакета', NOW(), NOW()),

  ('admin.crud.packages.field.short_description', 'en', 'Short description', NOW(), NOW()),
  ('admin.crud.packages.field.short_description', 'hy', 'Կարճ նկարագրություն', NOW(), NOW()),
  ('admin.crud.packages.field.short_description', 'ru', 'Краткое описание', NOW(), NOW())

ON CONFLICT (key, language_code) DO UPDATE
  SET value = EXCLUDED.value,
      updated_at = NOW();
