SET client_encoding = 'UTF8';

-- Phase 8 polish translations
-- 8.6 — reject reason optional on seller-applications
-- 8.7 — translations button tooltip on companies

INSERT INTO ui_translations (key, language_code, value, created_at, updated_at) VALUES
  -- 8.6
  ('admin.seller_applications.reject_reason_optional_hint', 'en', 'Reason is optional — leave blank if rejecting without a written reason.', NOW(), NOW()),
  ('admin.seller_applications.reject_reason_optional_hint', 'hy', 'Պատճառը պարտադիր չէ — դատարկ թողի՛ր, եթե մերժում ես առանց գրավոր պատճառի։', NOW(), NOW()),
  ('admin.seller_applications.reject_reason_optional_hint', 'ru', 'Причина не обязательна — оставьте пустым, если отклоняете без письменной причины.', NOW(), NOW()),

  ('admin.seller_applications.reject_reason_placeholder', 'en', 'Reason (optional)…', NOW(), NOW()),
  ('admin.seller_applications.reject_reason_placeholder', 'hy', 'Պատճառը (ոչ պարտադիր)…', NOW(), NOW()),
  ('admin.seller_applications.reject_reason_placeholder', 'ru', 'Причина (необязательно)…', NOW(), NOW()),

  ('admin.seller_applications.btn_reject', 'en', 'Reject application', NOW(), NOW()),
  ('admin.seller_applications.btn_reject', 'hy', 'Մերժել դիմումը', NOW(), NOW()),
  ('admin.seller_applications.btn_reject', 'ru', 'Отклонить заявку', NOW(), NOW()),

  -- 8.7
  ('admin.platform_companies.translations_tooltip', 'en', 'Edit company description and address in EN / RU / HY (shown on public pages)', NOW(), NOW()),
  ('admin.platform_companies.translations_tooltip', 'hy', 'Խմբագրել ընկերության նկարագիրն ու հասցեն EN / RU / HY լեզուներով (հանրային էջերում երևող)', NOW(), NOW()),
  ('admin.platform_companies.translations_tooltip', 'ru', 'Редактировать описание и адрес компании на EN / RU / HY (отображается на публичных страницах)', NOW(), NOW())

ON CONFLICT (key, language_code) DO UPDATE
  SET value = EXCLUDED.value,
      updated_at = NOW();
