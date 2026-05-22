SET client_encoding = 'UTF8';

-- Phase 6.3 — Bookings + Package-orders are already sibling tabs under
-- the same nav group. This pass relabels them with the canonical
-- "Bookings (All)" / "Bookings (Packages)" wording from the audit roadmap.

INSERT INTO ui_translations (key, language_code, value, created_at, updated_at) VALUES
  ('admin.nav.group.bookings', 'en', 'Bookings', NOW(), NOW()),
  ('admin.nav.group.bookings', 'hy', 'Ամրագրումներ', NOW(), NOW()),
  ('admin.nav.group.bookings', 'ru', 'Бронирования', NOW(), NOW()),

  ('admin.nav.tab.all_bookings', 'en', 'All bookings', NOW(), NOW()),
  ('admin.nav.tab.all_bookings', 'hy', 'Բոլոր ամրագրումները', NOW(), NOW()),
  ('admin.nav.tab.all_bookings', 'ru', 'Все бронирования', NOW(), NOW()),

  ('admin.nav.tab.package_orders', 'en', 'Package orders', NOW(), NOW()),
  ('admin.nav.tab.package_orders', 'hy', 'Փաթեթային պատվերներ', NOW(), NOW()),
  ('admin.nav.tab.package_orders', 'ru', 'Заказы пакетов', NOW(), NOW())

ON CONFLICT (key, language_code) DO UPDATE
  SET value = EXCLUDED.value,
      updated_at = NOW();
