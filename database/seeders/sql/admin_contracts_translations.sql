SET client_encoding = 'UTF8';

-- Phase 1.1 — /platform/contracts page translations
-- Source: docs/roadmaps/admin-audit-roadmap-2026-05-22.md

INSERT INTO ui_translations (key, language_code, value, created_at, updated_at) VALUES
  -- Title + subtitle
  ('admin.contracts.title', 'en', 'Contracts', NOW(), NOW()),
  ('admin.contracts.title', 'hy', 'Պայմանագրեր', NOW(), NOW()),
  ('admin.contracts.title', 'ru', 'Договоры', NOW(), NOW()),

  ('admin.contracts.subtitle', 'en', 'Platform and partner contracts', NOW(), NOW()),
  ('admin.contracts.subtitle', 'hy', 'Պլատֆորմի և գործընկերների պայմանագրեր', NOW(), NOW()),
  ('admin.contracts.subtitle', 'ru', 'Договоры платформы и партнёров', NOW(), NOW()),

  -- Meta pagination labels (used in "10 total · page 1/5" line)
  ('admin.contracts.meta_total_suffix', 'en', 'total', NOW(), NOW()),
  ('admin.contracts.meta_total_suffix', 'hy', 'ընդհանուր', NOW(), NOW()),
  ('admin.contracts.meta_total_suffix', 'ru', 'всего', NOW(), NOW()),

  ('admin.contracts.meta_page_prefix', 'en', 'page', NOW(), NOW()),
  ('admin.contracts.meta_page_prefix', 'hy', 'Էջ', NOW(), NOW()),
  ('admin.contracts.meta_page_prefix', 'ru', 'Стр.', NOW(), NOW()),

  -- Buttons
  ('admin.contracts.btn_new', 'en', 'New contract', NOW(), NOW()),
  ('admin.contracts.btn_new', 'hy', 'Նոր պայմանագիր', NOW(), NOW()),
  ('admin.contracts.btn_new', 'ru', 'Новый договор', NOW(), NOW()),

  -- Filters
  ('admin.contracts.search_placeholder', 'en', 'Search by contract number', NOW(), NOW()),
  ('admin.contracts.search_placeholder', 'hy', 'Որոնել ըստ պայմանագրի համարի', NOW(), NOW()),
  ('admin.contracts.search_placeholder', 'ru', 'Поиск по номеру договора', NOW(), NOW()),

  ('admin.contracts.filter_status', 'en', 'Status', NOW(), NOW()),
  ('admin.contracts.filter_status', 'hy', 'Կարգավիճակ', NOW(), NOW()),
  ('admin.contracts.filter_status', 'ru', 'Статус', NOW(), NOW()),

  ('admin.contracts.filter_type', 'en', 'Type', NOW(), NOW()),
  ('admin.contracts.filter_type', 'hy', 'Տեսակ', NOW(), NOW()),
  ('admin.contracts.filter_type', 'ru', 'Тип', NOW(), NOW()),

  -- Table columns
  ('admin.contracts.col_type', 'en', 'Type', NOW(), NOW()),
  ('admin.contracts.col_type', 'hy', 'Տեսակ', NOW(), NOW()),
  ('admin.contracts.col_type', 'ru', 'Тип', NOW(), NOW()),

  ('admin.contracts.col_status', 'en', 'Status', NOW(), NOW()),
  ('admin.contracts.col_status', 'hy', 'Կարգավիճակ', NOW(), NOW()),
  ('admin.contracts.col_status', 'ru', 'Статус', NOW(), NOW()),

  ('admin.contracts.col_party_a', 'en', 'Party A', NOW(), NOW()),
  ('admin.contracts.col_party_a', 'hy', 'Կողմ Ա', NOW(), NOW()),
  ('admin.contracts.col_party_a', 'ru', 'Сторона А', NOW(), NOW()),

  ('admin.contracts.col_party_b', 'en', 'Party B', NOW(), NOW()),
  ('admin.contracts.col_party_b', 'hy', 'Կողմ Բ', NOW(), NOW()),
  ('admin.contracts.col_party_b', 'ru', 'Сторона Б', NOW(), NOW()),

  ('admin.contracts.col_template', 'en', 'Template', NOW(), NOW()),
  ('admin.contracts.col_template', 'hy', 'Ձևանմուշ', NOW(), NOW()),
  ('admin.contracts.col_template', 'ru', 'Шаблон', NOW(), NOW()),

  ('admin.contracts.col_effective', 'en', 'Effective', NOW(), NOW()),
  ('admin.contracts.col_effective', 'hy', 'Ուժի մեջ', NOW(), NOW()),
  ('admin.contracts.col_effective', 'ru', 'Действует с', NOW(), NOW()),

  ('admin.contracts.col_expires', 'en', 'Expires', NOW(), NOW()),
  ('admin.contracts.col_expires', 'hy', 'Ավարտվում է', NOW(), NOW()),
  ('admin.contracts.col_expires', 'ru', 'Истекает', NOW(), NOW()),

  ('admin.contracts.col_created', 'en', 'Created', NOW(), NOW()),
  ('admin.contracts.col_created', 'hy', 'Ստեղծված', NOW(), NOW()),
  ('admin.contracts.col_created', 'ru', 'Создано', NOW(), NOW()),

  -- Empty state + error
  ('admin.contracts.empty_state', 'en', 'No contracts match the current filters.', NOW(), NOW()),
  ('admin.contracts.empty_state', 'hy', 'Ընթացիկ զտիչներով պայմանագիր չի գտնվել։', NOW(), NOW()),
  ('admin.contracts.empty_state', 'ru', 'По текущим фильтрам договоров не найдено.', NOW(), NOW()),

  ('admin.contracts.err_load_failed', 'en', 'Failed to load contracts', NOW(), NOW()),
  ('admin.contracts.err_load_failed', 'hy', 'Չհաջողվեց բեռնել պայմանագրերը', NOW(), NOW()),
  ('admin.contracts.err_load_failed', 'ru', 'Не удалось загрузить договоры', NOW(), NOW())

ON CONFLICT (key, language_code) DO UPDATE
  SET value = EXCLUDED.value,
      updated_at = NOW();
