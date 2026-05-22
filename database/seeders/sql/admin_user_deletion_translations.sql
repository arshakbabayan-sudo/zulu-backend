SET client_encoding = 'UTF8';

-- Phase 7.1 — Translations for 2-button user deletion flow
-- (anonymize + super-admin hard delete) on /platform/users

INSERT INTO ui_translations (key, language_code, value, created_at, updated_at) VALUES
  -- "Ջնջել" (default anonymize button)
  ('admin.users.btn_anonymize', 'en', 'Delete', NOW(), NOW()),
  ('admin.users.btn_anonymize', 'hy', 'Ջնջել', NOW(), NOW()),
  ('admin.users.btn_anonymize', 'ru', 'Удалить', NOW(), NOW()),

  ('admin.users.btn_anonymize_tooltip', 'en', 'Anonymize user (preserves order history)', NOW(), NOW()),
  ('admin.users.btn_anonymize_tooltip', 'hy', 'Անանուն դարձնել օգտատիրոջը (պատվերները պահպանվում են)', NOW(), NOW()),
  ('admin.users.btn_anonymize_tooltip', 'ru', 'Анонимизировать пользователя (история заказов сохраняется)', NOW(), NOW()),

  ('admin.users.btn_anonymize_confirm', 'en', 'Delete user', NOW(), NOW()),
  ('admin.users.btn_anonymize_confirm', 'hy', 'Ջնջել օգտատիրոջը', NOW(), NOW()),
  ('admin.users.btn_anonymize_confirm', 'ru', 'Удалить пользователя', NOW(), NOW()),

  ('admin.users.anonymize_title', 'en', 'Delete user "{name}"?', NOW(), NOW()),
  ('admin.users.anonymize_title', 'hy', 'Ջնջե՞լ «{name}» օգտատիրոջը', NOW(), NOW()),
  ('admin.users.anonymize_title', 'ru', 'Удалить пользователя «{name}»?', NOW(), NOW()),

  ('admin.users.anonymize_description', 'en', 'The user''s name, email and phone will be replaced with an anonymous marker. Their orders and contracts will remain so financial history is preserved. This cannot be undone. Type the user''s name to confirm.', NOW(), NOW()),
  ('admin.users.anonymize_description', 'hy', 'Օգտատիրոջ անունը, էլ. փոստն ու հեռախոսը կփոխարինվեն անանուն նիշով։ Պատվերներն ու պայմանագրերը կմնան (ֆինանսական պատմությունը չի կորչի)։ Հետադարձելի չէ։ Հաստատելու համար մուտքագրի՛ր օգտատիրոջ անունը։', NOW(), NOW()),
  ('admin.users.anonymize_description', 'ru', 'Имя, e-mail и телефон будут заменены анонимной меткой. Заказы и контракты сохраняются (финансовая история не теряется). Действие необратимо. Введите имя пользователя для подтверждения.', NOW(), NOW()),

  ('admin.users.anonymize_reason_title', 'en', 'Reason (optional)', NOW(), NOW()),
  ('admin.users.anonymize_reason_title', 'hy', 'Պատճառը (ոչ պարտադիր)', NOW(), NOW()),
  ('admin.users.anonymize_reason_title', 'ru', 'Причина (необязательно)', NOW(), NOW()),

  ('admin.users.anonymize_reason_description', 'en', 'Recorded in the audit log for compliance review.', NOW(), NOW()),
  ('admin.users.anonymize_reason_description', 'hy', 'Կարձանագրվի audit-ի մատյանում (հետագայի վերանայման համար)։', NOW(), NOW()),
  ('admin.users.anonymize_reason_description', 'ru', 'Записывается в журнал аудита.', NOW(), NOW()),

  ('admin.users.err_anonymize', 'en', 'Failed to delete user', NOW(), NOW()),
  ('admin.users.err_anonymize', 'hy', 'Չհաջողվեց ջնջել օգտատիրոջը', NOW(), NOW()),
  ('admin.users.err_anonymize', 'ru', 'Не удалось удалить пользователя', NOW(), NOW()),

  -- "Ամբողջությամբ ջնջել" (super-admin hard delete button)
  ('admin.users.btn_hard_delete', 'en', 'Hard delete', NOW(), NOW()),
  ('admin.users.btn_hard_delete', 'hy', 'Ամբողջությամբ ջնջել', NOW(), NOW()),
  ('admin.users.btn_hard_delete', 'ru', 'Удалить полностью', NOW(), NOW()),

  ('admin.users.btn_hard_delete_tooltip', 'en', 'Physically remove user row (super-admin only, GDPR Article 17)', NOW(), NOW()),
  ('admin.users.btn_hard_delete_tooltip', 'hy', 'Տվյալների բազայից ֆիզիկապես հեռացնել (միայն գերադմին, GDPR-ի 17-րդ հոդված)', NOW(), NOW()),
  ('admin.users.btn_hard_delete_tooltip', 'ru', 'Физическое удаление записи (только супер-админ, GDPR ст. 17)', NOW(), NOW()),

  ('admin.users.btn_hard_delete_confirm', 'en', 'Permanently delete', NOW(), NOW()),
  ('admin.users.btn_hard_delete_confirm', 'hy', 'Ընդմիշտ ջնջել', NOW(), NOW()),
  ('admin.users.btn_hard_delete_confirm', 'ru', 'Удалить навсегда', NOW(), NOW()),

  ('admin.users.hard_delete_title', 'en', 'PERMANENTLY DELETE "{name}"?', NOW(), NOW()),
  ('admin.users.hard_delete_title', 'hy', 'ԸՆԴՄԻՇՏ ՋՆՋԵ՞Լ «{name}»-ին', NOW(), NOW()),
  ('admin.users.hard_delete_title', 'ru', 'УДАЛИТЬ «{name}» НАВСЕГДА?', NOW(), NOW()),

  ('admin.users.hard_delete_description', 'en', 'Physical removal of the user row from the database. Order/contract PII linked to this user will be anonymized. This is irreversible — use only for written GDPR Right-to-Erasure requests. Type the user''s name to confirm.', NOW(), NOW()),
  ('admin.users.hard_delete_description', 'hy', 'Տվյալների բազայից օգտատիրոջ տողի ֆիզիկական հեռացում։ Կապված պատվերների/պայմանագրերի անձնական տվյալները կանանվարկվեն։ Հետադարձելի ՉԷ — օգտագործել միայն GDPR-ի «մոռացման իրավունքի» գրավոր դիմումի դեպքում։ Հաստատելու համար մուտքագրի՛ր օգտատիրոջ անունը։', NOW(), NOW()),
  ('admin.users.hard_delete_description', 'ru', 'Физическое удаление строки пользователя из базы. Личные данные в связанных заказах/контрактах будут анонимизированы. Необратимо — только для письменных GDPR-запросов на удаление. Введите имя пользователя для подтверждения.', NOW(), NOW()),

  ('admin.users.hard_delete_reason_title', 'en', 'Reason (required)', NOW(), NOW()),
  ('admin.users.hard_delete_reason_title', 'hy', 'Պատճառը (պարտադիր)', NOW(), NOW()),
  ('admin.users.hard_delete_reason_title', 'ru', 'Причина (обязательно)', NOW(), NOW()),

  ('admin.users.hard_delete_reason_description', 'en', 'Recorded in the audit log. Reference the GDPR request ticket number if applicable.', NOW(), NOW()),
  ('admin.users.hard_delete_reason_description', 'hy', 'Կարձանագրվի audit-ի մատյանում։ Հնարավորության դեպքում նշիր GDPR-ի դիմումի համարը։', NOW(), NOW()),
  ('admin.users.hard_delete_reason_description', 'ru', 'Записывается в журнал аудита. Укажите номер GDPR-запроса, если применимо.', NOW(), NOW()),

  ('admin.users.err_hard_delete', 'en', 'Hard delete failed', NOW(), NOW()),
  ('admin.users.err_hard_delete', 'hy', 'Ընդմիշտ ջնջումը ձախողվեց', NOW(), NOW()),
  ('admin.users.err_hard_delete', 'ru', 'Полное удаление не удалось', NOW(), NOW()),

  -- Shared
  ('admin.users.confirm_name_mismatch', 'en', 'Typed name does not match. Cancelled.', NOW(), NOW()),
  ('admin.users.confirm_name_mismatch', 'hy', 'Մուտքագրված անունը չի համընկնում։ Չեղարկված է։', NOW(), NOW()),
  ('admin.users.confirm_name_mismatch', 'ru', 'Введённое имя не совпадает. Отменено.', NOW(), NOW()),

  ('admin.users.reason_placeholder', 'en', 'Reason or ticket reference…', NOW(), NOW()),
  ('admin.users.reason_placeholder', 'hy', 'Պատճառը կամ դիմումի համարը…', NOW(), NOW()),
  ('admin.users.reason_placeholder', 'ru', 'Причина или номер заявки…', NOW(), NOW())

ON CONFLICT (key, language_code) DO UPDATE
  SET value = EXCLUDED.value,
      updated_at = NOW();
