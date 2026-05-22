SET client_encoding = 'UTF8';

-- Phase 7.2 — Translations for company archive (super-admin only)

INSERT INTO ui_translations (key, language_code, value, created_at, updated_at) VALUES
  -- Archive filter
  ('admin.platform_companies.archive', 'en', 'Archive', NOW(), NOW()),
  ('admin.platform_companies.archive', 'hy', 'Արխիվ', NOW(), NOW()),
  ('admin.platform_companies.archive', 'ru', 'Архив', NOW(), NOW()),

  ('admin.platform_companies.archive_active', 'en', 'Active only', NOW(), NOW()),
  ('admin.platform_companies.archive_active', 'hy', 'Միայն ակտիվները', NOW(), NOW()),
  ('admin.platform_companies.archive_active', 'ru', 'Только активные', NOW(), NOW()),

  ('admin.platform_companies.archive_only', 'en', 'Archived only', NOW(), NOW()),
  ('admin.platform_companies.archive_only', 'hy', 'Միայն արխիվացվածները', NOW(), NOW()),
  ('admin.platform_companies.archive_only', 'ru', 'Только архивированные', NOW(), NOW()),

  ('admin.platform_companies.archive_all', 'en', 'All companies', NOW(), NOW()),
  ('admin.platform_companies.archive_all', 'hy', 'Բոլոր ընկերությունները', NOW(), NOW()),
  ('admin.platform_companies.archive_all', 'ru', 'Все компании', NOW(), NOW()),

  -- Archive button + flow
  ('admin.platform_companies.btn_archive', 'en', 'Archive', NOW(), NOW()),
  ('admin.platform_companies.btn_archive', 'hy', 'Արխիվացնել', NOW(), NOW()),
  ('admin.platform_companies.btn_archive', 'ru', 'Архивировать', NOW(), NOW()),

  ('admin.platform_companies.btn_archive_tooltip', 'en', 'Hide from active lists (preserves orders/contracts)', NOW(), NOW()),
  ('admin.platform_companies.btn_archive_tooltip', 'hy', 'Թաքցնել ակտիվ ցուցակից (պատվերներն ու պայմանագրերը մնում են)', NOW(), NOW()),
  ('admin.platform_companies.btn_archive_tooltip', 'ru', 'Скрыть из активных списков (заказы и контракты сохраняются)', NOW(), NOW()),

  ('admin.platform_companies.btn_archive_confirm', 'en', 'Archive company', NOW(), NOW()),
  ('admin.platform_companies.btn_archive_confirm', 'hy', 'Արխիվացնել ընկերությունը', NOW(), NOW()),
  ('admin.platform_companies.btn_archive_confirm', 'ru', 'Архивировать компанию', NOW(), NOW()),

  ('admin.platform_companies.archive_title', 'en', 'Archive "{name}"?', NOW(), NOW()),
  ('admin.platform_companies.archive_title', 'hy', 'Արխիվացնե՞լ «{name}»-ը', NOW(), NOW()),
  ('admin.platform_companies.archive_title', 'ru', 'Архивировать «{name}»?', NOW(), NOW()),

  ('admin.platform_companies.archive_description', 'en', 'The company is hidden from default admin lists. Linked orders, contracts and inventory remain intact. Reversible — a super-admin can restore the row at any time. Type the company name to confirm.', NOW(), NOW()),
  ('admin.platform_companies.archive_description', 'hy', 'Ընկերությունը կթաքնվի ակտիվ ցուցակից։ Կապված պատվերները, պայմանագրերը և ինվենտարը կմնան անփոփոխ։ Հետադարձելի է՝ super admin-ը ցանկացած պահի կարող է վերականգնել։ Հաստատելու համար մուտքագրի՛ր ընկերության անունը։', NOW(), NOW()),
  ('admin.platform_companies.archive_description', 'ru', 'Компания скрывается из активных списков. Заказы, контракты и инвентарь сохраняются. Обратимо — супер-админ может восстановить запись в любое время. Введите название компании для подтверждения.', NOW(), NOW()),

  ('admin.platform_companies.archive_reason_title', 'en', 'Reason (required)', NOW(), NOW()),
  ('admin.platform_companies.archive_reason_title', 'hy', 'Պատճառը (պարտադիր)', NOW(), NOW()),
  ('admin.platform_companies.archive_reason_title', 'ru', 'Причина (обязательно)', NOW(), NOW()),

  ('admin.platform_companies.archive_reason_description', 'en', 'Recorded in the audit log and shown on hover when browsing archived companies.', NOW(), NOW()),
  ('admin.platform_companies.archive_reason_description', 'hy', 'Կարձանագրվի audit-ի մատյանում և կերևա, երբ կուրսորը պահեն արխիվացված ընկերության վրա։', NOW(), NOW()),
  ('admin.platform_companies.archive_reason_description', 'ru', 'Записывается в журнал аудита и отображается при наведении на архивированную компанию.', NOW(), NOW()),

  ('admin.platform_companies.err_archive', 'en', 'Failed to archive company', NOW(), NOW()),
  ('admin.platform_companies.err_archive', 'hy', 'Չհաջողվեց արխիվացնել ընկերությունը', NOW(), NOW()),
  ('admin.platform_companies.err_archive', 'ru', 'Не удалось архивировать компанию', NOW(), NOW()),

  -- Restore button + flow
  ('admin.platform_companies.btn_restore', 'en', 'Restore', NOW(), NOW()),
  ('admin.platform_companies.btn_restore', 'hy', 'Վերականգնել', NOW(), NOW()),
  ('admin.platform_companies.btn_restore', 'ru', 'Восстановить', NOW(), NOW()),

  ('admin.platform_companies.confirm_restore', 'en', 'Restore "{name}" from archive?', NOW(), NOW()),
  ('admin.platform_companies.confirm_restore', 'hy', 'Արխիվից վերականգնե՞լ «{name}»-ը', NOW(), NOW()),
  ('admin.platform_companies.confirm_restore', 'ru', 'Восстановить «{name}» из архива?', NOW(), NOW()),

  ('admin.platform_companies.err_restore', 'en', 'Failed to restore company', NOW(), NOW()),
  ('admin.platform_companies.err_restore', 'hy', 'Չհաջողվեց վերականգնել ընկերությունը', NOW(), NOW()),
  ('admin.platform_companies.err_restore', 'ru', 'Не удалось восстановить компанию', NOW(), NOW()),

  -- Shared
  ('admin.platform_companies.confirm_name_mismatch', 'en', 'Typed name does not match. Cancelled.', NOW(), NOW()),
  ('admin.platform_companies.confirm_name_mismatch', 'hy', 'Մուտքագրված անունը չի համընկնում։ Չեղարկված է։', NOW(), NOW()),
  ('admin.platform_companies.confirm_name_mismatch', 'ru', 'Введённое имя не совпадает. Отменено.', NOW(), NOW()),

  ('admin.platform_companies.reason_placeholder', 'en', 'Reason or ticket reference…', NOW(), NOW()),
  ('admin.platform_companies.reason_placeholder', 'hy', 'Պատճառը կամ դիմումի համարը…', NOW(), NOW()),
  ('admin.platform_companies.reason_placeholder', 'ru', 'Причина или номер заявки…', NOW(), NOW())

ON CONFLICT (key, language_code) DO UPDATE
  SET value = EXCLUDED.value,
      updated_at = NOW();
