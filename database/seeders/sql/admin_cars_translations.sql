SET client_encoding = 'UTF8';

-- Phase 1.7 — /operator/cars page translations

INSERT INTO ui_translations (key, language_code, value, created_at, updated_at) VALUES
  ('admin.crud.cars.main_image_alt', 'en', 'Car preview', NOW(), NOW()),
  ('admin.crud.cars.main_image_alt', 'hy', 'Մեքենայի նախադիտում', NOW(), NOW()),
  ('admin.crud.cars.main_image_alt', 'ru', 'Превью автомобиля', NOW(), NOW()),

  ('admin.crud.cars.field.location', 'en', 'Location', NOW(), NOW()),
  ('admin.crud.cars.field.location', 'hy', 'Տեղադրություն', NOW(), NOW()),
  ('admin.crud.cars.field.location', 'ru', 'Местоположение', NOW(), NOW()),

  ('admin.crud.cars.radius_placeholder', 'en', 'Leave empty = no radius limit', NOW(), NOW()),
  ('admin.crud.cars.radius_placeholder', 'hy', 'Թողեք դատարկ = շառավղի սահման չկա', NOW(), NOW()),
  ('admin.crud.cars.radius_placeholder', 'ru', 'Оставьте пустым = без ограничения радиуса', NOW(), NOW()),

  ('admin.crud.cars.mileage.unlimited', 'en', 'Unlimited', NOW(), NOW()),
  ('admin.crud.cars.mileage.unlimited', 'hy', 'Անսահմանափակ', NOW(), NOW()),
  ('admin.crud.cars.mileage.unlimited', 'ru', 'Безлимитный', NOW(), NOW()),

  ('admin.crud.cars.mileage.limited', 'en', 'Limited (included km)', NOW(), NOW()),
  ('admin.crud.cars.mileage.limited', 'hy', 'Սահմանափակ (ներառված կմ)', NOW(), NOW()),
  ('admin.crud.cars.mileage.limited', 'ru', 'Ограниченный (включённые км)', NOW(), NOW()),

  ('admin.crud.cars.out_mode.flat_fee', 'en', 'Extra flat fee', NOW(), NOW()),
  ('admin.crud.cars.out_mode.flat_fee', 'hy', 'Հավելյալ ֆիքսված վճար', NOW(), NOW()),
  ('admin.crud.cars.out_mode.flat_fee', 'ru', 'Доп. фиксированная плата', NOW(), NOW()),

  ('admin.crud.cars.out_mode.per_km', 'en', 'Extra per km', NOW(), NOW()),
  ('admin.crud.cars.out_mode.per_km', 'hy', 'Հավելյալ՝ յուր. կմ-ի համար', NOW(), NOW()),
  ('admin.crud.cars.out_mode.per_km', 'ru', 'Доп. за км', NOW(), NOW()),

  ('admin.crud.cars.out_mode.not_allowed', 'en', 'Not allowed', NOW(), NOW()),
  ('admin.crud.cars.out_mode.not_allowed', 'hy', 'Թույլատրված չէ', NOW(), NOW()),
  ('admin.crud.cars.out_mode.not_allowed', 'ru', 'Не разрешено', NOW(), NOW()),

  ('admin.crud.cars.out_mode.quote_only', 'en', 'Quote only', NOW(), NOW()),
  ('admin.crud.cars.out_mode.quote_only', 'hy', 'Միայն ըստ հարցման', NOW(), NOW()),
  ('admin.crud.cars.out_mode.quote_only', 'ru', 'Только по запросу', NOW(), NOW()),

  ('admin.crud.cars.field.title', 'en', 'Title', NOW(), NOW()),
  ('admin.crud.cars.field.title', 'hy', 'Վերնագիր', NOW(), NOW()),
  ('admin.crud.cars.field.title', 'ru', 'Заголовок', NOW(), NOW()),

  ('admin.crud.cars.field.description', 'en', 'Description', NOW(), NOW()),
  ('admin.crud.cars.field.description', 'hy', 'Նկարագրություն', NOW(), NOW()),
  ('admin.crud.cars.field.description', 'ru', 'Описание', NOW(), NOW())

ON CONFLICT (key, language_code) DO UPDATE
  SET value = EXCLUDED.value,
      updated_at = NOW();
