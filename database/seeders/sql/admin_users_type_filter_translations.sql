SET client_encoding = 'UTF8';

-- Phase 6.4 — Type filter on /platform/users (merges customers/staff/unverified)

INSERT INTO ui_translations (key, language_code, value, created_at, updated_at) VALUES
  ('admin.users.type_all', 'en', 'All users', NOW(), NOW()),
  ('admin.users.type_all', 'hy', 'Բոլոր օգտատերերը', NOW(), NOW()),
  ('admin.users.type_all', 'ru', 'Все пользователи', NOW(), NOW()),

  ('admin.users.type_customers', 'en', 'B2C customers', NOW(), NOW()),
  ('admin.users.type_customers', 'hy', 'B2C հաճախորդներ', NOW(), NOW()),
  ('admin.users.type_customers', 'ru', 'B2C клиенты', NOW(), NOW()),

  ('admin.users.type_staff', 'en', 'Staff (operator / agent / admin)', NOW(), NOW()),
  ('admin.users.type_staff', 'hy', 'Աշխատակիցներ (օպերատոր / գործակալ / ադմին)', NOW(), NOW()),
  ('admin.users.type_staff', 'ru', 'Сотрудники (оператор / агент / админ)', NOW(), NOW()),

  ('admin.users.type_unverified', 'en', 'Unverified accounts', NOW(), NOW()),
  ('admin.users.type_unverified', 'hy', 'Չհաստատված հաշիվներ', NOW(), NOW()),
  ('admin.users.type_unverified', 'ru', 'Неподтверждённые аккаунты', NOW(), NOW())

ON CONFLICT (key, language_code) DO UPDATE
  SET value = EXCLUDED.value,
      updated_at = NOW();
