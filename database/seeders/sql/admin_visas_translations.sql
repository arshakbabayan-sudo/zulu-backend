SET client_encoding = 'UTF8';

-- Phase 1.8 — /operator/visas page translations

INSERT INTO ui_translations (key, language_code, value, created_at, updated_at) VALUES
  ('admin.crud.common.invalid_value', 'en', 'Invalid value', NOW(), NOW()),
  ('admin.crud.common.invalid_value', 'hy', 'Անվավեր արժեք', NOW(), NOW()),
  ('admin.crud.common.invalid_value', 'ru', 'Недопустимое значение', NOW(), NOW()),

  ('admin.crud.visas.field.location', 'en', 'Location (select country/region/city)', NOW(), NOW()),
  ('admin.crud.visas.field.location', 'hy', 'Տեղադրություն (ընտրեք երկիր/շրջան/քաղաք)', NOW(), NOW()),
  ('admin.crud.visas.field.location', 'ru', 'Местоположение (выберите страну/регион/город)', NOW(), NOW()),

  ('admin.crud.visas.field.title', 'en', 'Title', NOW(), NOW()),
  ('admin.crud.visas.field.title', 'hy', 'Վերնագիր', NOW(), NOW()),
  ('admin.crud.visas.field.title', 'ru', 'Заголовок', NOW(), NOW()),

  ('admin.crud.visas.field.description', 'en', 'Description', NOW(), NOW()),
  ('admin.crud.visas.field.description', 'hy', 'Նկարագրություն', NOW(), NOW()),
  ('admin.crud.visas.field.description', 'ru', 'Описание', NOW(), NOW()),

  ('admin.crud.visas.field.notes', 'en', 'Notes', NOW(), NOW()),
  ('admin.crud.visas.field.notes', 'hy', 'Ծանոթագրություններ', NOW(), NOW()),
  ('admin.crud.visas.field.notes', 'ru', 'Заметки', NOW(), NOW())

ON CONFLICT (key, language_code) DO UPDATE
  SET value = EXCLUDED.value,
      updated_at = NOW();
