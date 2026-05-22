SET client_encoding = 'UTF8';

-- Phase 7.6 — Translations for invoice date range + CSV export

INSERT INTO ui_translations (key, language_code, value, created_at, updated_at) VALUES
  ('admin.invoices.filter_from', 'en', 'From date', NOW(), NOW()),
  ('admin.invoices.filter_from', 'hy', 'Սկսած', NOW(), NOW()),
  ('admin.invoices.filter_from', 'ru', 'С даты', NOW(), NOW()),

  ('admin.invoices.filter_to', 'en', 'To date', NOW(), NOW()),
  ('admin.invoices.filter_to', 'hy', 'Մինչև', NOW(), NOW()),
  ('admin.invoices.filter_to', 'ru', 'По дату', NOW(), NOW()),

  ('admin.invoices.btn_clear_filters', 'en', 'Clear filters', NOW(), NOW()),
  ('admin.invoices.btn_clear_filters', 'hy', 'Մաքրել ֆիլտրերը', NOW(), NOW()),
  ('admin.invoices.btn_clear_filters', 'ru', 'Очистить фильтры', NOW(), NOW()),

  ('admin.invoices.btn_export_csv', 'en', 'Export CSV', NOW(), NOW()),
  ('admin.invoices.btn_export_csv', 'hy', 'CSV արտահանում', NOW(), NOW()),
  ('admin.invoices.btn_export_csv', 'ru', 'Экспорт CSV', NOW(), NOW()),

  ('admin.invoices.exporting', 'en', 'Exporting…', NOW(), NOW()),
  ('admin.invoices.exporting', 'hy', 'Արտահանում…', NOW(), NOW()),
  ('admin.invoices.exporting', 'ru', 'Экспорт…', NOW(), NOW()),

  ('admin.invoices.err_export', 'en', 'Failed to export invoices', NOW(), NOW()),
  ('admin.invoices.err_export', 'hy', 'Չհաջողվեց արտահանել հաշիվները', NOW(), NOW()),
  ('admin.invoices.err_export', 'ru', 'Не удалось экспортировать счета', NOW(), NOW())

ON CONFLICT (key, language_code) DO UPDATE
  SET value = EXCLUDED.value,
      updated_at = NOW();
