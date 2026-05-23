-- Phase 6.1 (extended) — Companies page union view: pending applications surface
-- inline on the Companies list with their own status badge + Approve/Reject CTAs.
--
-- 6 new translation keys × 3 langs = 18 rows.
--
-- Applied to prod + cache cleared. Idempotent via UPDATE-by-(lang, key).

INSERT INTO ui_translations (key, language_code, value, created_at, updated_at) VALUES
  ('admin.platform_companies.pending_application', 'hy', 'Սպասվող հայտ',           NOW(), NOW()),
  ('admin.platform_companies.pending_application', 'en', 'Pending application',     NOW(), NOW()),
  ('admin.platform_companies.pending_application', 'ru', 'Ожидает рассмотрения',    NOW(), NOW()),

  ('admin.platform_companies.pending_apps_count',  'hy', '+ {count} սպասվող հայտ',  NOW(), NOW()),
  ('admin.platform_companies.pending_apps_count',  'en', '+ {count} pending apps',  NOW(), NOW()),
  ('admin.platform_companies.pending_apps_count',  'ru', '+ {count} заявок',        NOW(), NOW()),

  ('admin.platform_companies.confirm_approve_app', 'hy', 'Հաստատե՞լ «{name}» հայտը։ Ստեղծվելու է ընկերության գրառում + admin user։', NOW(), NOW()),
  ('admin.platform_companies.confirm_approve_app', 'en', 'Approve "{name}"? Will create the company row + admin user.',              NOW(), NOW()),
  ('admin.platform_companies.confirm_approve_app', 'ru', 'Одобрить заявку «{name}»? Будут созданы компания и пользователь admin.',   NOW(), NOW()),

  ('admin.platform_companies.err_approve_app', 'hy', 'Չհաջողվեց հաստատել հայտը',             NOW(), NOW()),
  ('admin.platform_companies.err_approve_app', 'en', 'Failed to approve application',        NOW(), NOW()),
  ('admin.platform_companies.err_approve_app', 'ru', 'Не удалось одобрить заявку',           NOW(), NOW()),

  ('admin.platform_companies.err_reject_app',  'hy', 'Չհաջողվեց մերժել հայտը',               NOW(), NOW()),
  ('admin.platform_companies.err_reject_app',  'en', 'Failed to reject application',         NOW(), NOW()),
  ('admin.platform_companies.err_reject_app',  'ru', 'Не удалось отклонить заявку',          NOW(), NOW()),

  ('admin.platform_companies.app_id_tooltip',  'hy', 'Հայտի մանրամասներ',                    NOW(), NOW()),
  ('admin.platform_companies.app_id_tooltip',  'en', 'View application detail',              NOW(), NOW()),
  ('admin.platform_companies.app_id_tooltip',  'ru', 'Подробнее о заявке',                   NOW(), NOW())
ON CONFLICT (language_code, key) DO UPDATE
  SET value = EXCLUDED.value, updated_at = NOW();
