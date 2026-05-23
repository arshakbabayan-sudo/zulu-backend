-- Phase 2 — Organization detail page tabs (Users + Applications history)
-- 6 new translation keys × 3 langs = 18 rows.
--
-- Applied to prod + cache cleared. Idempotent UPDATE-by-key.

INSERT INTO ui_translations (key, language_code, value, created_at, updated_at) VALUES
  ('admin.platform_companies.tab_users',        'hy', 'Աշխատակիցներ',                                NOW(), NOW()),
  ('admin.platform_companies.tab_users',        'en', 'Users',                                       NOW(), NOW()),
  ('admin.platform_companies.tab_users',        'ru', 'Сотрудники',                                  NOW(), NOW()),

  ('admin.platform_companies.tab_applications', 'hy', 'Հայտերի պատմություն',                         NOW(), NOW()),
  ('admin.platform_companies.tab_applications', 'en', 'Applications',                                NOW(), NOW()),
  ('admin.platform_companies.tab_applications', 'ru', 'История заявок',                              NOW(), NOW()),

  ('admin.platform_companies.users_empty',      'hy', 'Այս ընկերության հետ կապված օգտվող չկա։',      NOW(), NOW()),
  ('admin.platform_companies.users_empty',      'en', 'No users linked to this company yet.',         NOW(), NOW()),
  ('admin.platform_companies.users_empty',      'ru', 'Нет связанных пользователей.',                NOW(), NOW()),

  ('admin.platform_companies.apps_empty',       'hy', 'Հայտեր չեն գտնվել։',                          NOW(), NOW()),
  ('admin.platform_companies.apps_empty',       'en', 'No applications found.',                      NOW(), NOW()),
  ('admin.platform_companies.apps_empty',       'ru', 'Заявок не найдено.',                          NOW(), NOW()),

  ('admin.platform_companies.err_users_load',   'hy', 'Չհաջողվեց բեռնել օգտվողները',                 NOW(), NOW()),
  ('admin.platform_companies.err_users_load',   'en', 'Failed to load linked users',                 NOW(), NOW()),
  ('admin.platform_companies.err_users_load',   'ru', 'Не удалось загрузить пользователей',          NOW(), NOW()),

  ('admin.platform_companies.err_apps_load',    'hy', 'Չհաջողվեց բեռնել հայտերի պատմությունը',       NOW(), NOW()),
  ('admin.platform_companies.err_apps_load',    'en', 'Failed to load applications history',         NOW(), NOW()),
  ('admin.platform_companies.err_apps_load',    'ru', 'Не удалось загрузить историю заявок',         NOW(), NOW())
ON CONFLICT (language_code, key) DO UPDATE
  SET value = EXCLUDED.value, updated_at = NOW();
