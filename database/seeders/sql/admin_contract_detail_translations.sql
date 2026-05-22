SET client_encoding = 'UTF8';

-- Phase 1.11 — /platform/contracts/[id] detail page translations

INSERT INTO ui_translations (key, language_code, value, created_at, updated_at) VALUES
  ('admin.contract_detail.title', 'en', 'Contract detail', NOW(), NOW()),
  ('admin.contract_detail.title', 'hy', 'Պայմանագրի մանրամասներ', NOW(), NOW()),
  ('admin.contract_detail.title', 'ru', 'Детали договора', NOW(), NOW()),

  ('admin.contract_detail.err_not_found', 'en', 'Contract not found', NOW(), NOW()),
  ('admin.contract_detail.err_not_found', 'hy', 'Պայմանագիրը չի գտնվել', NOW(), NOW()),
  ('admin.contract_detail.err_not_found', 'ru', 'Договор не найден', NOW(), NOW()),

  ('admin.contract_detail.err_load', 'en', 'Failed to load contract', NOW(), NOW()),
  ('admin.contract_detail.err_load', 'hy', 'Չհաջողվեց բեռնել պայմանագիրը', NOW(), NOW()),
  ('admin.contract_detail.err_load', 'ru', 'Не удалось загрузить договор', NOW(), NOW()),

  ('admin.contract_detail.err_send', 'en', 'Send failed', NOW(), NOW()),
  ('admin.contract_detail.err_send', 'hy', 'Ուղարկումը ձախողվեց', NOW(), NOW()),
  ('admin.contract_detail.err_send', 'ru', 'Не удалось отправить', NOW(), NOW()),

  ('admin.contract_detail.err_countersign', 'en', 'Counter-sign failed', NOW(), NOW()),
  ('admin.contract_detail.err_countersign', 'hy', 'Հակ-ստորագրությունը ձախողվեց', NOW(), NOW()),
  ('admin.contract_detail.err_countersign', 'ru', 'Не удалось контр-подписать', NOW(), NOW()),

  ('admin.contract_detail.err_terminate', 'en', 'Terminate failed', NOW(), NOW()),
  ('admin.contract_detail.err_terminate', 'hy', 'Դադարեցումը ձախողվեց', NOW(), NOW()),
  ('admin.contract_detail.err_terminate', 'ru', 'Не удалось расторгнуть', NOW(), NOW()),

  ('admin.contract_detail.err_reason_required', 'en', 'Termination reason is required.', NOW(), NOW()),
  ('admin.contract_detail.err_reason_required', 'hy', 'Դադարեցման պատճառը պարտադիր է։', NOW(), NOW()),
  ('admin.contract_detail.err_reason_required', 'ru', 'Причина расторжения обязательна.', NOW(), NOW()),

  ('admin.contract_detail.back_to_contracts', 'en', 'Back to contracts', NOW(), NOW()),
  ('admin.contract_detail.back_to_contracts', 'hy', 'Վերադառնալ պայմանագրերին', NOW(), NOW()),
  ('admin.contract_detail.back_to_contracts', 'ru', 'Назад к договорам', NOW(), NOW()),

  ('admin.contract_detail.btn_send', 'en', 'Send to partner', NOW(), NOW()),
  ('admin.contract_detail.btn_send', 'hy', 'Ուղարկել գործընկերոջը', NOW(), NOW()),
  ('admin.contract_detail.btn_send', 'ru', 'Отправить партнёру', NOW(), NOW()),

  ('admin.contract_detail.btn_sending', 'en', 'Sending…', NOW(), NOW()),
  ('admin.contract_detail.btn_sending', 'hy', 'Ուղարկվում է…', NOW(), NOW()),
  ('admin.contract_detail.btn_sending', 'ru', 'Отправляется…', NOW(), NOW()),

  ('admin.contract_detail.btn_countersign', 'en', 'Counter-sign as ZULU', NOW(), NOW()),
  ('admin.contract_detail.btn_countersign', 'hy', 'Հակ-ստորագրել որպես ZULU', NOW(), NOW()),
  ('admin.contract_detail.btn_countersign', 'ru', 'Контр-подписать как ZULU', NOW(), NOW()),

  ('admin.contract_detail.btn_signing', 'en', 'Signing…', NOW(), NOW()),
  ('admin.contract_detail.btn_signing', 'hy', 'Ստորագրվում է…', NOW(), NOW()),
  ('admin.contract_detail.btn_signing', 'ru', 'Подписывается…', NOW(), NOW()),

  ('admin.contract_detail.btn_terminate', 'en', 'Terminate', NOW(), NOW()),
  ('admin.contract_detail.btn_terminate', 'hy', 'Դադարեցնել', NOW(), NOW()),
  ('admin.contract_detail.btn_terminate', 'ru', 'Расторгнуть', NOW(), NOW()),

  ('admin.contract_detail.btn_terminating', 'en', 'Terminating…', NOW(), NOW()),
  ('admin.contract_detail.btn_terminating', 'hy', 'Դադարեցվում է…', NOW(), NOW()),
  ('admin.contract_detail.btn_terminating', 'ru', 'Расторгается…', NOW(), NOW()),

  ('admin.contract_detail.btn_confirm_terminate', 'en', 'Confirm termination', NOW(), NOW()),
  ('admin.contract_detail.btn_confirm_terminate', 'hy', 'Հաստատել դադարեցումը', NOW(), NOW()),
  ('admin.contract_detail.btn_confirm_terminate', 'ru', 'Подтвердить расторжение', NOW(), NOW()),

  ('admin.contract_detail.terminate_title', 'en', 'Terminate contract', NOW(), NOW()),
  ('admin.contract_detail.terminate_title', 'hy', 'Դադարեցնել պայմանագիրը', NOW(), NOW()),
  ('admin.contract_detail.terminate_title', 'ru', 'Расторгнуть договор', NOW(), NOW()),

  ('admin.contract_detail.terminate_hint', 'en', 'Provide a reason that will be recorded with the termination. Both parties keep access to the terminated contract; status changes to terminated.', NOW(), NOW()),
  ('admin.contract_detail.terminate_hint', 'hy', 'Տրամադրիր պատճառ, որը կարձանագրվի դադարեցման հետ։ Երկու կողմերն էլ պահպանում են մուտքը դեպի դադարեցված պայմանագիրը; կարգավիճակը փոխվում է terminated։', NOW(), NOW()),
  ('admin.contract_detail.terminate_hint', 'ru', 'Укажите причину, которая будет записана при расторжении. Обе стороны сохраняют доступ к расторгнутому договору; статус меняется на terminated.', NOW(), NOW()),

  ('admin.contract_detail.terminate_reason_placeholder', 'en', 'Reason for termination…', NOW(), NOW()),
  ('admin.contract_detail.terminate_reason_placeholder', 'hy', 'Դադարեցման պատճառ…', NOW(), NOW()),
  ('admin.contract_detail.terminate_reason_placeholder', 'ru', 'Причина расторжения…', NOW(), NOW()),

  ('admin.contract_detail.parties_section', 'en', 'Parties', NOW(), NOW()),
  ('admin.contract_detail.parties_section', 'hy', 'Կողմեր', NOW(), NOW()),
  ('admin.contract_detail.parties_section', 'ru', 'Стороны', NOW(), NOW()),

  ('admin.contract_detail.created_by', 'en', 'Created by', NOW(), NOW()),
  ('admin.contract_detail.created_by', 'hy', 'Ստեղծել է', NOW(), NOW()),
  ('admin.contract_detail.created_by', 'ru', 'Создал', NOW(), NOW()),

  ('admin.contract_detail.notice_days', 'en', 'Notice (days)', NOW(), NOW()),
  ('admin.contract_detail.notice_days', 'hy', 'Ծանուցում (օրեր)', NOW(), NOW()),
  ('admin.contract_detail.notice_days', 'ru', 'Уведомление (дни)', NOW(), NOW()),

  ('admin.contract_detail.yes', 'en', 'Yes', NOW(), NOW()),
  ('admin.contract_detail.yes', 'hy', 'Այո', NOW(), NOW()),
  ('admin.contract_detail.yes', 'ru', 'Да', NOW(), NOW()),

  ('admin.contract_detail.no', 'en', 'No', NOW(), NOW()),
  ('admin.contract_detail.no', 'hy', 'Ոչ', NOW(), NOW()),
  ('admin.contract_detail.no', 'ru', 'Нет', NOW(), NOW()),

  ('admin.contract_detail.terminated_at', 'en', 'Terminated at', NOW(), NOW()),
  ('admin.contract_detail.terminated_at', 'hy', 'Դադարեցված է', NOW(), NOW()),
  ('admin.contract_detail.terminated_at', 'ru', 'Расторгнут', NOW(), NOW()),

  ('admin.contract_detail.reason', 'en', 'Reason', NOW(), NOW()),
  ('admin.contract_detail.reason', 'hy', 'Պատճառ', NOW(), NOW()),
  ('admin.contract_detail.reason', 'ru', 'Причина', NOW(), NOW()),

  ('admin.contract_detail.signed_pdf', 'en', 'Signed PDF', NOW(), NOW()),
  ('admin.contract_detail.signed_pdf', 'hy', 'Ստորագրված PDF', NOW(), NOW()),
  ('admin.contract_detail.signed_pdf', 'ru', 'Подписанный PDF', NOW(), NOW()),

  ('admin.contract_detail.download_signed_pdf', 'en', 'Download signed PDF', NOW(), NOW()),
  ('admin.contract_detail.download_signed_pdf', 'hy', 'Ներբեռնել ստորագրված PDF-ը', NOW(), NOW()),
  ('admin.contract_detail.download_signed_pdf', 'ru', 'Скачать подписанный PDF', NOW(), NOW()),

  ('admin.contract_detail.version_history', 'en', 'Version history', NOW(), NOW()),
  ('admin.contract_detail.version_history', 'hy', 'Տարբերակների պատմություն', NOW(), NOW()),
  ('admin.contract_detail.version_history', 'ru', 'История версий', NOW(), NOW()),

  ('admin.contract_detail.by_user', 'en', 'by user', NOW(), NOW()),
  ('admin.contract_detail.by_user', 'hy', 'օգտատերի կողմից', NOW(), NOW()),
  ('admin.contract_detail.by_user', 'ru', 'пользователем', NOW(), NOW())

ON CONFLICT (key, language_code) DO UPDATE
  SET value = EXCLUDED.value,
      updated_at = NOW();
