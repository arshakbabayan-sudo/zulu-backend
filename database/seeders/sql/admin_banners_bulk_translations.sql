SET client_encoding = 'UTF8';

-- Phase 7.8 — Translations for banner bulk delete + reorder UI

INSERT INTO ui_translations (key, language_code, value, created_at, updated_at) VALUES
  ('admin.banners.select_all', 'en', 'Select all banners', NOW(), NOW()),
  ('admin.banners.select_all', 'hy', 'Ընտրել բոլոր բաններները', NOW(), NOW()),
  ('admin.banners.select_all', 'ru', 'Выбрать все баннеры', NOW(), NOW()),

  ('admin.banners.btn_bulk_delete', 'en', 'Delete selected ({count})', NOW(), NOW()),
  ('admin.banners.btn_bulk_delete', 'hy', 'Ջնջել ընտրվածները ({count})', NOW(), NOW()),
  ('admin.banners.btn_bulk_delete', 'ru', 'Удалить выбранные ({count})', NOW(), NOW()),

  ('admin.banners.bulk_deleting', 'en', 'Deleting…', NOW(), NOW()),
  ('admin.banners.bulk_deleting', 'hy', 'Ջնջվում է…', NOW(), NOW()),
  ('admin.banners.bulk_deleting', 'ru', 'Удаление…', NOW(), NOW()),

  ('admin.banners.confirm_bulk_delete', 'en', 'Delete {count} banners? Linked images will be removed from storage.', NOW(), NOW()),
  ('admin.banners.confirm_bulk_delete', 'hy', 'Ջնջե՞լ {count} բաններ։ Կապված նկարները հանվելու են պահեստից։', NOW(), NOW()),
  ('admin.banners.confirm_bulk_delete', 'ru', 'Удалить {count} баннеров? Связанные изображения будут удалены из хранилища.', NOW(), NOW()),

  ('admin.banners.bulk_delete_result', 'en', 'Deleted {deleted} of {total}.', NOW(), NOW()),
  ('admin.banners.bulk_delete_result', 'hy', 'Ջնջվել է {total}-ից {deleted}-ը։', NOW(), NOW()),
  ('admin.banners.bulk_delete_result', 'ru', 'Удалено {deleted} из {total}.', NOW(), NOW()),

  ('admin.banners.move_up', 'en', 'Move up', NOW(), NOW()),
  ('admin.banners.move_up', 'hy', 'Տեղափոխել վերև', NOW(), NOW()),
  ('admin.banners.move_up', 'ru', 'Переместить вверх', NOW(), NOW()),

  ('admin.banners.move_down', 'en', 'Move down', NOW(), NOW()),
  ('admin.banners.move_down', 'hy', 'Տեղափոխել ներքև', NOW(), NOW()),
  ('admin.banners.move_down', 'ru', 'Переместить вниз', NOW(), NOW()),

  ('admin.banners.err_reorder', 'en', 'Failed to reorder banners', NOW(), NOW()),
  ('admin.banners.err_reorder', 'hy', 'Չհաջողվեց վերադասավորել բաններները', NOW(), NOW()),
  ('admin.banners.err_reorder', 'ru', 'Не удалось изменить порядок баннеров', NOW(), NOW())

ON CONFLICT (key, language_code) DO UPDATE
  SET value = EXCLUDED.value,
      updated_at = NOW();
