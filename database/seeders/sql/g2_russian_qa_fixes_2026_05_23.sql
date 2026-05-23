-- G2 — Russian translation QA fixes (2026-05-23)
--
-- Audit caught 14 strings with broken-word typos, English bleed-through,
-- and raw column-name leaks into user-facing labels. All fixed in
-- place. Applied to prod + cache cleared.
--
-- Categories of fix:
-- 1. Broken Russian word:   "переregenerировать" → "перегенерировать"
-- 2. Typo:                  "ежночно"            → "каждую ночь"
-- 3. Raw column name:       "entity_type"        → "тип сущности"
--                           "title_en"           → "Заголовок (EN)"
-- 4. English noun:          "Banner CMS"         → "Управление баннерами"
--                           "Try-it-out"         → «Попробовать»
-- 5. Anglicism:             "override"           → "переопределение"
--                           "gross"              → "валовая сумма"
--                           "countersign"        → "Подписать со стороны ZULU"
-- 6. Untranslated tail:     "статус меняется на terminated" → «расторгнут»
--
-- Re-run safe via UPDATE-by-key (no INSERT). After applying, run:
--   php artisan cache:forget ui_translations_ru

UPDATE ui_translations SET value = 'Чтобы перегенерировать спецификацию из backend сейчас:', updated_at = NOW()
  WHERE language_code = 'ru' AND key = 'admin.api_docs.regenerate_hint';

UPDATE ui_translations SET value = 'Спецификация OpenAPI 3.0, генерируемая каждую ночь из зарегистрированных маршрутов. «Попробовать» работает с вашим текущим токеном администратора.', updated_at = NOW()
  WHERE language_code = 'ru' AND key = 'admin.api_docs.subtitle';

UPDATE ui_translations SET value = 'Фильтр по типу сущности', updated_at = NOW()
  WHERE language_code = 'ru' AND key = 'admin.approvals.placeholder_entity_type';

UPDATE ui_translations SET value = 'Заголовок (EN)', updated_at = NOW()
  WHERE language_code = 'ru' AND key = 'admin.banners.field_title_en';
UPDATE ui_translations SET value = 'Заголовок (HY)', updated_at = NOW()
  WHERE language_code = 'ru' AND key = 'admin.banners.field_title_hy';
UPDATE ui_translations SET value = 'Заголовок (RU)', updated_at = NOW()
  WHERE language_code = 'ru' AND key = 'admin.banners.field_title_ru';

UPDATE ui_translations SET value = 'Управление баннерами', updated_at = NOW()
  WHERE language_code = 'ru' AND key = 'admin.banners.title_long';

UPDATE ui_translations SET value = 'Сохранить переопределение', updated_at = NOW()
  WHERE language_code = 'ru' AND key = 'admin.commission.btn_save_override';

UPDATE ui_translations SET value = 'Переопределений пока нет — все агенты используют ставку по умолчанию.', updated_at = NOW()
  WHERE language_code = 'ru' AND key = 'admin.commission.empty_overrides';

UPDATE ui_translations SET value = 'Применяется к каждому агенту вашей компании, если ниже не задано индивидуальное переопределение.', updated_at = NOW()
  WHERE language_code = 'ru' AND key = 'admin.commission.default_section_hint';

UPDATE ui_translations SET value = 'Произвольная база — % от валовой суммы', updated_at = NOW()
  WHERE language_code = 'ru' AND key = 'admin.commission.field.custom_base';

UPDATE ui_translations SET value = 'Используйте только с базой «Произвольная». Напр. 80 = комиссия агента считается от 80% валовой суммы.', updated_at = NOW()
  WHERE language_code = 'ru' AND key = 'admin.commission.field.custom_base_hint';

UPDATE ui_translations SET value = 'Подписать со стороны ZULU', updated_at = NOW()
  WHERE language_code = 'ru' AND key = 'admin.contract_detail.btn_countersign';

UPDATE ui_translations SET value = 'Укажите причину, которая будет записана при расторжении. Обе стороны сохраняют доступ к расторгнутому договору; статус меняется на «расторгнут».', updated_at = NOW()
  WHERE language_code = 'ru' AND key = 'admin.contract_detail.terminate_hint';
