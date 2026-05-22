SET client_encoding = 'UTF8';

-- Phase 7.7 — Translations for payments status dropdown + CSV export

INSERT INTO ui_translations (key, language_code, value, created_at, updated_at) VALUES
  ('admin.payments.btn_export_csv', 'en', 'Export CSV', NOW(), NOW()),
  ('admin.payments.btn_export_csv', 'hy', 'CSV արտահանում', NOW(), NOW()),
  ('admin.payments.btn_export_csv', 'ru', 'Экспорт CSV', NOW(), NOW()),

  ('admin.payments.exporting', 'en', 'Exporting…', NOW(), NOW()),
  ('admin.payments.exporting', 'hy', 'Արտահանում…', NOW(), NOW()),
  ('admin.payments.exporting', 'ru', 'Экспорт…', NOW(), NOW()),

  ('admin.payments.err_export', 'en', 'Failed to export payments', NOW(), NOW()),
  ('admin.payments.err_export', 'hy', 'Չհաջողվեց արտահանել վճարումները', NOW(), NOW()),
  ('admin.payments.err_export', 'ru', 'Не удалось экспортировать платежи', NOW(), NOW())

ON CONFLICT (key, language_code) DO UPDATE
  SET value = EXCLUDED.value,
      updated_at = NOW();
