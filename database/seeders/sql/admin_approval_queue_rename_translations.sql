SET client_encoding = 'UTF8';

-- Phase 6.2 — Rename the "Reviews & approvals" navigation group to
-- the canonical "Approval queue" terminology used in the audit roadmap.

INSERT INTO ui_translations (key, language_code, value, created_at, updated_at) VALUES
  ('admin.nav.group.reviews_approvals', 'en', 'Approval queue', NOW(), NOW()),
  ('admin.nav.group.reviews_approvals', 'hy', 'Հաստատման հերթ', NOW(), NOW()),
  ('admin.nav.group.reviews_approvals', 'ru', 'Очередь утверждений', NOW(), NOW()),

  ('admin.nav.tab.pending_offers', 'en', 'Offers', NOW(), NOW()),
  ('admin.nav.tab.pending_offers', 'hy', 'Առաջարկներ', NOW(), NOW()),
  ('admin.nav.tab.pending_offers', 'ru', 'Предложения', NOW(), NOW()),

  ('admin.nav.tab.generic_approvals', 'en', 'Other approvals', NOW(), NOW()),
  ('admin.nav.tab.generic_approvals', 'hy', 'Այլ հաստատումներ', NOW(), NOW()),
  ('admin.nav.tab.generic_approvals', 'ru', 'Другие утверждения', NOW(), NOW())

ON CONFLICT (key, language_code) DO UPDATE
  SET value = EXCLUDED.value,
      updated_at = NOW();
