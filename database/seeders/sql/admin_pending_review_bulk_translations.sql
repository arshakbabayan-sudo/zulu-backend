SET client_encoding = 'UTF8';

-- Phase 7.5 — Translations for bulk-approve UI on /platform/pending-review

INSERT INTO ui_translations (key, language_code, value, created_at, updated_at) VALUES
  ('admin.pending_review.btn_bulk_approve', 'en', 'Approve selected ({count})', NOW(), NOW()),
  ('admin.pending_review.btn_bulk_approve', 'hy', 'Հաստատել ընտրվածները ({count})', NOW(), NOW()),
  ('admin.pending_review.btn_bulk_approve', 'ru', 'Утвердить выбранные ({count})', NOW(), NOW()),

  ('admin.pending_review.bulk_approving', 'en', 'Approving…', NOW(), NOW()),
  ('admin.pending_review.bulk_approving', 'hy', 'Հաստատվում է…', NOW(), NOW()),
  ('admin.pending_review.bulk_approving', 'ru', 'Утверждение…', NOW(), NOW()),

  ('admin.pending_review.confirm_bulk_approve', 'en', 'Approve {count} offers?', NOW(), NOW()),
  ('admin.pending_review.confirm_bulk_approve', 'hy', 'Հաստատե՞լ {count} առաջարկ', NOW(), NOW()),
  ('admin.pending_review.confirm_bulk_approve', 'ru', 'Утвердить {count} предложений?', NOW(), NOW()),

  ('admin.pending_review.bulk_approve_result', 'en', 'Approved {approved} of {total}.', NOW(), NOW()),
  ('admin.pending_review.bulk_approve_result', 'hy', 'Հաստատվել է {total}-ից {approved}-ը։', NOW(), NOW()),
  ('admin.pending_review.bulk_approve_result', 'ru', 'Утверждено {approved} из {total}.', NOW(), NOW()),

  ('admin.pending_review.select_all_on_page', 'en', 'Select all on this page', NOW(), NOW()),
  ('admin.pending_review.select_all_on_page', 'hy', 'Ընտրել այս էջի բոլորը', NOW(), NOW()),
  ('admin.pending_review.select_all_on_page', 'ru', 'Выбрать все на этой странице', NOW(), NOW())

ON CONFLICT (key, language_code) DO UPDATE
  SET value = EXCLUDED.value,
      updated_at = NOW();
