SET client_encoding = 'UTF8';

-- Phase 7.3 — Translations for the Commission tab on /platform/companies/[id]

INSERT INTO ui_translations (key, language_code, value, created_at, updated_at) VALUES
  -- Tab label
  ('admin.platform_companies.tab_commission', 'en', 'Commission', NOW(), NOW()),
  ('admin.platform_companies.tab_commission', 'hy', 'Կոմիսիա', NOW(), NOW()),
  ('admin.platform_companies.tab_commission', 'ru', 'Комиссия', NOW(), NOW()),

  -- Default section
  ('admin.commission.default_title', 'en', 'Default commission for all agents', NOW(), NOW()),
  ('admin.commission.default_title', 'hy', 'Բոլոր գործակալների համար լռության կոմիսիա', NOW(), NOW()),
  ('admin.commission.default_title', 'ru', 'Комиссия по умолчанию для всех агентов', NOW(), NOW()),

  ('admin.commission.default_hint', 'en', 'Applied to every agent unless an override row below exists for that agent.', NOW(), NOW()),
  ('admin.commission.default_hint', 'hy', 'Կիրառվում է բոլոր գործակալների վրա, եթե ստորև առանձին տող չկա այդ գործակալի համար։', NOW(), NOW()),
  ('admin.commission.default_hint', 'ru', 'Применяется к каждому агенту, если ниже нет персональной строки для этого агента.', NOW(), NOW()),

  ('admin.commission.calculation_base', 'en', 'Calculation base', NOW(), NOW()),
  ('admin.commission.calculation_base', 'hy', 'Հաշվարկի հիմքը', NOW(), NOW()),
  ('admin.commission.calculation_base', 'ru', 'База расчёта', NOW(), NOW()),

  ('admin.commission.default_percentage', 'en', 'Percentage (%)', NOW(), NOW()),
  ('admin.commission.default_percentage', 'hy', 'Տոկոս (%)', NOW(), NOW()),
  ('admin.commission.default_percentage', 'ru', 'Процент (%)', NOW(), NOW()),

  ('admin.commission.custom_base', 'en', 'Custom base percentage (%)', NOW(), NOW()),
  ('admin.commission.custom_base', 'hy', 'Հատուկ հիմքի տոկոս (%)', NOW(), NOW()),
  ('admin.commission.custom_base', 'ru', 'Процент собственной базы (%)', NOW(), NOW()),

  ('admin.commission.notes', 'en', 'Notes (optional)', NOW(), NOW()),
  ('admin.commission.notes', 'hy', 'Նշումներ (ոչ պարտադիր)', NOW(), NOW()),
  ('admin.commission.notes', 'ru', 'Заметки (необязательно)', NOW(), NOW()),

  ('admin.commission.notes_placeholder', 'en', 'Internal note about how this default was decided…', NOW(), NOW()),
  ('admin.commission.notes_placeholder', 'hy', 'Ներքին նշում՝ ինչու է այս լռության արժեքն ընտրվել…', NOW(), NOW()),
  ('admin.commission.notes_placeholder', 'ru', 'Внутренняя заметка о выборе значения по умолчанию…', NOW(), NOW()),

  ('admin.commission.save_default', 'en', 'Save default', NOW(), NOW()),
  ('admin.commission.save_default', 'hy', 'Պահպանել', NOW(), NOW()),
  ('admin.commission.save_default', 'ru', 'Сохранить', NOW(), NOW()),

  ('admin.commission.saved_just_now', 'en', 'Saved just now', NOW(), NOW()),
  ('admin.commission.saved_just_now', 'hy', 'Հենց նոր պահպանված է', NOW(), NOW()),
  ('admin.commission.saved_just_now', 'ru', 'Только что сохранено', NOW(), NOW()),

  -- Overrides section
  ('admin.commission.overrides_title', 'en', 'Per-agent overrides', NOW(), NOW()),
  ('admin.commission.overrides_title', 'hy', 'Անհատական արժեքներ ըստ գործակալի', NOW(), NOW()),
  ('admin.commission.overrides_title', 'ru', 'Индивидуальные значения для агентов', NOW(), NOW()),

  ('admin.commission.overrides_hint', 'en', 'Override the default for specific agents — used when a partner has negotiated a different rate.', NOW(), NOW()),
  ('admin.commission.overrides_hint', 'hy', 'Լռության արժեքը փոխարինել առանձին գործակալների համար (օգտագործվում է, երբ կողմն առանձին համաձայնեցում ունի)։', NOW(), NOW()),
  ('admin.commission.overrides_hint', 'ru', 'Переопределить значение по умолчанию для конкретного агента (когда есть отдельная договорённость).', NOW(), NOW()),

  ('admin.commission.no_overrides', 'en', 'No per-agent overrides yet.', NOW(), NOW()),
  ('admin.commission.no_overrides', 'hy', 'Անհատական արժեքներ դեռ չեն ստեղծվել։', NOW(), NOW()),
  ('admin.commission.no_overrides', 'ru', 'Индивидуальные значения ещё не созданы.', NOW(), NOW()),

  ('admin.commission.agent', 'en', 'Agent', NOW(), NOW()),
  ('admin.commission.agent', 'hy', 'Գործակալ', NOW(), NOW()),
  ('admin.commission.agent', 'ru', 'Агент', NOW(), NOW()),

  ('admin.commission.actions', 'en', 'Actions', NOW(), NOW()),
  ('admin.commission.actions', 'hy', 'Գործողություններ', NOW(), NOW()),
  ('admin.commission.actions', 'ru', 'Действия', NOW(), NOW()),

  ('admin.commission.delete', 'en', 'Delete', NOW(), NOW()),
  ('admin.commission.delete', 'hy', 'Ջնջել', NOW(), NOW()),
  ('admin.commission.delete', 'ru', 'Удалить', NOW(), NOW()),

  ('admin.commission.confirm_delete_override', 'en', 'Delete override for "{name}"?', NOW(), NOW()),
  ('admin.commission.confirm_delete_override', 'hy', 'Ջնջե՞լ «{name}»-ի անհատական արժեքը', NOW(), NOW()),
  ('admin.commission.confirm_delete_override', 'ru', 'Удалить персональное значение для «{name}»?', NOW(), NOW()),

  ('admin.commission.add_override', 'en', 'Add per-agent override', NOW(), NOW()),
  ('admin.commission.add_override', 'hy', 'Ավելացնել անհատական արժեք', NOW(), NOW()),
  ('admin.commission.add_override', 'ru', 'Добавить персональное значение', NOW(), NOW()),

  ('admin.commission.add_override_btn', 'en', '+ Add override', NOW(), NOW()),
  ('admin.commission.add_override_btn', 'hy', '+ Ավելացնել', NOW(), NOW()),
  ('admin.commission.add_override_btn', 'ru', '+ Добавить', NOW(), NOW()),

  ('admin.commission.agent_company_id', 'en', 'Agent company ID', NOW(), NOW()),
  ('admin.commission.agent_company_id', 'hy', 'Գործակալի ընկերության ID', NOW(), NOW()),
  ('admin.commission.agent_company_id', 'ru', 'ID компании агента', NOW(), NOW()),

  ('admin.commission.agent_id_placeholder', 'en', 'e.g. 19', NOW(), NOW()),
  ('admin.commission.agent_id_placeholder', 'hy', 'օր.՝ 19', NOW(), NOW()),
  ('admin.commission.agent_id_placeholder', 'ru', 'напр. 19', NOW(), NOW()),

  -- Shared
  ('admin.commission.loading', 'en', 'Loading commission settings…', NOW(), NOW()),
  ('admin.commission.loading', 'hy', 'Բեռնում ենք կոմիսիայի կարգավորումները…', NOW(), NOW()),
  ('admin.commission.loading', 'ru', 'Загрузка настроек комиссии…', NOW(), NOW()),

  ('admin.commission.err_load', 'en', 'Failed to load commission settings', NOW(), NOW()),
  ('admin.commission.err_load', 'hy', 'Չհաջողվեց բեռնել կոմիսիայի կարգավորումները', NOW(), NOW()),
  ('admin.commission.err_load', 'ru', 'Не удалось загрузить настройки комиссии', NOW(), NOW()),

  ('admin.commission.err_save', 'en', 'Failed to save commission settings', NOW(), NOW()),
  ('admin.commission.err_save', 'hy', 'Չհաջողվեց պահպանել կոմիսիայի կարգավորումները', NOW(), NOW()),
  ('admin.commission.err_save', 'ru', 'Не удалось сохранить настройки комиссии', NOW(), NOW()),

  ('admin.commission.err_agent_id', 'en', 'Please enter a valid agent company ID', NOW(), NOW()),
  ('admin.commission.err_agent_id', 'hy', 'Մուտքագրի՛ր գործակալի ընկերության վավեր ID', NOW(), NOW()),
  ('admin.commission.err_agent_id', 'ru', 'Введите корректный ID компании агента', NOW(), NOW())

ON CONFLICT (key, language_code) DO UPDATE
  SET value = EXCLUDED.value,
      updated_at = NOW();
