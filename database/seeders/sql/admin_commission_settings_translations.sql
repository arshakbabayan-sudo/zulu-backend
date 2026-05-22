SET client_encoding = 'UTF8';

-- Phase 1.10 — /operator/commission-settings + /statistics title translations

INSERT INTO ui_translations (key, language_code, value, created_at, updated_at) VALUES
  -- Title + subtitle
  ('admin.commission.title', 'en', 'Agent commission', NOW(), NOW()),
  ('admin.commission.title', 'hy', 'Գործակալի կոմիսիա', NOW(), NOW()),
  ('admin.commission.title', 'ru', 'Комиссия агента', NOW(), NOW()),

  ('admin.commission.subtitle', 'en', 'Configure how much commission your downstream agents earn on referred bookings.', NOW(), NOW()),
  ('admin.commission.subtitle', 'hy', 'Կարգավորիր, թե որքան կոմիսիա են ստանում քո ենթակա գործակալները ուղղորդված ամրագրումների համար։', NOW(), NOW()),
  ('admin.commission.subtitle', 'ru', 'Настройте, сколько комиссии получают ваши агенты за реферированные бронирования.', NOW(), NOW()),

  -- Errors
  ('admin.commission.err_no_operator', 'en', 'No active operator company on this user.', NOW(), NOW()),
  ('admin.commission.err_no_operator', 'hy', 'Այս օգտատիրոջ վրա ակտիվ օպերատորի ընկերություն չկա։', NOW(), NOW()),
  ('admin.commission.err_no_operator', 'ru', 'У этого пользователя нет активной компании-оператора.', NOW(), NOW()),

  ('admin.commission.err_load', 'en', 'Failed to load', NOW(), NOW()),
  ('admin.commission.err_load', 'hy', 'Չհաջողվեց բեռնել', NOW(), NOW()),
  ('admin.commission.err_load', 'ru', 'Не удалось загрузить', NOW(), NOW()),

  ('admin.commission.err_save', 'en', 'Save failed', NOW(), NOW()),
  ('admin.commission.err_save', 'hy', 'Պահպանումը ձախողվեց', NOW(), NOW()),
  ('admin.commission.err_save', 'ru', 'Не удалось сохранить', NOW(), NOW()),

  ('admin.commission.err_remove', 'en', 'Remove failed', NOW(), NOW()),
  ('admin.commission.err_remove', 'hy', 'Հեռացումը ձախողվեց', NOW(), NOW()),
  ('admin.commission.err_remove', 'ru', 'Не удалось удалить', NOW(), NOW()),

  ('admin.commission.err_invalid_agent_id', 'en', 'Pick a valid agent company id', NOW(), NOW()),
  ('admin.commission.err_invalid_agent_id', 'hy', 'Ընտրեք գործակալ ընկերության վավեր id', NOW(), NOW()),
  ('admin.commission.err_invalid_agent_id', 'ru', 'Введите корректный id компании-агента', NOW(), NOW()),

  -- States
  ('admin.commission.loading', 'en', 'Loading…', NOW(), NOW()),
  ('admin.commission.loading', 'hy', 'Բեռնվում է…', NOW(), NOW()),
  ('admin.commission.loading', 'ru', 'Загрузка…', NOW(), NOW()),

  ('admin.commission.saved_at', 'en', 'Saved at', NOW(), NOW()),
  ('admin.commission.saved_at', 'hy', 'Պահպանված է', NOW(), NOW()),
  ('admin.commission.saved_at', 'ru', 'Сохранено в', NOW(), NOW()),

  -- Sections
  ('admin.commission.default_section', 'en', 'Default for all agents', NOW(), NOW()),
  ('admin.commission.default_section', 'hy', 'Լռության պարագայում՝ բոլոր գործակալների համար', NOW(), NOW()),
  ('admin.commission.default_section', 'ru', 'По умолчанию для всех агентов', NOW(), NOW()),

  ('admin.commission.default_section_hint', 'en', 'Applied to every agent under your company unless an override below changes their rate.', NOW(), NOW()),
  ('admin.commission.default_section_hint', 'hy', 'Կիրառվում է քո ընկերության յուրաքանչյուր գործակալի վրա, եթե ներքևի override-ը չփոխի նրա տոկոսը։', NOW(), NOW()),
  ('admin.commission.default_section_hint', 'ru', 'Применяется к каждому агенту вашей компании, если ниже не задан индивидуальный override.', NOW(), NOW()),

  ('admin.commission.overrides_section', 'en', 'Per-agent overrides', NOW(), NOW()),
  ('admin.commission.overrides_section', 'hy', 'Անհատական ընտրովիներ', NOW(), NOW()),
  ('admin.commission.overrides_section', 'ru', 'Индивидуальные настройки на агента', NOW(), NOW()),

  ('admin.commission.overrides_section_hint', 'en', 'Override the default for specific agents (e.g. a strategic partner gets a higher %).', NOW(), NOW()),
  ('admin.commission.overrides_section_hint', 'hy', 'Կոնկրետ գործակալների համար փոխիր լռության արժեքը (օր. ստրատեգիական գործընկերն ավելի բարձր %)։', NOW(), NOW()),
  ('admin.commission.overrides_section_hint', 'ru', 'Переопределите значение по умолчанию для конкретных агентов (напр. стратегический партнёр получает больший %).', NOW(), NOW()),

  ('admin.commission.add_override', 'en', 'Add a per-agent override', NOW(), NOW()),
  ('admin.commission.add_override', 'hy', 'Ավելացրու անհատական ընտրովի', NOW(), NOW()),
  ('admin.commission.add_override', 'ru', 'Добавить индивидуальную настройку', NOW(), NOW()),

  -- Field labels
  ('admin.commission.field.calculation_base', 'en', 'Calculation base', NOW(), NOW()),
  ('admin.commission.field.calculation_base', 'hy', 'Հաշվարկման հիմք', NOW(), NOW()),
  ('admin.commission.field.calculation_base', 'ru', 'База расчёта', NOW(), NOW()),

  ('admin.commission.field.agent_percentage', 'en', 'Agent commission %', NOW(), NOW()),
  ('admin.commission.field.agent_percentage', 'hy', 'Գործակալի կոմիսիա %', NOW(), NOW()),
  ('admin.commission.field.agent_percentage', 'ru', 'Комиссия агента %', NOW(), NOW()),

  ('admin.commission.field.agent_percentage_hint', 'en', 'e.g. 5.0 means agent gets 5% of the calculation base', NOW(), NOW()),
  ('admin.commission.field.agent_percentage_hint', 'hy', 'օր. 5.0 նշանակում է, որ գործակալը ստանում է հաշվարկման հիմքի 5%-ը', NOW(), NOW()),
  ('admin.commission.field.agent_percentage_hint', 'ru', 'напр. 5.0 означает, что агент получает 5% от базы расчёта', NOW(), NOW()),

  ('admin.commission.field.custom_base', 'en', 'Custom base % of gross', NOW(), NOW()),
  ('admin.commission.field.custom_base', 'hy', 'Հատուկ հիմք՝ համախառն գումարի %', NOW(), NOW()),
  ('admin.commission.field.custom_base', 'ru', 'Произвольная база % от gross', NOW(), NOW()),

  ('admin.commission.field.custom_base_hint', 'en', 'Use only with the ''Custom'' base. e.g. 80 = agent''s commission is calculated against 80% of the gross.', NOW(), NOW()),
  ('admin.commission.field.custom_base_hint', 'hy', 'Օգտագործիր միայն ''Custom'' հիմքով։ Օր. 80 = գործակալի կոմիսիան հաշվարկվում է համախառն գումարի 80%-ի հիման վրա։', NOW(), NOW()),
  ('admin.commission.field.custom_base_hint', 'ru', 'Используйте только с базой ''Custom''. Напр. 80 = комиссия агента считается от 80% gross.', NOW(), NOW()),

  ('admin.commission.field.notes', 'en', 'Notes', NOW(), NOW()),
  ('admin.commission.field.notes', 'hy', 'Ծանոթագրություններ', NOW(), NOW()),
  ('admin.commission.field.notes', 'ru', 'Заметки', NOW(), NOW()),

  ('admin.commission.field.agent_company_id', 'en', 'Agent company ID', NOW(), NOW()),
  ('admin.commission.field.agent_company_id', 'hy', 'Գործակալ ընկերության ID', NOW(), NOW()),
  ('admin.commission.field.agent_company_id', 'ru', 'ID компании-агента', NOW(), NOW()),

  -- Buttons
  ('admin.commission.btn_save_default', 'en', 'Save default', NOW(), NOW()),
  ('admin.commission.btn_save_default', 'hy', 'Պահպանել լռության արժեքը', NOW(), NOW()),
  ('admin.commission.btn_save_default', 'ru', 'Сохранить значение по умолчанию', NOW(), NOW()),

  ('admin.commission.btn_save_override', 'en', 'Save override', NOW(), NOW()),
  ('admin.commission.btn_save_override', 'hy', 'Պահպանել ընտրովին', NOW(), NOW()),
  ('admin.commission.btn_save_override', 'ru', 'Сохранить override', NOW(), NOW()),

  ('admin.commission.btn_remove', 'en', 'Remove', NOW(), NOW()),
  ('admin.commission.btn_remove', 'hy', 'Հեռացնել', NOW(), NOW()),
  ('admin.commission.btn_remove', 'ru', 'Удалить', NOW(), NOW()),

  -- Table columns
  ('admin.commission.col.agent', 'en', 'Agent', NOW(), NOW()),
  ('admin.commission.col.agent', 'hy', 'Գործակալ', NOW(), NOW()),
  ('admin.commission.col.agent', 'ru', 'Агент', NOW(), NOW()),

  ('admin.commission.col.base', 'en', 'Base', NOW(), NOW()),
  ('admin.commission.col.base', 'hy', 'Հիմք', NOW(), NOW()),
  ('admin.commission.col.base', 'ru', 'База', NOW(), NOW()),

  ('admin.commission.col.percentage', 'en', 'Percentage', NOW(), NOW()),
  ('admin.commission.col.percentage', 'hy', 'Տոկոս', NOW(), NOW()),
  ('admin.commission.col.percentage', 'ru', 'Процент', NOW(), NOW()),

  ('admin.commission.empty_overrides', 'en', 'No overrides yet — every agent uses the default rate.', NOW(), NOW()),
  ('admin.commission.empty_overrides', 'hy', 'Դեռ ընտրովիներ չկան — բոլոր գործակալները օգտվում են լռության տոկոսից։', NOW(), NOW()),
  ('admin.commission.empty_overrides', 'ru', 'Override-ов пока нет — все агенты используют ставку по умолчанию.', NOW(), NOW()),

  -- /statistics title (only one if not already there)
  ('admin.operator_statistics.title', 'en', 'Operator statistics', NOW(), NOW()),
  ('admin.operator_statistics.title', 'hy', 'Օպերատորի վիճակագրություն', NOW(), NOW()),
  ('admin.operator_statistics.title', 'ru', 'Статистика оператора', NOW(), NOW())

ON CONFLICT (key, language_code) DO UPDATE
  SET value = EXCLUDED.value,
      updated_at = NOW();
