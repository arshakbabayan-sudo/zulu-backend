SET client_encoding = 'UTF8';

-- Phase 1.5 — /operator/transfers page translations
-- Most strings already had t() calls; this fills the remaining hardcoded.

INSERT INTO ui_translations (key, language_code, value, created_at, updated_at) VALUES
  ('admin.crud.transfers.main_image_alt', 'en', 'Transfer preview', NOW(), NOW()),
  ('admin.crud.transfers.main_image_alt', 'hy', 'Փոխադրման նախադիտում', NOW(), NOW()),
  ('admin.crud.transfers.main_image_alt', 'ru', 'Превью трансфера', NOW(), NOW()),

  ('admin.crud.transfers.field.currency', 'en', 'Currency (offer)', NOW(), NOW()),
  ('admin.crud.transfers.field.currency', 'hy', 'Արժույթ (առաջարկ)', NOW(), NOW()),
  ('admin.crud.transfers.field.currency', 'ru', 'Валюта (предложение)', NOW(), NOW()),

  ('admin.crud.transfers.field.origin_location', 'en', 'Origin location', NOW(), NOW()),
  ('admin.crud.transfers.field.origin_location', 'hy', 'Մեկնման վայր', NOW(), NOW()),
  ('admin.crud.transfers.field.origin_location', 'ru', 'Место отправления', NOW(), NOW()),

  ('admin.crud.transfers.field.destination_location', 'en', 'Destination location', NOW(), NOW()),
  ('admin.crud.transfers.field.destination_location', 'hy', 'Նպատակակետ', NOW(), NOW()),
  ('admin.crud.transfers.field.destination_location', 'ru', 'Пункт назначения', NOW(), NOW()),

  ('admin.crud.transfers.translations_title', 'en', 'Translations', NOW(), NOW()),
  ('admin.crud.transfers.translations_title', 'hy', 'Թարգմանություններ', NOW(), NOW()),
  ('admin.crud.transfers.translations_title', 'ru', 'Переводы', NOW(), NOW()),

  ('admin.crud.transfers.translations_hint', 'en', '(beyond EN: RU / HY)', NOW(), NOW()),
  ('admin.crud.transfers.translations_hint', 'hy', '(EN-ից բացի՝ RU / HY)', NOW(), NOW()),
  ('admin.crud.transfers.translations_hint', 'ru', '(кроме EN: RU / HY)', NOW(), NOW()),

  ('admin.crud.transfers.field.title', 'en', 'Title', NOW(), NOW()),
  ('admin.crud.transfers.field.title', 'hy', 'Վերնագիր', NOW(), NOW()),
  ('admin.crud.transfers.field.title', 'ru', 'Заголовок', NOW(), NOW()),

  ('admin.crud.transfers.field.description', 'en', 'Description', NOW(), NOW()),
  ('admin.crud.transfers.field.description', 'hy', 'Նկարագրություն', NOW(), NOW()),
  ('admin.crud.transfers.field.description', 'ru', 'Описание', NOW(), NOW())

ON CONFLICT (key, language_code) DO UPDATE
  SET value = EXCLUDED.value,
      updated_at = NOW();
