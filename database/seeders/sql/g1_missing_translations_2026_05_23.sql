-- G1 — Armenian translation completeness audit (2026-05-23)
--
-- Scan of all `t("...")` literal calls across admin + frontend
-- (2971 unique keys) vs `ui_translations` rows (3800 per language)
-- surfaced 2 keys called by code but missing from DB. Both are
-- browser-tab document titles, so the broken UX was a literal "key
-- string" appearing in the browser tab name instead of a localized
-- page title.
--
-- After insert: run `php artisan cache:forget ui_translations_<lang>`
-- for hy/en/ru (already done on prod 2026-05-23).
--
-- Idempotent via ON CONFLICT — safe to re-run.

INSERT INTO ui_translations (key, language_code, value, created_at, updated_at) VALUES
  ('admin.companies.title',         'hy', 'Ընկերություններ', NOW(), NOW()),
  ('admin.companies.title',         'en', 'Companies',       NOW(), NOW()),
  ('admin.companies.title',         'ru', 'Компании',        NOW(), NOW()),
  ('admin.operator.hotels.title',   'hy', 'Հյուրանոցներ',    NOW(), NOW()),
  ('admin.operator.hotels.title',   'en', 'Hotels',          NOW(), NOW()),
  ('admin.operator.hotels.title',   'ru', 'Отели',           NOW(), NOW())
ON CONFLICT (language_code, key) DO UPDATE
  SET value = EXCLUDED.value, updated_at = NOW();
