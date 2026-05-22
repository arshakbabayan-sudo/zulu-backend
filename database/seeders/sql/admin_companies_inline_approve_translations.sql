SET client_encoding = 'UTF8';

-- Phase 6.1 — Inline Approve/Reject UI for pending governance_status on /platform/companies

INSERT INTO ui_translations (key, language_code, value, created_at, updated_at) VALUES
  ('admin.platform_companies.btn_approve', 'en', 'Approve', NOW(), NOW()),
  ('admin.platform_companies.btn_approve', 'hy', 'Հաստատել', NOW(), NOW()),
  ('admin.platform_companies.btn_approve', 'ru', 'Утвердить', NOW(), NOW()),

  ('admin.platform_companies.btn_reject', 'en', 'Reject', NOW(), NOW()),
  ('admin.platform_companies.btn_reject', 'hy', 'Մերժել', NOW(), NOW()),
  ('admin.platform_companies.btn_reject', 'ru', 'Отклонить', NOW(), NOW()),

  ('admin.platform_companies.confirm_inline_approve', 'en', 'Approve "{name}" and move to active?', NOW(), NOW()),
  ('admin.platform_companies.confirm_inline_approve', 'hy', 'Հաստատե՞լ «{name}» և դարձնել ակտիվ', NOW(), NOW()),
  ('admin.platform_companies.confirm_inline_approve', 'ru', 'Утвердить «{name}» и перевести в активные?', NOW(), NOW()),

  ('admin.platform_companies.inline_reject_title', 'en', 'Reject "{name}"?', NOW(), NOW()),
  ('admin.platform_companies.inline_reject_title', 'hy', 'Մերժե՞լ «{name}»-ին', NOW(), NOW()),
  ('admin.platform_companies.inline_reject_title', 'ru', 'Отклонить «{name}»?', NOW(), NOW()),

  ('admin.platform_companies.inline_reject_description', 'en', 'Set governance status to rejected. Enter a short reason for the audit log.', NOW(), NOW()),
  ('admin.platform_companies.inline_reject_description', 'hy', 'Կարգավիճակը կդառնա մերժված։ Մուտքագրի՛ր կարճ պատճառ audit մատյանի համար։', NOW(), NOW()),
  ('admin.platform_companies.inline_reject_description', 'ru', 'Установить статус «отклонено». Укажите краткую причину для журнала аудита.', NOW(), NOW()),

  ('admin.platform_companies.reject_reason_placeholder', 'en', 'Reason for rejection…', NOW(), NOW()),
  ('admin.platform_companies.reject_reason_placeholder', 'hy', 'Մերժման պատճառը…', NOW(), NOW()),
  ('admin.platform_companies.reject_reason_placeholder', 'ru', 'Причина отклонения…', NOW(), NOW())

ON CONFLICT (key, language_code) DO UPDATE
  SET value = EXCLUDED.value,
      updated_at = NOW();
