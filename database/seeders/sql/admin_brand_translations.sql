SET client_encoding = 'UTF8';

-- Phase 1.12 — /platform/settings/brand page translations

INSERT INTO ui_translations (key, language_code, value, created_at, updated_at) VALUES
  ('admin.brand.title', 'en', 'Brand settings', NOW(), NOW()),
  ('admin.brand.title', 'hy', 'Բրենդի կարգավորումներ', NOW(), NOW()),
  ('admin.brand.title', 'ru', 'Настройки бренда', NOW(), NOW()),

  ('admin.brand.subtitle', 'en', 'For editing site logo, contact and social links', NOW(), NOW()),
  ('admin.brand.subtitle', 'hy', 'Կայքի լոգոյի, կոնտակտի և սոցիալական հղումների խմբագրման համար', NOW(), NOW()),
  ('admin.brand.subtitle', 'ru', 'Для редактирования логотипа, контактов и социальных ссылок сайта', NOW(), NOW()),

  ('admin.brand.saved', 'en', 'Saved.', NOW(), NOW()),
  ('admin.brand.saved', 'hy', 'Պահպանված է։', NOW(), NOW()),
  ('admin.brand.saved', 'ru', 'Сохранено.', NOW(), NOW()),

  ('admin.brand.section.imagery', 'en', 'Brand imagery', NOW(), NOW()),
  ('admin.brand.section.imagery', 'hy', 'Բրենդի պատկերներ', NOW(), NOW()),
  ('admin.brand.section.imagery', 'ru', 'Изображения бренда', NOW(), NOW()),

  ('admin.brand.section.imagery_hint', 'en', 'Logo / emblem / favicon (browser tab icon)', NOW(), NOW()),
  ('admin.brand.section.imagery_hint', 'hy', 'Լոգո / էմբլեմ / favicon (բրաուզերի թաբի նկար)', NOW(), NOW()),
  ('admin.brand.section.imagery_hint', 'ru', 'Логотип / эмблема / favicon (значок вкладки браузера)', NOW(), NOW()),

  ('admin.brand.field.logo', 'en', 'Logo (full wordmark)', NOW(), NOW()),
  ('admin.brand.field.logo', 'hy', 'Լոգո (ամբողջ բառանշան)', NOW(), NOW()),
  ('admin.brand.field.logo', 'ru', 'Логотип (полный wordmark)', NOW(), NOW()),

  ('admin.brand.field.emblem', 'en', 'Emblem (compact icon)', NOW(), NOW()),
  ('admin.brand.field.emblem', 'hy', 'Էմբլեմ (կոմպակտ նկար)', NOW(), NOW()),
  ('admin.brand.field.emblem', 'ru', 'Эмблема (компактный значок)', NOW(), NOW()),

  ('admin.brand.field.favicon', 'en', 'Favicon (browser tab)', NOW(), NOW()),
  ('admin.brand.field.favicon', 'hy', 'Favicon (բրաուզերի թաբ)', NOW(), NOW()),
  ('admin.brand.field.favicon', 'ru', 'Favicon (вкладка браузера)', NOW(), NOW()),

  ('admin.brand.section.contact', 'en', 'Contact info', NOW(), NOW()),
  ('admin.brand.section.contact', 'hy', 'Կոնտակտային տվյալներ', NOW(), NOW()),
  ('admin.brand.section.contact', 'ru', 'Контактная информация', NOW(), NOW()),

  ('admin.brand.field.phone', 'en', 'Phone', NOW(), NOW()),
  ('admin.brand.field.phone', 'hy', 'Հեռախոս', NOW(), NOW()),
  ('admin.brand.field.phone', 'ru', 'Телефон', NOW(), NOW()),

  ('admin.brand.field.email', 'en', 'Email', NOW(), NOW()),
  ('admin.brand.field.email', 'hy', 'Էլ. փոստ', NOW(), NOW()),
  ('admin.brand.field.email', 'ru', 'Эл. почта', NOW(), NOW()),

  ('admin.brand.field.address', 'en', 'Address (street + building)', NOW(), NOW()),
  ('admin.brand.field.address', 'hy', 'Հասցե (փողոց + շենք)', NOW(), NOW()),
  ('admin.brand.field.address', 'ru', 'Адрес (улица + здание)', NOW(), NOW()),

  ('admin.brand.field.address_placeholder', 'en', 'Mashtots Ave 1', NOW(), NOW()),
  ('admin.brand.field.address_placeholder', 'hy', 'Մաշտոցի պող. 1', NOW(), NOW()),
  ('admin.brand.field.address_placeholder', 'ru', 'просп. Маштоца 1', NOW(), NOW()),

  ('admin.brand.field.city', 'en', 'City', NOW(), NOW()),
  ('admin.brand.field.city', 'hy', 'Քաղաք', NOW(), NOW()),
  ('admin.brand.field.city', 'ru', 'Город', NOW(), NOW()),

  ('admin.brand.field.country', 'en', 'Country', NOW(), NOW()),
  ('admin.brand.field.country', 'hy', 'Երկիր', NOW(), NOW()),
  ('admin.brand.field.country', 'ru', 'Страна', NOW(), NOW()),

  ('admin.brand.section.social', 'en', 'Social links', NOW(), NOW()),
  ('admin.brand.section.social', 'hy', 'Սոցիալական հղումներ', NOW(), NOW()),
  ('admin.brand.section.social', 'ru', 'Социальные ссылки', NOW(), NOW()),

  ('admin.brand.section.social_hint', 'en', 'If left empty, will not be shown in the footer', NOW(), NOW()),
  ('admin.brand.section.social_hint', 'hy', 'Դատարկ թողնելու դեպքում՝ footer-ում չի երևա', NOW(), NOW()),
  ('admin.brand.section.social_hint', 'ru', 'Если оставить пустым, не будет показано в футере', NOW(), NOW()),

  ('admin.brand.section.custom_fields', 'en', 'Custom fields', NOW(), NOW()),
  ('admin.brand.section.custom_fields', 'hy', 'Հատուկ դաշտեր', NOW(), NOW()),
  ('admin.brand.section.custom_fields', 'ru', 'Дополнительные поля', NOW(), NOW()),

  ('admin.brand.section.custom_fields_hint', 'en', 'Custom fields (e.g. Office hours, Telegram URL, etc.)', NOW(), NOW()),
  ('admin.brand.section.custom_fields_hint', 'hy', 'Հատուկ դաշտեր (օրինակ՝ Office hours, Telegram URL, ևն)', NOW(), NOW()),
  ('admin.brand.section.custom_fields_hint', 'ru', 'Дополнительные поля (напр. Office hours, Telegram URL, и т.д.)', NOW(), NOW()),

  ('admin.brand.add_field', 'en', '+ Add field', NOW(), NOW()),
  ('admin.brand.add_field', 'hy', '+ Ավելացնել դաշտ', NOW(), NOW()),
  ('admin.brand.add_field', 'ru', '+ Добавить поле', NOW(), NOW()),

  ('admin.brand.empty_custom_fields', 'en', 'No custom fields yet.', NOW(), NOW()),
  ('admin.brand.empty_custom_fields', 'hy', 'Դեռ հատուկ դաշտեր չկան։', NOW(), NOW()),
  ('admin.brand.empty_custom_fields', 'ru', 'Дополнительных полей пока нет.', NOW(), NOW()),

  ('admin.brand.field.cf_key', 'en', 'Key (no spaces)', NOW(), NOW()),
  ('admin.brand.field.cf_key', 'hy', 'Բանալի (առանց բացատների)', NOW(), NOW()),
  ('admin.brand.field.cf_key', 'ru', 'Ключ (без пробелов)', NOW(), NOW()),

  ('admin.brand.field.cf_label', 'en', 'Label (display name)', NOW(), NOW()),
  ('admin.brand.field.cf_label', 'hy', 'Պիտակ (ցուցադրման անուն)', NOW(), NOW()),
  ('admin.brand.field.cf_label', 'ru', 'Метка (отображаемое имя)', NOW(), NOW()),

  ('admin.brand.field.cf_label_placeholder', 'en', 'Office hours', NOW(), NOW()),
  ('admin.brand.field.cf_label_placeholder', 'hy', 'Աշխատանքային ժամեր', NOW(), NOW()),
  ('admin.brand.field.cf_label_placeholder', 'ru', 'Часы работы', NOW(), NOW()),

  ('admin.brand.field.cf_value', 'en', 'Value', NOW(), NOW()),
  ('admin.brand.field.cf_value', 'hy', 'Արժեք', NOW(), NOW()),
  ('admin.brand.field.cf_value', 'ru', 'Значение', NOW(), NOW())

ON CONFLICT (key, language_code) DO UPDATE
  SET value = EXCLUDED.value,
      updated_at = NOW();
