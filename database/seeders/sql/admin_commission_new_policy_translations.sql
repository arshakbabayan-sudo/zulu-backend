SET client_encoding = 'UTF8';

-- Phase 7.4 — Translations for the "+ New commission policy" modal

INSERT INTO ui_translations (key, language_code, value, created_at, updated_at) VALUES
  ('admin.platform_commissions.btn_new', 'en', '+ New policy', NOW(), NOW()),
  ('admin.platform_commissions.btn_new', 'hy', '+ Նոր կանոն', NOW(), NOW()),
  ('admin.platform_commissions.btn_new', 'ru', '+ Новое правило', NOW(), NOW()),

  ('admin.platform_commissions.btn_create', 'en', 'Create policy', NOW(), NOW()),
  ('admin.platform_commissions.btn_create', 'hy', 'Ստեղծել կանոնը', NOW(), NOW()),
  ('admin.platform_commissions.btn_create', 'ru', 'Создать правило', NOW(), NOW()),

  ('admin.platform_commissions.new_modal_title', 'en', 'New commission policy', NOW(), NOW()),
  ('admin.platform_commissions.new_modal_title', 'hy', 'Նոր կոմիսիայի կանոն', NOW(), NOW()),
  ('admin.platform_commissions.new_modal_title', 'ru', 'Новое правило комиссии', NOW(), NOW()),

  ('admin.platform_commissions.field_company_id', 'en', 'Seller company ID', NOW(), NOW()),
  ('admin.platform_commissions.field_company_id', 'hy', 'Վաճառողի ընկերության ID', NOW(), NOW()),
  ('admin.platform_commissions.field_company_id', 'ru', 'ID компании-продавца', NOW(), NOW()),

  ('admin.platform_commissions.field_company_id_placeholder', 'en', 'e.g. 19', NOW(), NOW()),
  ('admin.platform_commissions.field_company_id_placeholder', 'hy', 'օր.՝ 19', NOW(), NOW()),
  ('admin.platform_commissions.field_company_id_placeholder', 'ru', 'напр. 19', NOW(), NOW()),

  ('admin.platform_commissions.field_service_type', 'en', 'Service type', NOW(), NOW()),
  ('admin.platform_commissions.field_service_type', 'hy', 'Ծառայության տեսակ', NOW(), NOW()),
  ('admin.platform_commissions.field_service_type', 'ru', 'Тип услуги', NOW(), NOW()),

  ('admin.platform_commissions.field_type', 'en', 'Rule type', NOW(), NOW()),
  ('admin.platform_commissions.field_type', 'hy', 'Կանոնի տեսակ', NOW(), NOW()),
  ('admin.platform_commissions.field_type', 'ru', 'Тип правила', NOW(), NOW()),

  ('admin.platform_commissions.type_percentage', 'en', 'Percentage (%)', NOW(), NOW()),
  ('admin.platform_commissions.type_percentage', 'hy', 'Տոկոս (%)', NOW(), NOW()),
  ('admin.platform_commissions.type_percentage', 'ru', 'Процент (%)', NOW(), NOW()),

  ('admin.platform_commissions.type_fixed', 'en', 'Fixed amount', NOW(), NOW()),
  ('admin.platform_commissions.type_fixed', 'hy', 'Հաստատուն գումար', NOW(), NOW()),
  ('admin.platform_commissions.type_fixed', 'ru', 'Фиксированная сумма', NOW(), NOW()),

  ('admin.platform_commissions.field_percent', 'en', 'Percentage (%)', NOW(), NOW()),
  ('admin.platform_commissions.field_percent', 'hy', 'Տոկոս (%)', NOW(), NOW()),
  ('admin.platform_commissions.field_percent', 'ru', 'Процент (%)', NOW(), NOW()),

  ('admin.platform_commissions.field_fixed_value', 'en', 'Amount', NOW(), NOW()),
  ('admin.platform_commissions.field_fixed_value', 'hy', 'Գումարը', NOW(), NOW()),
  ('admin.platform_commissions.field_fixed_value', 'ru', 'Сумма', NOW(), NOW()),

  ('admin.platform_commissions.field_currency', 'en', 'Currency', NOW(), NOW()),
  ('admin.platform_commissions.field_currency', 'hy', 'Արժույթ', NOW(), NOW()),
  ('admin.platform_commissions.field_currency', 'ru', 'Валюта', NOW(), NOW()),

  ('admin.platform_commissions.field_status', 'en', 'Status', NOW(), NOW()),
  ('admin.platform_commissions.field_status', 'hy', 'Կարգավիճակ', NOW(), NOW()),
  ('admin.platform_commissions.field_status', 'ru', 'Статус', NOW(), NOW()),

  ('admin.platform_commissions.status_active', 'en', 'Active', NOW(), NOW()),
  ('admin.platform_commissions.status_active', 'hy', 'Ակտիվ', NOW(), NOW()),
  ('admin.platform_commissions.status_active', 'ru', 'Активно', NOW(), NOW()),

  ('admin.platform_commissions.status_inactive', 'en', 'Inactive', NOW(), NOW()),
  ('admin.platform_commissions.status_inactive', 'hy', 'Անակտիվ', NOW(), NOW()),
  ('admin.platform_commissions.status_inactive', 'ru', 'Неактивно', NOW(), NOW()),

  ('admin.platform_commissions.status_scheduled', 'en', 'Scheduled', NOW(), NOW()),
  ('admin.platform_commissions.status_scheduled', 'hy', 'Պլանավորված', NOW(), NOW()),
  ('admin.platform_commissions.status_scheduled', 'ru', 'Запланировано', NOW(), NOW()),

  ('admin.platform_commissions.field_notes', 'en', 'Notes (optional)', NOW(), NOW()),
  ('admin.platform_commissions.field_notes', 'hy', 'Նշումներ (ոչ պարտադիր)', NOW(), NOW()),
  ('admin.platform_commissions.field_notes', 'ru', 'Заметки (необязательно)', NOW(), NOW()),

  ('admin.platform_commissions.err_company_required', 'en', 'Please enter the seller company ID', NOW(), NOW()),
  ('admin.platform_commissions.err_company_required', 'hy', 'Մուտքագրի՛ր վաճառողի ընկերության ID', NOW(), NOW()),
  ('admin.platform_commissions.err_company_required', 'ru', 'Введите ID компании-продавца', NOW(), NOW()),

  ('admin.platform_commissions.err_percent_invalid', 'en', 'Percentage must be between 0 and 100', NOW(), NOW()),
  ('admin.platform_commissions.err_percent_invalid', 'hy', 'Տոկոսը պետք է լինի 0–100 միջակայքում', NOW(), NOW()),
  ('admin.platform_commissions.err_percent_invalid', 'ru', 'Процент должен быть от 0 до 100', NOW(), NOW()),

  ('admin.platform_commissions.err_fixed_invalid', 'en', 'Fixed amount must be non-negative', NOW(), NOW()),
  ('admin.platform_commissions.err_fixed_invalid', 'hy', 'Հաստատուն գումարը չպետք է բացասական լինի', NOW(), NOW()),
  ('admin.platform_commissions.err_fixed_invalid', 'ru', 'Фиксированная сумма не должна быть отрицательной', NOW(), NOW()),

  -- Common helpers
  ('common.cancel', 'en', 'Cancel', NOW(), NOW()),
  ('common.cancel', 'hy', 'Չեղարկել', NOW(), NOW()),
  ('common.cancel', 'ru', 'Отмена', NOW(), NOW()),

  ('common.saving', 'en', 'Saving…', NOW(), NOW()),
  ('common.saving', 'hy', 'Պահպանվում է…', NOW(), NOW()),
  ('common.saving', 'ru', 'Сохранение…', NOW(), NOW()),

  ('common.all', 'en', 'All', NOW(), NOW()),
  ('common.all', 'hy', 'Բոլորը', NOW(), NOW()),
  ('common.all', 'ru', 'Все', NOW(), NOW())

ON CONFLICT (key, language_code) DO UPDATE
  SET value = EXCLUDED.value,
      updated_at = NOW();
