SET client_encoding = 'UTF8';

-- Phase 1.2 — /platform/contract-templates page translations
-- Source: docs/roadmaps/admin-audit-roadmap-2026-05-22.md

INSERT INTO ui_translations (key, language_code, value, created_at, updated_at) VALUES
  -- Title + subtitle suffix
  ('admin.contract_templates.title', 'en', 'Contract templates', NOW(), NOW()),
  ('admin.contract_templates.title', 'hy', 'Պայմանագրի ձևանմուշներ', NOW(), NOW()),
  ('admin.contract_templates.title', 'ru', 'Шаблоны договоров', NOW(), NOW()),

  ('admin.contract_templates.meta_count_suffix', 'en', 'template(s)', NOW(), NOW()),
  ('admin.contract_templates.meta_count_suffix', 'hy', 'ձևանմուշ', NOW(), NOW()),
  ('admin.contract_templates.meta_count_suffix', 'ru', 'шаблон(ов)', NOW(), NOW()),

  -- Button
  ('admin.contract_templates.btn_new', 'en', 'New template', NOW(), NOW()),
  ('admin.contract_templates.btn_new', 'hy', 'Նոր ձևանմուշ', NOW(), NOW()),
  ('admin.contract_templates.btn_new', 'ru', 'Новый шаблон', NOW(), NOW()),

  -- Filters
  ('admin.contract_templates.filter_type', 'en', 'Type', NOW(), NOW()),
  ('admin.contract_templates.filter_type', 'hy', 'Տեսակ', NOW(), NOW()),
  ('admin.contract_templates.filter_type', 'ru', 'Тип', NOW(), NOW()),

  ('admin.contract_templates.filter_language', 'en', 'Language', NOW(), NOW()),
  ('admin.contract_templates.filter_language', 'hy', 'Լեզու', NOW(), NOW()),
  ('admin.contract_templates.filter_language', 'ru', 'Язык', NOW(), NOW()),

  -- Table columns
  ('admin.contract_templates.col_name', 'en', 'Name', NOW(), NOW()),
  ('admin.contract_templates.col_name', 'hy', 'Անուն', NOW(), NOW()),
  ('admin.contract_templates.col_name', 'ru', 'Название', NOW(), NOW()),

  ('admin.contract_templates.col_type', 'en', 'Type', NOW(), NOW()),
  ('admin.contract_templates.col_type', 'hy', 'Տեսակ', NOW(), NOW()),
  ('admin.contract_templates.col_type', 'ru', 'Тип', NOW(), NOW()),

  ('admin.contract_templates.col_language', 'en', 'Language', NOW(), NOW()),
  ('admin.contract_templates.col_language', 'hy', 'Լեզու', NOW(), NOW()),
  ('admin.contract_templates.col_language', 'ru', 'Язык', NOW(), NOW()),

  ('admin.contract_templates.col_version', 'en', 'Version', NOW(), NOW()),
  ('admin.contract_templates.col_version', 'hy', 'Տարբերակ', NOW(), NOW()),
  ('admin.contract_templates.col_version', 'ru', 'Версия', NOW(), NOW()),

  ('admin.contract_templates.col_published', 'en', 'Published', NOW(), NOW()),
  ('admin.contract_templates.col_published', 'hy', 'Հրապարակված', NOW(), NOW()),
  ('admin.contract_templates.col_published', 'ru', 'Опубликовано', NOW(), NOW()),

  ('admin.contract_templates.col_updated', 'en', 'Updated', NOW(), NOW()),
  ('admin.contract_templates.col_updated', 'hy', 'Թարմացված', NOW(), NOW()),
  ('admin.contract_templates.col_updated', 'ru', 'Обновлено', NOW(), NOW()),

  -- Status pills
  ('admin.contract_templates.status_published', 'en', 'Published', NOW(), NOW()),
  ('admin.contract_templates.status_published', 'hy', 'Հրապարակված', NOW(), NOW()),
  ('admin.contract_templates.status_published', 'ru', 'Опубликован', NOW(), NOW()),

  ('admin.contract_templates.status_draft', 'en', 'Draft', NOW(), NOW()),
  ('admin.contract_templates.status_draft', 'hy', 'Սևագիր', NOW(), NOW()),
  ('admin.contract_templates.status_draft', 'ru', 'Черновик', NOW(), NOW()),

  -- Empty state
  ('admin.contract_templates.empty_state', 'en', 'No templates yet.', NOW(), NOW()),
  ('admin.contract_templates.empty_state', 'hy', 'Դեռ ձևանմուշներ չկան։', NOW(), NOW()),
  ('admin.contract_templates.empty_state', 'ru', 'Шаблонов пока нет.', NOW(), NOW()),

  ('admin.contract_templates.empty_state_cta', 'en', 'Create the first one', NOW(), NOW()),
  ('admin.contract_templates.empty_state_cta', 'hy', 'Ստեղծել առաջինը', NOW(), NOW()),
  ('admin.contract_templates.empty_state_cta', 'ru', 'Создать первый', NOW(), NOW()),

  -- Error
  ('admin.contract_templates.err_load_failed', 'en', 'Failed to load templates', NOW(), NOW()),
  ('admin.contract_templates.err_load_failed', 'hy', 'Չհաջողվեց բեռնել ձևանմուշները', NOW(), NOW()),
  ('admin.contract_templates.err_load_failed', 'ru', 'Не удалось загрузить шаблоны', NOW(), NOW())

ON CONFLICT (key, language_code) DO UPDATE
  SET value = EXCLUDED.value,
      updated_at = NOW();
