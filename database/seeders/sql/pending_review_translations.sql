SET client_encoding = 'UTF8';

INSERT INTO ui_translations (key, language_code, value, created_at, updated_at) VALUES
  ('admin.nav.pending_review', 'en', 'Pending review', NOW(), NOW()),
  ('admin.nav.pending_review', 'hy', 'Սպասում է վերանայման', NOW(), NOW()),
  ('admin.nav.pending_review', 'ru', 'Ожидает проверки', NOW(), NOW())
ON CONFLICT (key, language_code) DO UPDATE
  SET value = EXCLUDED.value,
      updated_at = NOW();
