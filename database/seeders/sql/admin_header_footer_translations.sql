SET client_encoding = 'UTF8';

-- Phase 1.13 + 1.14 — /platform/settings/header-menu + /platform/settings/footer page translations

INSERT INTO ui_translations (key, language_code, value, created_at, updated_at) VALUES
  -- Header menu
  ('admin.header_menu.title', 'en', 'Header menu', NOW(), NOW()),
  ('admin.header_menu.title', 'hy', 'Վերին մենյու', NOW(), NOW()),
  ('admin.header_menu.title', 'ru', 'Верхнее меню', NOW(), NOW()),

  ('admin.header_menu.add_item', 'en', '+ Add item', NOW(), NOW()),
  ('admin.header_menu.add_item', 'hy', '+ Ավելացնել կետ', NOW(), NOW()),
  ('admin.header_menu.add_item', 'ru', '+ Добавить пункт', NOW(), NOW()),

  ('admin.header_menu.empty', 'en', 'No items.', NOW(), NOW()),
  ('admin.header_menu.empty', 'hy', 'Կետեր չկան։', NOW(), NOW()),
  ('admin.header_menu.empty', 'ru', 'Пунктов нет.', NOW(), NOW()),

  ('admin.header_menu.children', 'en', 'Children', NOW(), NOW()),
  ('admin.header_menu.children', 'hy', 'Ենթակետեր', NOW(), NOW()),
  ('admin.header_menu.children', 'ru', 'Подпункты', NOW(), NOW()),

  ('admin.header_menu.add_child', 'en', '+ Add child', NOW(), NOW()),
  ('admin.header_menu.add_child', 'hy', '+ Ավելացնել ենթակետ', NOW(), NOW()),
  ('admin.header_menu.add_child', 'ru', '+ Добавить подпункт', NOW(), NOW()),

  ('admin.header_menu.save_all', 'en', 'Save all', NOW(), NOW()),
  ('admin.header_menu.save_all', 'hy', 'Պահպանել ամենը', NOW(), NOW()),
  ('admin.header_menu.save_all', 'ru', 'Сохранить все', NOW(), NOW()),

  ('admin.header_menu.label_en', 'en', 'Label (EN)', NOW(), NOW()),
  ('admin.header_menu.label_en', 'hy', 'Պիտակ (EN)', NOW(), NOW()),
  ('admin.header_menu.label_en', 'ru', 'Метка (EN)', NOW(), NOW()),

  ('admin.header_menu.label_ru', 'en', 'Label (RU)', NOW(), NOW()),
  ('admin.header_menu.label_ru', 'hy', 'Պիտակ (RU)', NOW(), NOW()),
  ('admin.header_menu.label_ru', 'ru', 'Метка (RU)', NOW(), NOW()),

  ('admin.header_menu.label_hy', 'en', 'Label (HY)', NOW(), NOW()),
  ('admin.header_menu.label_hy', 'hy', 'Պիտակ (HY)', NOW(), NOW()),
  ('admin.header_menu.label_hy', 'ru', 'Метка (HY)', NOW(), NOW()),

  ('admin.header_menu.url_placeholder', 'en', '/about or https://...', NOW(), NOW()),
  ('admin.header_menu.url_placeholder', 'hy', '/about կամ https://...', NOW(), NOW()),
  ('admin.header_menu.url_placeholder', 'ru', '/about или https://...', NOW(), NOW()),

  ('admin.header_menu.icon_optional', 'en', 'Icon (optional)', NOW(), NOW()),
  ('admin.header_menu.icon_optional', 'hy', 'Նկար (ոչ պարտադիր)', NOW(), NOW()),
  ('admin.header_menu.icon_optional', 'ru', 'Иконка (необязательно)', NOW(), NOW()),

  ('admin.header_menu.icon_placeholder', 'en', 'lucide name (e.g. phone)', NOW(), NOW()),
  ('admin.header_menu.icon_placeholder', 'hy', 'lucide անուն (օր. phone)', NOW(), NOW()),
  ('admin.header_menu.icon_placeholder', 'ru', 'имя lucide (напр. phone)', NOW(), NOW()),

  ('admin.header_menu.visible', 'en', 'Visible', NOW(), NOW()),
  ('admin.header_menu.visible', 'hy', 'Տեսանելի', NOW(), NOW()),
  ('admin.header_menu.visible', 'ru', 'Видимое', NOW(), NOW()),

  ('admin.header_menu.new_tab', 'en', 'New tab', NOW(), NOW()),
  ('admin.header_menu.new_tab', 'hy', 'Նոր թաբ', NOW(), NOW()),
  ('admin.header_menu.new_tab', 'ru', 'Новая вкладка', NOW(), NOW()),

  ('admin.header_menu.move_up', 'en', 'Move up', NOW(), NOW()),
  ('admin.header_menu.move_up', 'hy', 'Տեղափոխել վերև', NOW(), NOW()),
  ('admin.header_menu.move_up', 'ru', 'Переместить вверх', NOW(), NOW()),

  ('admin.header_menu.move_down', 'en', 'Move down', NOW(), NOW()),
  ('admin.header_menu.move_down', 'hy', 'Տեղափոխել ներքև', NOW(), NOW()),
  ('admin.header_menu.move_down', 'ru', 'Переместить вниз', NOW(), NOW()),

  -- Footer
  ('admin.footer.title', 'en', 'Footer columns', NOW(), NOW()),
  ('admin.footer.title', 'hy', 'Ստորին հատվածի սյունակներ', NOW(), NOW()),
  ('admin.footer.title', 'ru', 'Колонки футера', NOW(), NOW()),

  ('admin.footer.title_short', 'en', 'Footer', NOW(), NOW()),
  ('admin.footer.title_short', 'hy', 'Ստորին հատված', NOW(), NOW()),
  ('admin.footer.title_short', 'ru', 'Футер', NOW(), NOW()),

  ('admin.footer.add_column', 'en', '+ Add column', NOW(), NOW()),
  ('admin.footer.add_column', 'hy', '+ Ավելացնել սյունակ', NOW(), NOW()),
  ('admin.footer.add_column', 'ru', '+ Добавить колонку', NOW(), NOW()),

  ('admin.footer.remove_column', 'en', 'Remove column', NOW(), NOW()),
  ('admin.footer.remove_column', 'hy', 'Հեռացնել սյունակը', NOW(), NOW()),
  ('admin.footer.remove_column', 'ru', 'Удалить колонку', NOW(), NOW()),

  ('admin.footer.title_en', 'en', 'Title (EN)', NOW(), NOW()),
  ('admin.footer.title_en', 'hy', 'Վերնագիր (EN)', NOW(), NOW()),
  ('admin.footer.title_en', 'ru', 'Заголовок (EN)', NOW(), NOW()),

  ('admin.footer.title_ru', 'en', 'Title (RU)', NOW(), NOW()),
  ('admin.footer.title_ru', 'hy', 'Վերնագիր (RU)', NOW(), NOW()),
  ('admin.footer.title_ru', 'ru', 'Заголовок (RU)', NOW(), NOW()),

  ('admin.footer.title_hy', 'en', 'Title (HY)', NOW(), NOW()),
  ('admin.footer.title_hy', 'hy', 'Վերնագիր (HY)', NOW(), NOW()),
  ('admin.footer.title_hy', 'ru', 'Заголовок (HY)', NOW(), NOW()),

  ('admin.footer.slug_optional', 'en', 'Slug (optional)', NOW(), NOW()),
  ('admin.footer.slug_optional', 'hy', 'Slug (ոչ պարտադիր)', NOW(), NOW()),
  ('admin.footer.slug_optional', 'ru', 'Slug (необязательно)', NOW(), NOW()),

  ('admin.footer.col_short', 'en', 'col', NOW(), NOW()),
  ('admin.footer.col_short', 'hy', 'սյուն', NOW(), NOW()),
  ('admin.footer.col_short', 'ru', 'кол', NOW(), NOW()),

  ('admin.footer.links', 'en', 'Links', NOW(), NOW()),
  ('admin.footer.links', 'hy', 'Հղումներ', NOW(), NOW()),
  ('admin.footer.links', 'ru', 'Ссылки', NOW(), NOW()),

  ('admin.footer.add_link', 'en', '+ Add link', NOW(), NOW()),
  ('admin.footer.add_link', 'hy', '+ Ավելացնել հղում', NOW(), NOW()),
  ('admin.footer.add_link', 'ru', '+ Добавить ссылку', NOW(), NOW())

ON CONFLICT (key, language_code) DO UPDATE
  SET value = EXCLUDED.value,
      updated_at = NOW();
