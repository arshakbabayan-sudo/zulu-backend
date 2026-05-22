SET client_encoding = 'UTF8';

-- Phase 1.11 — /platform/contract-templates/new + /[id] page translations

INSERT INTO ui_translations (key, language_code, value, created_at, updated_at) VALUES
  ('admin.template_form.new_title', 'en', 'New contract template', NOW(), NOW()),
  ('admin.template_form.new_title', 'hy', 'Նոր պայմանագրի ձևանմուշ', NOW(), NOW()),
  ('admin.template_form.new_title', 'ru', 'Новый шаблон договора', NOW(), NOW()),

  ('admin.template_form.new_subtitle', 'en', 'Body text supports {{placeholders}} that contracts fill in at create time', NOW(), NOW()),
  ('admin.template_form.new_subtitle', 'hy', 'Տեքստն աջակցում է {{փոփոխականներ}}, որոնք պայմանագրերը լրացնում են ստեղծման ժամանակ', NOW(), NOW()),
  ('admin.template_form.new_subtitle', 'ru', 'Тело шаблона поддерживает {{плейсхолдеры}}, которые договоры заполняют при создании', NOW(), NOW()),

  ('admin.template_form.err_name_required', 'en', 'Name is required', NOW(), NOW()),
  ('admin.template_form.err_name_required', 'hy', 'Անունը պարտադիր է', NOW(), NOW()),
  ('admin.template_form.err_name_required', 'ru', 'Название обязательно', NOW(), NOW()),

  ('admin.template_form.err_body_required', 'en', 'Body template is required', NOW(), NOW()),
  ('admin.template_form.err_body_required', 'hy', 'Տեքստի ձևանմուշը պարտադիր է', NOW(), NOW()),
  ('admin.template_form.err_body_required', 'ru', 'Тело шаблона обязательно', NOW(), NOW()),

  ('admin.template_form.err_defaults_must_be_object', 'en', 'Default variables must be a JSON object', NOW(), NOW()),
  ('admin.template_form.err_defaults_must_be_object', 'hy', 'Լռության փոփոխականները պետք է լինեն JSON օբյեկտ', NOW(), NOW()),
  ('admin.template_form.err_defaults_must_be_object', 'ru', 'Переменные по умолчанию должны быть JSON-объектом', NOW(), NOW()),

  ('admin.template_form.err_defaults_invalid_json', 'en', 'Default variables: invalid JSON', NOW(), NOW()),
  ('admin.template_form.err_defaults_invalid_json', 'hy', 'Լռության փոփոխականներ՝ սխալ JSON', NOW(), NOW()),
  ('admin.template_form.err_defaults_invalid_json', 'ru', 'Переменные по умолчанию: некорректный JSON', NOW(), NOW()),

  ('admin.template_form.section.identity', 'en', 'Identity', NOW(), NOW()),
  ('admin.template_form.section.identity', 'hy', 'Նույնականացում', NOW(), NOW()),
  ('admin.template_form.section.identity', 'ru', 'Идентификация', NOW(), NOW()),

  ('admin.template_form.section.body', 'en', 'Body template', NOW(), NOW()),
  ('admin.template_form.section.body', 'hy', 'Տեքստի ձևանմուշ', NOW(), NOW()),
  ('admin.template_form.section.body', 'ru', 'Тело шаблона', NOW(), NOW()),

  ('admin.template_form.section.defaults', 'en', 'Default variables', NOW(), NOW()),
  ('admin.template_form.section.defaults', 'hy', 'Լռության փոփոխականներ', NOW(), NOW()),
  ('admin.template_form.section.defaults', 'ru', 'Переменные по умолчанию', NOW(), NOW()),

  ('admin.template_form.name_placeholder', 'en', 'e.g. Tour Operator Platform Agreement', NOW(), NOW()),
  ('admin.template_form.name_placeholder', 'hy', 'օր. Տուր օպերատորի պլատֆորմի համաձայնագիր', NOW(), NOW()),
  ('admin.template_form.name_placeholder', 'ru', 'напр. Соглашение туроператора с платформой', NOW(), NOW()),

  ('admin.template_form.version_hint', 'en', 'e.g. 1.0, 2.0-draft', NOW(), NOW()),
  ('admin.template_form.version_hint', 'hy', 'օր. 1.0, 2.0-draft', NOW(), NOW()),
  ('admin.template_form.version_hint', 'ru', 'напр. 1.0, 2.0-draft', NOW(), NOW()),

  ('admin.template_form.body_label', 'en', 'Body text', NOW(), NOW()),
  ('admin.template_form.body_label', 'hy', 'Տեքստ', NOW(), NOW()),
  ('admin.template_form.body_label', 'ru', 'Тело', NOW(), NOW()),

  ('admin.template_form.body_hint', 'en', 'Use {{variable_name}} placeholders that get replaced when a contract is generated', NOW(), NOW()),
  ('admin.template_form.body_hint', 'hy', 'Օգտագործիր {{փոփոխական_անուն}} տեղադրիչներ, որոնք փոխարինվում են պայմանագիր ստեղծելիս', NOW(), NOW()),
  ('admin.template_form.body_hint', 'ru', 'Используйте плейсхолдеры {{имя_переменной}}, которые заменяются при создании договора', NOW(), NOW()),

  ('admin.template_form.body_placeholder', 'en', 'AGREEMENT BETWEEN {{party_a_name}} AND {{party_b_name}}\n\nThis agreement...', NOW(), NOW()),
  ('admin.template_form.body_placeholder', 'hy', 'ՀԱՄԱՁԱՅՆԱԳԻՐ {{party_a_name}}-Ի ԵՎ {{party_b_name}}-Ի ՄԻՋԵՎ\n\nՍույն համաձայնագիրը...', NOW(), NOW()),
  ('admin.template_form.body_placeholder', 'ru', 'СОГЛАШЕНИЕ МЕЖДУ {{party_a_name}} И {{party_b_name}}\n\nНастоящее соглашение...', NOW(), NOW()),

  ('admin.template_form.defaults_label', 'en', 'JSON map', NOW(), NOW()),
  ('admin.template_form.defaults_label', 'hy', 'JSON քարտեզ', NOW(), NOW()),
  ('admin.template_form.defaults_label', 'ru', 'JSON-карта', NOW(), NOW()),

  ('admin.template_form.defaults_hint', 'en', 'Default values for placeholders — used when the contract create form omits them', NOW(), NOW()),
  ('admin.template_form.defaults_hint', 'hy', 'Տեղադրիչների լռության արժեքները — օգտագործվում են, երբ պայմանագիր ստեղծելու ձևը բաց է թողնում դրանք', NOW(), NOW()),
  ('admin.template_form.defaults_hint', 'ru', 'Значения по умолчанию для плейсхолдеров — используются, когда форма создания договора их пропускает', NOW(), NOW()),

  ('admin.template_form.btn_create', 'en', 'Create template', NOW(), NOW()),
  ('admin.template_form.btn_create', 'hy', 'Ստեղծել ձևանմուշ', NOW(), NOW()),
  ('admin.template_form.btn_create', 'ru', 'Создать шаблон', NOW(), NOW()),

  ('admin.template_detail.title', 'en', 'Template detail', NOW(), NOW()),
  ('admin.template_detail.title', 'hy', 'Ձևանմուշի մանրամասներ', NOW(), NOW()),
  ('admin.template_detail.title', 'ru', 'Детали шаблона', NOW(), NOW()),

  ('admin.template_detail.err_load', 'en', 'Failed to load template', NOW(), NOW()),
  ('admin.template_detail.err_load', 'hy', 'Չհաջողվեց բեռնել ձևանմուշը', NOW(), NOW()),
  ('admin.template_detail.err_load', 'ru', 'Не удалось загрузить шаблон', NOW(), NOW()),

  ('admin.template_detail.btn_save', 'en', 'Save changes', NOW(), NOW()),
  ('admin.template_detail.btn_save', 'hy', 'Պահպանել փոփոխությունները', NOW(), NOW()),
  ('admin.template_detail.btn_save', 'ru', 'Сохранить изменения', NOW(), NOW())

ON CONFLICT (key, language_code) DO UPDATE
  SET value = EXCLUDED.value,
      updated_at = NOW();
