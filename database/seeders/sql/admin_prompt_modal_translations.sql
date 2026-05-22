SET client_encoding = 'UTF8';

-- Phase 3 — Translations for PromptModal + connections/reviews replacements

INSERT INTO ui_translations (key, language_code, value, created_at, updated_at) VALUES
  ('common.confirm', 'en', 'Confirm', NOW(), NOW()),
  ('common.confirm', 'hy', 'Հաստատել', NOW(), NOW()),
  ('common.confirm', 'ru', 'Подтвердить', NOW(), NOW()),

  ('common.field_required', 'en', 'This field is required', NOW(), NOW()),
  ('common.field_required', 'hy', 'Այս դաշտը պարտադիր է', NOW(), NOW()),
  ('common.field_required', 'ru', 'Это поле обязательно', NOW(), NOW()),

  ('admin.connections.action.reject', 'en', 'Reject connection', NOW(), NOW()),
  ('admin.connections.action.reject', 'hy', 'Մերժել միացումը', NOW(), NOW()),
  ('admin.connections.action.reject', 'ru', 'Отклонить соединение', NOW(), NOW()),

  ('admin.connections.action.cancel', 'en', 'Cancel connection', NOW(), NOW()),
  ('admin.connections.action.cancel', 'hy', 'Չեղարկել միացումը', NOW(), NOW()),
  ('admin.connections.action.cancel', 'ru', 'Отменить соединение', NOW(), NOW()),

  ('admin.reviews.moderate_title', 'en', 'Moderate review', NOW(), NOW()),
  ('admin.reviews.moderate_title', 'hy', 'Մոդերավորել կարծիքը', NOW(), NOW()),
  ('admin.reviews.moderate_title', 'ru', 'Модерация отзыва', NOW(), NOW())

ON CONFLICT (key, language_code) DO UPDATE
  SET value = EXCLUDED.value,
      updated_at = NOW();
