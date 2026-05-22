SET client_encoding = 'UTF8';

-- Phase 1.11 — /platform/contracts/new page translations

INSERT INTO ui_translations (key, language_code, value, created_at, updated_at) VALUES
  ('common.back', 'en', 'Back', NOW(), NOW()),
  ('common.back', 'hy', 'Հետ', NOW(), NOW()),
  ('common.back', 'ru', 'Назад', NOW(), NOW()),

  ('admin.contract_new.title', 'en', 'New contract', NOW(), NOW()),
  ('admin.contract_new.title', 'hy', 'Նոր պայմանագիր', NOW(), NOW()),
  ('admin.contract_new.title', 'ru', 'Новый договор', NOW(), NOW()),

  ('admin.contract_new.subtitle', 'en', 'Generate a contract from a template and assign both parties', NOW(), NOW()),
  ('admin.contract_new.subtitle', 'hy', 'Ստեղծիր պայմանագիր ձևանմուշից և ընտրիր երկու կողմերն էլ', NOW(), NOW()),
  ('admin.contract_new.subtitle', 'ru', 'Создайте договор из шаблона и назначьте обе стороны', NOW(), NOW()),

  ('admin.contract_new.err_load_options', 'en', 'Failed to load options', NOW(), NOW()),
  ('admin.contract_new.err_load_options', 'hy', 'Չհաջողվեց բեռնել տարբերակները', NOW(), NOW()),
  ('admin.contract_new.err_load_options', 'ru', 'Не удалось загрузить варианты', NOW(), NOW()),

  ('admin.contract_new.err_pick_template', 'en', 'Pick a template', NOW(), NOW()),
  ('admin.contract_new.err_pick_template', 'hy', 'Ընտրեք ձևանմուշ', NOW(), NOW()),
  ('admin.contract_new.err_pick_template', 'ru', 'Выберите шаблон', NOW(), NOW()),

  ('admin.contract_new.err_party_b_required', 'en', 'Party B (partner) is required', NOW(), NOW()),
  ('admin.contract_new.err_party_b_required', 'hy', 'Կողմ Բ-ն (գործընկեր) պարտադիր է', NOW(), NOW()),
  ('admin.contract_new.err_party_b_required', 'ru', 'Сторона Б (партнёр) обязательна', NOW(), NOW()),

  ('admin.contract_new.err_party_a_required', 'en', 'Party A is required for partner-type contracts', NOW(), NOW()),
  ('admin.contract_new.err_party_a_required', 'hy', 'Կողմ Ա-ն պարտադիր է գործընկերային պայմանագրերի համար', NOW(), NOW()),
  ('admin.contract_new.err_party_a_required', 'ru', 'Сторона А обязательна для договоров партнёрского типа', NOW(), NOW()),

  ('admin.contract_new.err_create', 'en', 'Create failed', NOW(), NOW()),
  ('admin.contract_new.err_create', 'hy', 'Ստեղծումը ձախողվեց', NOW(), NOW()),
  ('admin.contract_new.err_create', 'ru', 'Не удалось создать', NOW(), NOW()),

  ('admin.contract_new.section.template_parties', 'en', 'Template & parties', NOW(), NOW()),
  ('admin.contract_new.section.template_parties', 'hy', 'Ձևանմուշ և կողմեր', NOW(), NOW()),
  ('admin.contract_new.section.template_parties', 'ru', 'Шаблон и стороны', NOW(), NOW()),

  ('admin.contract_new.section.schedule', 'en', 'Schedule', NOW(), NOW()),
  ('admin.contract_new.section.schedule', 'hy', 'Ժամանակացույց', NOW(), NOW()),
  ('admin.contract_new.section.schedule', 'ru', 'Расписание', NOW(), NOW()),

  ('admin.contract_new.section.variables', 'en', 'Variables & clauses (JSON)', NOW(), NOW()),
  ('admin.contract_new.section.variables', 'hy', 'Փոփոխականներ և կետեր (JSON)', NOW(), NOW()),
  ('admin.contract_new.section.variables', 'ru', 'Переменные и пункты (JSON)', NOW(), NOW()),

  ('admin.contract_new.section.variables_hint', 'en', 'Provide values as JSON objects. Variables fill template placeholders; commission / payment / cancellation override template defaults for this contract.', NOW(), NOW()),
  ('admin.contract_new.section.variables_hint', 'hy', 'Տրամադրիր արժեքները որպես JSON օբյեկտներ։ Փոփոխականները լրացնում են ձևանմուշի տեղադրիչները; կոմիսիա / վճարում / չեղարկում փոխարինում են ձևանմուշի լռության արժեքները։', NOW(), NOW()),
  ('admin.contract_new.section.variables_hint', 'ru', 'Укажите значения как JSON-объекты. Переменные заполняют плейсхолдеры шаблона; комиссия/оплата/отмена переопределяют значения шаблона.', NOW(), NOW()),

  ('admin.contract_new.field.template', 'en', 'Template', NOW(), NOW()),
  ('admin.contract_new.field.template', 'hy', 'Ձևանմուշ', NOW(), NOW()),
  ('admin.contract_new.field.template', 'ru', 'Шаблон', NOW(), NOW()),

  ('admin.contract_new.placeholder.pick_template', 'en', 'Pick a template', NOW(), NOW()),
  ('admin.contract_new.placeholder.pick_template', 'hy', 'Ընտրեք ձևանմուշ', NOW(), NOW()),
  ('admin.contract_new.placeholder.pick_template', 'ru', 'Выберите шаблон', NOW(), NOW()),

  ('admin.contract_new.placeholder.pick_company', 'en', 'Pick a company', NOW(), NOW()),
  ('admin.contract_new.placeholder.pick_company', 'hy', 'Ընտրեք ընկերություն', NOW(), NOW()),
  ('admin.contract_new.placeholder.pick_company', 'ru', 'Выберите компанию', NOW(), NOW()),

  ('admin.contract_new.unnamed_company', 'en', '(unnamed)', NOW(), NOW()),
  ('admin.contract_new.unnamed_company', 'hy', '(անանուն)', NOW(), NOW()),
  ('admin.contract_new.unnamed_company', 'ru', '(без названия)', NOW(), NOW()),

  ('admin.contract_new.template_type_label', 'en', 'Template type', NOW(), NOW()),
  ('admin.contract_new.template_type_label', 'hy', 'Ձևանմուշի տեսակ', NOW(), NOW()),
  ('admin.contract_new.template_type_label', 'ru', 'Тип шаблона', NOW(), NOW()),

  ('admin.contract_new.party_a_zulu_note', 'en', 'Party A will be ZULU (skip selection).', NOW(), NOW()),
  ('admin.contract_new.party_a_zulu_note', 'hy', 'Կողմ Ա-ն կլինի ZULU (բաց թողեք ընտրությունը)։', NOW(), NOW()),
  ('admin.contract_new.party_a_zulu_note', 'ru', 'Сторона А будет ZULU (пропустите выбор).', NOW(), NOW()),

  ('admin.contract_new.party_both_partners_note', 'en', 'Both parties are partner companies.', NOW(), NOW()),
  ('admin.contract_new.party_both_partners_note', 'hy', 'Երկու կողմերն էլ գործընկեր ընկերություններ են։', NOW(), NOW()),
  ('admin.contract_new.party_both_partners_note', 'ru', 'Обе стороны — компании-партнёры.', NOW(), NOW()),

  ('admin.contract_new.field.party_a', 'en', 'Party A', NOW(), NOW()),
  ('admin.contract_new.field.party_a', 'hy', 'Կողմ Ա', NOW(), NOW()),
  ('admin.contract_new.field.party_a', 'ru', 'Сторона А', NOW(), NOW()),

  ('admin.contract_new.field.party_b', 'en', 'Party B (partner)', NOW(), NOW()),
  ('admin.contract_new.field.party_b', 'hy', 'Կողմ Բ (գործընկեր)', NOW(), NOW()),
  ('admin.contract_new.field.party_b', 'ru', 'Сторона Б (партнёр)', NOW(), NOW()),

  ('admin.contract_new.field.language', 'en', 'Language', NOW(), NOW()),
  ('admin.contract_new.field.language', 'hy', 'Լեզու', NOW(), NOW()),
  ('admin.contract_new.field.language', 'ru', 'Язык', NOW(), NOW()),

  ('admin.contract_new.field.effective_date', 'en', 'Effective date', NOW(), NOW()),
  ('admin.contract_new.field.effective_date', 'hy', 'Ուժի մեջ մտնելու ամսաթիվ', NOW(), NOW()),
  ('admin.contract_new.field.effective_date', 'ru', 'Дата вступления в силу', NOW(), NOW()),

  ('admin.contract_new.field.expiry_date', 'en', 'Expiry date', NOW(), NOW()),
  ('admin.contract_new.field.expiry_date', 'hy', 'Ավարտի ամսաթիվ', NOW(), NOW()),
  ('admin.contract_new.field.expiry_date', 'ru', 'Дата окончания', NOW(), NOW()),

  ('admin.contract_new.field.termination_notice_days', 'en', 'Termination notice (days)', NOW(), NOW()),
  ('admin.contract_new.field.termination_notice_days', 'hy', 'Դադարեցման ծանուցում (օրեր)', NOW(), NOW()),
  ('admin.contract_new.field.termination_notice_days', 'ru', 'Уведомление о расторжении (дни)', NOW(), NOW()),

  ('admin.contract_new.field.termination_notice_days_hint', 'en', 'How many days of notice are required to terminate', NOW(), NOW()),
  ('admin.contract_new.field.termination_notice_days_hint', 'hy', 'Քանի օր նախապես պետք է ծանուցել դադարեցման մասին', NOW(), NOW()),
  ('admin.contract_new.field.termination_notice_days_hint', 'ru', 'За сколько дней нужно уведомить о расторжении', NOW(), NOW()),

  ('admin.contract_new.field.auto_renew', 'en', 'Auto-renew', NOW(), NOW()),
  ('admin.contract_new.field.auto_renew', 'hy', 'Ինքնավերանորոգում', NOW(), NOW()),
  ('admin.contract_new.field.auto_renew', 'ru', 'Авто-продление', NOW(), NOW()),

  ('admin.contract_new.field.auto_renew_hint', 'en', 'Automatically renew on expiry date', NOW(), NOW()),
  ('admin.contract_new.field.auto_renew_hint', 'hy', 'Ինքնաբերաբար նորոգել ավարտի ամսաթվին', NOW(), NOW()),
  ('admin.contract_new.field.auto_renew_hint', 'ru', 'Автоматически продлевать в дату окончания', NOW(), NOW()),

  ('admin.contract_new.field.variables', 'en', 'Variables', NOW(), NOW()),
  ('admin.contract_new.field.variables', 'hy', 'Փոփոխականներ', NOW(), NOW()),
  ('admin.contract_new.field.variables', 'ru', 'Переменные', NOW(), NOW()),

  ('admin.contract_new.field.commission_clause', 'en', 'Commission clause', NOW(), NOW()),
  ('admin.contract_new.field.commission_clause', 'hy', 'Կոմիսիայի կետ', NOW(), NOW()),
  ('admin.contract_new.field.commission_clause', 'ru', 'Пункт о комиссии', NOW(), NOW()),

  ('admin.contract_new.field.payment_terms', 'en', 'Payment terms', NOW(), NOW()),
  ('admin.contract_new.field.payment_terms', 'hy', 'Վճարման պայմաններ', NOW(), NOW()),
  ('admin.contract_new.field.payment_terms', 'ru', 'Условия оплаты', NOW(), NOW()),

  ('admin.contract_new.field.cancellation_policy', 'en', 'Cancellation policy', NOW(), NOW()),
  ('admin.contract_new.field.cancellation_policy', 'hy', 'Չեղարկման կանոն', NOW(), NOW()),
  ('admin.contract_new.field.cancellation_policy', 'ru', 'Правило отмены', NOW(), NOW()),

  ('admin.contract_new.btn_create', 'en', 'Create contract', NOW(), NOW()),
  ('admin.contract_new.btn_create', 'hy', 'Ստեղծել պայմանագիր', NOW(), NOW()),
  ('admin.contract_new.btn_create', 'ru', 'Создать договор', NOW(), NOW()),

  ('admin.contract_new.btn_creating', 'en', 'Creating…', NOW(), NOW()),
  ('admin.contract_new.btn_creating', 'hy', 'Ստեղծվում է…', NOW(), NOW()),
  ('admin.contract_new.btn_creating', 'ru', 'Создаётся…', NOW(), NOW())

ON CONFLICT (key, language_code) DO UPDATE
  SET value = EXCLUDED.value,
      updated_at = NOW();
