<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds en/hy/ru translations for all Bucket-3 admin page content keys.
 * The keys here MUST match what the code calls via t("...") — divergent
 * keys would render as raw key paths in the UI. Verified against
 * grep of app/(admin)/bucket3/.
 *
 * After deploy: clear ui_translations cache:
 *   php artisan cache:forget ui_translations_en
 *   php artisan cache:forget ui_translations_hy
 *   php artisan cache:forget ui_translations_ru
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            // ────────────────────────────────────────────────────────────
            // COMMON
            // ────────────────────────────────────────────────────────────
            ['common.all', 'en', 'All'],
            ['common.all', 'hy', 'Բոլորը'],
            ['common.all', 'ru', 'Все'],

            ['common.apply', 'en', 'Apply'],
            ['common.apply', 'hy', 'Կիրառել'],
            ['common.apply', 'ru', 'Применить'],

            ['common.cancel', 'en', 'Cancel'],
            ['common.cancel', 'hy', 'Չեղարկել'],
            ['common.cancel', 'ru', 'Отмена'],

            ['common.edit', 'en', 'Edit'],
            ['common.edit', 'hy', 'Խմբագրել'],
            ['common.edit', 'ru', 'Редактировать'],

            ['common.loading', 'en', 'Loading…'],
            ['common.loading', 'hy', 'Բեռնվում է…'],
            ['common.loading', 'ru', 'Загрузка…'],

            ['common.remove', 'en', 'Remove'],
            ['common.remove', 'hy', 'Ջնջել'],
            ['common.remove', 'ru', 'Удалить'],

            ['common.saving', 'en', 'Saving…'],
            ['common.saving', 'hy', 'Պահպանվում է…'],
            ['common.saving', 'ru', 'Сохранение…'],

            // ────────────────────────────────────────────────────────────
            // BLOCK DATES
            // ────────────────────────────────────────────────────────────
            ['admin.bucket3.block_dates.add_block', 'en', 'Add block'],
            ['admin.bucket3.block_dates.add_block', 'hy', 'Ավելացնել արգելափակում'],
            ['admin.bucket3.block_dates.add_block', 'ru', 'Добавить блокировку'],

            ['admin.bucket3.block_dates.col.actions', 'en', 'Actions'],
            ['admin.bucket3.block_dates.col.actions', 'hy', 'Գործողություններ'],
            ['admin.bucket3.block_dates.col.actions', 'ru', 'Действия'],

            ['admin.bucket3.block_dates.col.company', 'en', 'Company'],
            ['admin.bucket3.block_dates.col.company', 'hy', 'Ընկերություն'],
            ['admin.bucket3.block_dates.col.company', 'ru', 'Компания'],

            ['admin.bucket3.block_dates.col.from', 'en', 'From'],
            ['admin.bucket3.block_dates.col.from', 'hy', 'Սկսած'],
            ['admin.bucket3.block_dates.col.from', 'ru', 'С'],

            ['admin.bucket3.block_dates.col.item', 'en', 'Item'],
            ['admin.bucket3.block_dates.col.item', 'hy', 'Տարր'],
            ['admin.bucket3.block_dates.col.item', 'ru', 'Элемент'],

            ['admin.bucket3.block_dates.col.reason', 'en', 'Reason'],
            ['admin.bucket3.block_dates.col.reason', 'hy', 'Պատճառ'],
            ['admin.bucket3.block_dates.col.reason', 'ru', 'Причина'],

            ['admin.bucket3.block_dates.col.to', 'en', 'To'],
            ['admin.bucket3.block_dates.col.to', 'hy', 'Մինչև'],
            ['admin.bucket3.block_dates.col.to', 'ru', 'До'],

            ['admin.bucket3.block_dates.col.type', 'en', 'Type'],
            ['admin.bucket3.block_dates.col.type', 'hy', 'Տեսակ'],
            ['admin.bucket3.block_dates.col.type', 'ru', 'Тип'],

            ['admin.bucket3.block_dates.empty', 'en', 'No blocks defined yet.'],
            ['admin.bucket3.block_dates.empty', 'hy', 'Արգելափակումներ դեռ սահմանված չեն։'],
            ['admin.bucket3.block_dates.empty', 'ru', 'Блокировки пока не заданы.'],

            ['admin.bucket3.block_dates.error.dates_required', 'en', 'From and to dates are required'],
            ['admin.bucket3.block_dates.error.dates_required', 'hy', 'Սկզբի և ավարտի ամսաթվերը պարտադիր են'],
            ['admin.bucket3.block_dates.error.dates_required', 'ru', 'Даты «с» и «по» обязательны'],

            ['admin.bucket3.block_dates.error.from_before_to', 'en', 'From date must be before to date'],
            ['admin.bucket3.block_dates.error.from_before_to', 'hy', 'Սկզբի ամսաթիվը պետք է լինի ավարտի ամսաթվից առաջ'],
            ['admin.bucket3.block_dates.error.from_before_to', 'ru', 'Дата начала должна быть раньше даты окончания'],

            ['admin.bucket3.block_dates.field.from', 'en', 'From'],
            ['admin.bucket3.block_dates.field.from', 'hy', 'Սկսած'],
            ['admin.bucket3.block_dates.field.from', 'ru', 'С'],

            ['admin.bucket3.block_dates.field.item_id', 'en', 'Item ID'],
            ['admin.bucket3.block_dates.field.item_id', 'hy', 'Տարրի ID'],
            ['admin.bucket3.block_dates.field.item_id', 'ru', 'ID элемента'],

            ['admin.bucket3.block_dates.field.item_id_helper', 'en', 'Numeric ID of the inventory item'],
            ['admin.bucket3.block_dates.field.item_id_helper', 'hy', 'Տարրի թվային ID-ն'],
            ['admin.bucket3.block_dates.field.item_id_helper', 'ru', 'Числовой ID позиции'],

            ['admin.bucket3.block_dates.field.item_type', 'en', 'Item type'],
            ['admin.bucket3.block_dates.field.item_type', 'hy', 'Տարրի տեսակ'],
            ['admin.bucket3.block_dates.field.item_type', 'ru', 'Тип элемента'],

            ['admin.bucket3.block_dates.field.reason', 'en', 'Reason'],
            ['admin.bucket3.block_dates.field.reason', 'hy', 'Պատճառ'],
            ['admin.bucket3.block_dates.field.reason', 'ru', 'Причина'],

            ['admin.bucket3.block_dates.field.reason_placeholder', 'en', 'e.g. maintenance, private event'],
            ['admin.bucket3.block_dates.field.reason_placeholder', 'hy', 'օր.՝ տեխսպասարկում, փակ միջոցառում'],
            ['admin.bucket3.block_dates.field.reason_placeholder', 'ru', 'напр.: тех. обслуживание, частное мероприятие'],

            ['admin.bucket3.block_dates.field.to', 'en', 'To'],
            ['admin.bucket3.block_dates.field.to', 'hy', 'Մինչև'],
            ['admin.bucket3.block_dates.field.to', 'ru', 'До'],

            ['admin.bucket3.block_dates.filter.item_id', 'en', 'Item ID'],
            ['admin.bucket3.block_dates.filter.item_id', 'hy', 'Տարրի ID'],
            ['admin.bucket3.block_dates.filter.item_id', 'ru', 'ID элемента'],

            ['admin.bucket3.block_dates.filter.placeholder', 'en', 'Filter by item ID'],
            ['admin.bucket3.block_dates.filter.placeholder', 'hy', 'Զտել ըստ տարրի ID-ի'],
            ['admin.bucket3.block_dates.filter.placeholder', 'ru', 'Фильтр по ID элемента'],

            ['admin.bucket3.block_dates.subtitle', 'en', "Block dates per inventory item to prevent overbooking when capacity isn't available"],
            ['admin.bucket3.block_dates.subtitle', 'hy', 'Արգելափակեք ամսաթվերը ըստ տարրի՝ կանխելու համար գերամրագրումը, երբ տեղ չկա'],
            ['admin.bucket3.block_dates.subtitle', 'ru', 'Блокируйте даты по позициям, чтобы избежать овербукинга, когда мест нет'],

            ['admin.bucket3.block_dates.subtitle_count', 'en', '{count} blocks defined'],
            ['admin.bucket3.block_dates.subtitle_count', 'hy', '{count} արգելափակում սահմանված է'],
            ['admin.bucket3.block_dates.subtitle_count', 'ru', '{count} блокировок задано'],

            ['admin.bucket3.block_dates.title', 'en', 'Block dates'],
            ['admin.bucket3.block_dates.title', 'hy', 'Արգելափակել ամսաթվերը'],
            ['admin.bucket3.block_dates.title', 'ru', 'Блокировка дат'],

            // ────────────────────────────────────────────────────────────
            // BULK NOTIFICATIONS
            // ────────────────────────────────────────────────────────────
            ['admin.bucket3.bulk_notifications.error.company_id_required', 'en', 'Company is required when targeting by company'],
            ['admin.bucket3.bulk_notifications.error.company_id_required', 'hy', 'Ընկերության ընտրությունը պարտադիր է, երբ թիրախավորումն ըստ ընկերության է'],
            ['admin.bucket3.bulk_notifications.error.company_id_required', 'ru', 'Выбор компании обязателен при таргетировании по компании'],

            ['admin.bucket3.bulk_notifications.error.title_message_required', 'en', 'Title and message are required'],
            ['admin.bucket3.bulk_notifications.error.title_message_required', 'hy', 'Վերնագիրը և հաղորդագրությունը պարտադիր են'],
            ['admin.bucket3.bulk_notifications.error.title_message_required', 'ru', 'Заголовок и сообщение обязательны'],

            ['admin.bucket3.bulk_notifications.error.user_ids_required', 'en', 'At least one user ID is required'],
            ['admin.bucket3.bulk_notifications.error.user_ids_required', 'hy', 'Առնվազն մեկ օգտատիրոջ ID պարտադիր է'],
            ['admin.bucket3.bulk_notifications.error.user_ids_required', 'ru', 'Требуется хотя бы один ID пользователя'],

            ['admin.bucket3.bulk_notifications.field.body', 'en', 'Message'],
            ['admin.bucket3.bulk_notifications.field.body', 'hy', 'Հաղորդագրություն'],
            ['admin.bucket3.bulk_notifications.field.body', 'ru', 'Сообщение'],

            ['admin.bucket3.bulk_notifications.field.body_placeholder', 'en', 'Write the notification body…'],
            ['admin.bucket3.bulk_notifications.field.body_placeholder', 'hy', 'Գրեք ծանուցման տեքստը…'],
            ['admin.bucket3.bulk_notifications.field.body_placeholder', 'ru', 'Введите текст уведомления…'],

            ['admin.bucket3.bulk_notifications.field.company_id', 'en', 'Company'],
            ['admin.bucket3.bulk_notifications.field.company_id', 'hy', 'Ընկերություն'],
            ['admin.bucket3.bulk_notifications.field.company_id', 'ru', 'Компания'],

            ['admin.bucket3.bulk_notifications.field.priority', 'en', 'Priority'],
            ['admin.bucket3.bulk_notifications.field.priority', 'hy', 'Առաջնահերթություն'],
            ['admin.bucket3.bulk_notifications.field.priority', 'ru', 'Приоритет'],

            ['admin.bucket3.bulk_notifications.field.target', 'en', 'Target'],
            ['admin.bucket3.bulk_notifications.field.target', 'hy', 'Թիրախ'],
            ['admin.bucket3.bulk_notifications.field.target', 'ru', 'Аудитория'],

            ['admin.bucket3.bulk_notifications.field.title', 'en', 'Title'],
            ['admin.bucket3.bulk_notifications.field.title', 'hy', 'Վերնագիր'],
            ['admin.bucket3.bulk_notifications.field.title', 'ru', 'Заголовок'],

            ['admin.bucket3.bulk_notifications.field.title_placeholder', 'en', 'Short notification headline'],
            ['admin.bucket3.bulk_notifications.field.title_placeholder', 'hy', 'Ծանուցման կարճ վերնագիր'],
            ['admin.bucket3.bulk_notifications.field.title_placeholder', 'ru', 'Краткий заголовок уведомления'],

            ['admin.bucket3.bulk_notifications.field.user_ids', 'en', 'User IDs'],
            ['admin.bucket3.bulk_notifications.field.user_ids', 'hy', 'Օգտատերերի ID-ներ'],
            ['admin.bucket3.bulk_notifications.field.user_ids', 'ru', 'ID пользователей'],

            ['admin.bucket3.bulk_notifications.field.user_ids_helper', 'en', 'Comma-separated list of user IDs'],
            ['admin.bucket3.bulk_notifications.field.user_ids_helper', 'hy', 'Օգտատերերի ID-ների ստորակետով բաժանված ցուցակ'],
            ['admin.bucket3.bulk_notifications.field.user_ids_helper', 'ru', 'Список ID пользователей через запятую'],

            ['admin.bucket3.bulk_notifications.option.all_b2c', 'en', 'All B2C customers'],
            ['admin.bucket3.bulk_notifications.option.all_b2c', 'hy', 'Բոլոր B2C հաճախորդները'],
            ['admin.bucket3.bulk_notifications.option.all_b2c', 'ru', 'Все B2C-клиенты'],

            ['admin.bucket3.bulk_notifications.option.all_staff', 'en', 'All staff'],
            ['admin.bucket3.bulk_notifications.option.all_staff', 'hy', 'Ողջ անձնակազմը'],
            ['admin.bucket3.bulk_notifications.option.all_staff', 'ru', 'Весь персонал'],

            ['admin.bucket3.bulk_notifications.option.by_company', 'en', 'By company'],
            ['admin.bucket3.bulk_notifications.option.by_company', 'hy', 'Ըստ ընկերության'],
            ['admin.bucket3.bulk_notifications.option.by_company', 'ru', 'По компании'],

            ['admin.bucket3.bulk_notifications.option.specific_users', 'en', 'Specific users'],
            ['admin.bucket3.bulk_notifications.option.specific_users', 'hy', 'Կոնկրետ օգտատերեր'],
            ['admin.bucket3.bulk_notifications.option.specific_users', 'ru', 'Конкретные пользователи'],

            ['admin.bucket3.bulk_notifications.priority.high', 'en', 'High'],
            ['admin.bucket3.bulk_notifications.priority.high', 'hy', 'Բարձր'],
            ['admin.bucket3.bulk_notifications.priority.high', 'ru', 'Высокий'],

            ['admin.bucket3.bulk_notifications.priority.low', 'en', 'Low'],
            ['admin.bucket3.bulk_notifications.priority.low', 'hy', 'Ցածր'],
            ['admin.bucket3.bulk_notifications.priority.low', 'ru', 'Низкий'],

            ['admin.bucket3.bulk_notifications.priority.normal', 'en', 'Normal'],
            ['admin.bucket3.bulk_notifications.priority.normal', 'hy', 'Սովորական'],
            ['admin.bucket3.bulk_notifications.priority.normal', 'ru', 'Обычный'],

            ['admin.bucket3.bulk_notifications.section.message', 'en', 'Message'],
            ['admin.bucket3.bulk_notifications.section.message', 'hy', 'Հաղորդագրություն'],
            ['admin.bucket3.bulk_notifications.section.message', 'ru', 'Сообщение'],

            ['admin.bucket3.bulk_notifications.section.recipients', 'en', 'Recipients'],
            ['admin.bucket3.bulk_notifications.section.recipients', 'hy', 'Ստացողներ'],
            ['admin.bucket3.bulk_notifications.section.recipients', 'ru', 'Получатели'],

            ['admin.bucket3.bulk_notifications.send', 'en', 'Send notification'],
            ['admin.bucket3.bulk_notifications.send', 'hy', 'Ուղարկել ծանուցումը'],
            ['admin.bucket3.bulk_notifications.send', 'ru', 'Отправить уведомление'],

            ['admin.bucket3.bulk_notifications.sending', 'en', 'Sending…'],
            ['admin.bucket3.bulk_notifications.sending', 'hy', 'Ուղարկվում է…'],
            ['admin.bucket3.bulk_notifications.sending', 'ru', 'Отправляется…'],

            ['admin.bucket3.bulk_notifications.subtitle', 'en', 'Send a one-off notification to a group of users'],
            ['admin.bucket3.bulk_notifications.subtitle', 'hy', 'Ուղարկեք միանվագ ծանուցում օգտատերերի խմբին'],
            ['admin.bucket3.bulk_notifications.subtitle', 'ru', 'Отправьте разовое уведомление группе пользователей'],

            ['admin.bucket3.bulk_notifications.success', 'en', 'Sent to {count} users'],
            ['admin.bucket3.bulk_notifications.success', 'hy', 'Ուղարկվել է {count} օգտատիրոջ'],
            ['admin.bucket3.bulk_notifications.success', 'ru', 'Отправлено {count} пользователям'],

            ['admin.bucket3.bulk_notifications.title', 'en', 'Bulk notifications'],
            ['admin.bucket3.bulk_notifications.title', 'hy', 'Զանգվածային ծանուցումներ'],
            ['admin.bucket3.bulk_notifications.title', 'ru', 'Массовые уведомления'],

            // ────────────────────────────────────────────────────────────
            // CASES
            // ────────────────────────────────────────────────────────────
            ['admin.bucket3.cases.close', 'en', 'Close case'],
            ['admin.bucket3.cases.close', 'hy', 'Փակել գործը'],
            ['admin.bucket3.cases.close', 'ru', 'Закрыть кейс'],

            ['admin.bucket3.cases.col.assigned', 'en', 'Assigned to'],
            ['admin.bucket3.cases.col.assigned', 'hy', 'Հանձնարարված է'],
            ['admin.bucket3.cases.col.assigned', 'ru', 'Назначен'],

            ['admin.bucket3.cases.col.case_number', 'en', 'Case #'],
            ['admin.bucket3.cases.col.case_number', 'hy', 'Գործ №'],
            ['admin.bucket3.cases.col.case_number', 'ru', 'Кейс №'],

            ['admin.bucket3.cases.col.opened', 'en', 'Opened'],
            ['admin.bucket3.cases.col.opened', 'hy', 'Բացված է'],
            ['admin.bucket3.cases.col.opened', 'ru', 'Открыт'],

            ['admin.bucket3.cases.col.priority', 'en', 'Priority'],
            ['admin.bucket3.cases.col.priority', 'hy', 'Առաջնահերթություն'],
            ['admin.bucket3.cases.col.priority', 'ru', 'Приоритет'],

            ['admin.bucket3.cases.col.sla', 'en', 'SLA'],
            ['admin.bucket3.cases.col.sla', 'hy', 'SLA'],
            ['admin.bucket3.cases.col.sla', 'ru', 'SLA'],

            ['admin.bucket3.cases.col.status', 'en', 'Status'],
            ['admin.bucket3.cases.col.status', 'hy', 'Կարգավիճակ'],
            ['admin.bucket3.cases.col.status', 'ru', 'Статус'],

            ['admin.bucket3.cases.col.title', 'en', 'Title'],
            ['admin.bucket3.cases.col.title', 'hy', 'Վերնագիր'],
            ['admin.bucket3.cases.col.title', 'ru', 'Заголовок'],

            ['admin.bucket3.cases.conversation', 'en', 'Conversation'],
            ['admin.bucket3.cases.conversation', 'hy', 'Նամակագրություն'],
            ['admin.bucket3.cases.conversation', 'ru', 'Переписка'],

            ['admin.bucket3.cases.create', 'en', 'Create case'],
            ['admin.bucket3.cases.create', 'hy', 'Ստեղծել գործ'],
            ['admin.bucket3.cases.create', 'ru', 'Создать кейс'],

            ['admin.bucket3.cases.creating', 'en', 'Creating…'],
            ['admin.bucket3.cases.creating', 'hy', 'Ստեղծվում է…'],
            ['admin.bucket3.cases.creating', 'ru', 'Создаётся…'],

            ['admin.bucket3.cases.empty', 'en', 'No cases yet.'],
            ['admin.bucket3.cases.empty', 'hy', 'Դեռ գործեր չկան։'],
            ['admin.bucket3.cases.empty', 'ru', 'Кейсов пока нет.'],

            ['admin.bucket3.cases.error.title_and_description', 'en', 'Title and description are required'],
            ['admin.bucket3.cases.error.title_and_description', 'hy', 'Վերնագիրը և նկարագրությունը պարտադիր են'],
            ['admin.bucket3.cases.error.title_and_description', 'ru', 'Заголовок и описание обязательны'],

            ['admin.bucket3.cases.field.assignee', 'en', 'Assignee'],
            ['admin.bucket3.cases.field.assignee', 'hy', 'Պատասխանատու'],
            ['admin.bucket3.cases.field.assignee', 'ru', 'Ответственный'],

            ['admin.bucket3.cases.field.closing_notes', 'en', 'Closing notes'],
            ['admin.bucket3.cases.field.closing_notes', 'hy', 'Փակման նշումներ'],
            ['admin.bucket3.cases.field.closing_notes', 'ru', 'Заметки при закрытии'],

            ['admin.bucket3.cases.field.company_id', 'en', 'Company'],
            ['admin.bucket3.cases.field.company_id', 'hy', 'Ընկերություն'],
            ['admin.bucket3.cases.field.company_id', 'ru', 'Компания'],

            ['admin.bucket3.cases.field.description', 'en', 'Description'],
            ['admin.bucket3.cases.field.description', 'hy', 'Նկարագրություն'],
            ['admin.bucket3.cases.field.description', 'ru', 'Описание'],

            ['admin.bucket3.cases.field.priority', 'en', 'Priority'],
            ['admin.bucket3.cases.field.priority', 'hy', 'Առաջնահերթություն'],
            ['admin.bucket3.cases.field.priority', 'ru', 'Приоритет'],

            ['admin.bucket3.cases.field.reassign', 'en', 'Reassign to'],
            ['admin.bucket3.cases.field.reassign', 'hy', 'Վերանշանակել'],
            ['admin.bucket3.cases.field.reassign', 'ru', 'Переназначить'],

            ['admin.bucket3.cases.field.reply', 'en', 'Reply'],
            ['admin.bucket3.cases.field.reply', 'hy', 'Պատասխան'],
            ['admin.bucket3.cases.field.reply', 'ru', 'Ответ'],

            ['admin.bucket3.cases.field.reply_placeholder', 'en', 'Write a reply…'],
            ['admin.bucket3.cases.field.reply_placeholder', 'hy', 'Գրեք պատասխան…'],
            ['admin.bucket3.cases.field.reply_placeholder', 'ru', 'Напишите ответ…'],

            ['admin.bucket3.cases.field.title', 'en', 'Title'],
            ['admin.bucket3.cases.field.title', 'hy', 'Վերնագիր'],
            ['admin.bucket3.cases.field.title', 'ru', 'Заголовок'],

            ['admin.bucket3.cases.field.update_priority', 'en', 'Update priority'],
            ['admin.bucket3.cases.field.update_priority', 'hy', 'Թարմացնել առաջնահերթությունը'],
            ['admin.bucket3.cases.field.update_priority', 'ru', 'Изменить приоритет'],

            ['admin.bucket3.cases.field.update_status', 'en', 'Update status'],
            ['admin.bucket3.cases.field.update_status', 'hy', 'Թարմացնել կարգավիճակը'],
            ['admin.bucket3.cases.field.update_status', 'ru', 'Изменить статус'],

            ['admin.bucket3.cases.filter.priority', 'en', 'Priority'],
            ['admin.bucket3.cases.filter.priority', 'hy', 'Առաջնահերթություն'],
            ['admin.bucket3.cases.filter.priority', 'ru', 'Приоритет'],

            ['admin.bucket3.cases.filter.status', 'en', 'Status'],
            ['admin.bucket3.cases.filter.status', 'hy', 'Կարգավիճակ'],
            ['admin.bucket3.cases.filter.status', 'ru', 'Статус'],

            ['admin.bucket3.cases.internal', 'en', 'Internal'],
            ['admin.bucket3.cases.internal', 'hy', 'Ներքին'],
            ['admin.bucket3.cases.internal', 'ru', 'Внутреннее'],

            ['admin.bucket3.cases.label.assigned_to', 'en', 'Assigned to'],
            ['admin.bucket3.cases.label.assigned_to', 'hy', 'Հանձնարարված է'],
            ['admin.bucket3.cases.label.assigned_to', 'ru', 'Назначен'],

            ['admin.bucket3.cases.label.closed_at', 'en', 'Closed at'],
            ['admin.bucket3.cases.label.closed_at', 'hy', 'Փակվել է'],
            ['admin.bucket3.cases.label.closed_at', 'ru', 'Закрыт'],

            ['admin.bucket3.cases.label.company', 'en', 'Company'],
            ['admin.bucket3.cases.label.company', 'hy', 'Ընկերություն'],
            ['admin.bucket3.cases.label.company', 'ru', 'Компания'],

            ['admin.bucket3.cases.label.opened_at', 'en', 'Opened at'],
            ['admin.bucket3.cases.label.opened_at', 'hy', 'Բացվել է'],
            ['admin.bucket3.cases.label.opened_at', 'ru', 'Открыт'],

            ['admin.bucket3.cases.label.opened_by', 'en', 'Opened by'],
            ['admin.bucket3.cases.label.opened_by', 'hy', 'Բացել է'],
            ['admin.bucket3.cases.label.opened_by', 'ru', 'Открыл'],

            ['admin.bucket3.cases.new_case', 'en', 'New case'],
            ['admin.bucket3.cases.new_case', 'hy', 'Նոր գործ'],
            ['admin.bucket3.cases.new_case', 'ru', 'Новый кейс'],

            ['admin.bucket3.cases.no_replies', 'en', 'No replies yet.'],
            ['admin.bucket3.cases.no_replies', 'hy', 'Դեռ պատասխաններ չկան։'],
            ['admin.bucket3.cases.no_replies', 'ru', 'Ответов пока нет.'],

            ['admin.bucket3.cases.replies_count', 'en', '{count} replies'],
            ['admin.bucket3.cases.replies_count', 'hy', '{count} պատասխան'],
            ['admin.bucket3.cases.replies_count', 'ru', '{count} ответов'],

            ['admin.bucket3.cases.search_placeholder', 'en', 'Search cases…'],
            ['admin.bucket3.cases.search_placeholder', 'hy', 'Որոնել գործերում…'],
            ['admin.bucket3.cases.search_placeholder', 'ru', 'Поиск кейсов…'],

            ['admin.bucket3.cases.send_internal', 'en', 'Send internal note'],
            ['admin.bucket3.cases.send_internal', 'hy', 'Ուղարկել ներքին նշում'],
            ['admin.bucket3.cases.send_internal', 'ru', 'Отправить внутреннюю заметку'],

            ['admin.bucket3.cases.send_reply', 'en', 'Send reply'],
            ['admin.bucket3.cases.send_reply', 'hy', 'Ուղարկել պատասխանը'],
            ['admin.bucket3.cases.send_reply', 'ru', 'Отправить ответ'],

            ['admin.bucket3.cases.sending', 'en', 'Sending…'],
            ['admin.bucket3.cases.sending', 'hy', 'Ուղարկվում է…'],
            ['admin.bucket3.cases.sending', 'ru', 'Отправляется…'],

            ['admin.bucket3.cases.subtitle', 'en', 'Track and resolve customer and partner cases'],
            ['admin.bucket3.cases.subtitle', 'hy', 'Հետևեք և լուծեք հաճախորդների ու գործընկերների գործերը'],
            ['admin.bucket3.cases.subtitle', 'ru', 'Отслеживайте и закрывайте кейсы клиентов и партнёров'],

            ['admin.bucket3.cases.subtitle_count', 'en', '{count} cases'],
            ['admin.bucket3.cases.subtitle_count', 'hy', '{count} գործ'],
            ['admin.bucket3.cases.subtitle_count', 'ru', '{count} кейсов'],

            ['admin.bucket3.cases.title', 'en', 'Cases'],
            ['admin.bucket3.cases.title', 'hy', 'Գործեր'],
            ['admin.bucket3.cases.title', 'ru', 'Кейсы'],

            // ────────────────────────────────────────────────────────────
            // CUSTOM FIELDS
            // ────────────────────────────────────────────────────────────
            ['admin.bucket3.custom_fields.add_field', 'en', 'Add field'],
            ['admin.bucket3.custom_fields.add_field', 'hy', 'Ավելացնել դաշտ'],
            ['admin.bucket3.custom_fields.add_field', 'ru', 'Добавить поле'],

            ['admin.bucket3.custom_fields.cancel_edit', 'en', 'Cancel edit'],
            ['admin.bucket3.custom_fields.cancel_edit', 'hy', 'Չեղարկել խմբագրումը'],
            ['admin.bucket3.custom_fields.cancel_edit', 'ru', 'Отменить правку'],

            ['admin.bucket3.custom_fields.col.actions', 'en', 'Actions'],
            ['admin.bucket3.custom_fields.col.actions', 'hy', 'Գործողություններ'],
            ['admin.bucket3.custom_fields.col.actions', 'ru', 'Действия'],

            ['admin.bucket3.custom_fields.col.active', 'en', 'Active'],
            ['admin.bucket3.custom_fields.col.active', 'hy', 'Ակտիվ'],
            ['admin.bucket3.custom_fields.col.active', 'ru', 'Активен'],

            ['admin.bucket3.custom_fields.col.flags', 'en', 'Flags'],
            ['admin.bucket3.custom_fields.col.flags', 'hy', 'Դրոշակներ'],
            ['admin.bucket3.custom_fields.col.flags', 'ru', 'Флаги'],

            ['admin.bucket3.custom_fields.col.key', 'en', 'Key'],
            ['admin.bucket3.custom_fields.col.key', 'hy', 'Բանալի'],
            ['admin.bucket3.custom_fields.col.key', 'ru', 'Ключ'],

            ['admin.bucket3.custom_fields.col.label', 'en', 'Label'],
            ['admin.bucket3.custom_fields.col.label', 'hy', 'Պիտակ'],
            ['admin.bucket3.custom_fields.col.label', 'ru', 'Метка'],

            ['admin.bucket3.custom_fields.col.order', 'en', 'Order'],
            ['admin.bucket3.custom_fields.col.order', 'hy', 'Հերթ'],
            ['admin.bucket3.custom_fields.col.order', 'ru', 'Порядок'],

            ['admin.bucket3.custom_fields.col.scope', 'en', 'Scope'],
            ['admin.bucket3.custom_fields.col.scope', 'hy', 'Շրջանակ'],
            ['admin.bucket3.custom_fields.col.scope', 'ru', 'Область'],

            ['admin.bucket3.custom_fields.col.type', 'en', 'Type'],
            ['admin.bucket3.custom_fields.col.type', 'hy', 'Տեսակ'],
            ['admin.bucket3.custom_fields.col.type', 'ru', 'Тип'],

            ['admin.bucket3.custom_fields.edit_field', 'en', 'Edit field #{id}'],
            ['admin.bucket3.custom_fields.edit_field', 'hy', 'Խմբագրել դաշտ №{id}'],
            ['admin.bucket3.custom_fields.edit_field', 'ru', 'Редактировать поле №{id}'],

            ['admin.bucket3.custom_fields.empty', 'en', 'No custom fields defined yet.'],
            ['admin.bucket3.custom_fields.empty', 'hy', 'Հատուկ դաշտեր դեռ սահմանված չեն։'],
            ['admin.bucket3.custom_fields.empty', 'ru', 'Пользовательские поля пока не заданы.'],

            ['admin.bucket3.custom_fields.error.key_format', 'en', 'Key must contain only lowercase letters, numbers and underscores'],
            ['admin.bucket3.custom_fields.error.key_format', 'hy', 'Բանալին պետք է պարունակի միայն փոքրատառեր, թվեր և ընդգծումներ'],
            ['admin.bucket3.custom_fields.error.key_format', 'ru', 'Ключ может содержать только строчные буквы, цифры и подчёркивания'],

            ['admin.bucket3.custom_fields.error.key_label_required', 'en', 'Key and label are required'],
            ['admin.bucket3.custom_fields.error.key_label_required', 'hy', 'Բանալին և պիտակը պարտադիր են'],
            ['admin.bucket3.custom_fields.error.key_label_required', 'ru', 'Ключ и метка обязательны'],

            ['admin.bucket3.custom_fields.field.active', 'en', 'Active'],
            ['admin.bucket3.custom_fields.field.active', 'hy', 'Ակտիվ'],
            ['admin.bucket3.custom_fields.field.active', 'ru', 'Активно'],

            ['admin.bucket3.custom_fields.field.display_order', 'en', 'Display order'],
            ['admin.bucket3.custom_fields.field.display_order', 'hy', 'Ցուցադրման հերթականություն'],
            ['admin.bucket3.custom_fields.field.display_order', 'ru', 'Порядок отображения'],

            ['admin.bucket3.custom_fields.field.help_text', 'en', 'Help text'],
            ['admin.bucket3.custom_fields.field.help_text', 'hy', 'Օգնության տեքստ'],
            ['admin.bucket3.custom_fields.field.help_text', 'ru', 'Подсказка'],

            ['admin.bucket3.custom_fields.field.help_text_placeholder', 'en', 'Optional helper text shown under the field'],
            ['admin.bucket3.custom_fields.field.help_text_placeholder', 'hy', 'Ոչ պարտադիր օգնության տեքստ՝ դաշտի տակ'],
            ['admin.bucket3.custom_fields.field.help_text_placeholder', 'ru', 'Необязательная подсказка под полем'],

            ['admin.bucket3.custom_fields.field.key', 'en', 'Key'],
            ['admin.bucket3.custom_fields.field.key', 'hy', 'Բանալի'],
            ['admin.bucket3.custom_fields.field.key', 'ru', 'Ключ'],

            ['admin.bucket3.custom_fields.field.key_helper', 'en', 'Used in the API; cannot be changed later'],
            ['admin.bucket3.custom_fields.field.key_helper', 'hy', 'Օգտագործվում է API-ում, հետագայում չի փոխվում'],
            ['admin.bucket3.custom_fields.field.key_helper', 'ru', 'Используется в API, изменить позже нельзя'],

            ['admin.bucket3.custom_fields.field.key_placeholder', 'en', 'e.g. passport_number'],
            ['admin.bucket3.custom_fields.field.key_placeholder', 'hy', 'օր.՝ passport_number'],
            ['admin.bucket3.custom_fields.field.key_placeholder', 'ru', 'напр.: passport_number'],

            ['admin.bucket3.custom_fields.field.label', 'en', 'Label'],
            ['admin.bucket3.custom_fields.field.label', 'hy', 'Պիտակ'],
            ['admin.bucket3.custom_fields.field.label', 'ru', 'Метка'],

            ['admin.bucket3.custom_fields.field.label_placeholder', 'en', 'Shown to the user'],
            ['admin.bucket3.custom_fields.field.label_placeholder', 'hy', 'Ցուցադրվում է օգտատիրոջը'],
            ['admin.bucket3.custom_fields.field.label_placeholder', 'ru', 'Показывается пользователю'],

            ['admin.bucket3.custom_fields.field.options', 'en', 'Options'],
            ['admin.bucket3.custom_fields.field.options', 'hy', 'Տարբերակներ'],
            ['admin.bucket3.custom_fields.field.options', 'ru', 'Варианты'],

            ['admin.bucket3.custom_fields.field.options_helper', 'en', 'For select/multi-select; one option per line'],
            ['admin.bucket3.custom_fields.field.options_helper', 'hy', 'Select/multi-select դաշտերի համար, տող առ տող'],
            ['admin.bucket3.custom_fields.field.options_helper', 'ru', 'Для select/multi-select; по одному варианту на строку'],

            ['admin.bucket3.custom_fields.field.required', 'en', 'Required'],
            ['admin.bucket3.custom_fields.field.required', 'hy', 'Պարտադիր'],
            ['admin.bucket3.custom_fields.field.required', 'ru', 'Обязательно'],

            ['admin.bucket3.custom_fields.field.scope', 'en', 'Scope'],
            ['admin.bucket3.custom_fields.field.scope', 'hy', 'Շրջանակ'],
            ['admin.bucket3.custom_fields.field.scope', 'ru', 'Область'],

            ['admin.bucket3.custom_fields.field.show_in_filter', 'en', 'Show in filter'],
            ['admin.bucket3.custom_fields.field.show_in_filter', 'hy', 'Ցուցադրել զտիչում'],
            ['admin.bucket3.custom_fields.field.show_in_filter', 'ru', 'Показывать в фильтре'],

            ['admin.bucket3.custom_fields.field.type', 'en', 'Type'],
            ['admin.bucket3.custom_fields.field.type', 'hy', 'Տեսակ'],
            ['admin.bucket3.custom_fields.field.type', 'ru', 'Тип'],

            ['admin.bucket3.custom_fields.save_changes', 'en', 'Save changes'],
            ['admin.bucket3.custom_fields.save_changes', 'hy', 'Պահպանել փոփոխությունները'],
            ['admin.bucket3.custom_fields.save_changes', 'ru', 'Сохранить изменения'],

            ['admin.bucket3.custom_fields.status.active', 'en', 'Active'],
            ['admin.bucket3.custom_fields.status.active', 'hy', 'Ակտիվ'],
            ['admin.bucket3.custom_fields.status.active', 'ru', 'Активно'],

            ['admin.bucket3.custom_fields.status.inactive', 'en', 'Inactive'],
            ['admin.bucket3.custom_fields.status.inactive', 'hy', 'Ոչ ակտիվ'],
            ['admin.bucket3.custom_fields.status.inactive', 'ru', 'Неактивно'],

            ['admin.bucket3.custom_fields.subtitle', 'en', 'Define custom fields that appear on bookings and profiles'],
            ['admin.bucket3.custom_fields.subtitle', 'hy', 'Սահմանեք հատուկ դաշտեր ամրագրումների և պրոֆիլների համար'],
            ['admin.bucket3.custom_fields.subtitle', 'ru', 'Задайте пользовательские поля для бронирований и профилей'],

            ['admin.bucket3.custom_fields.subtitle_count', 'en', '{count} fields defined'],
            ['admin.bucket3.custom_fields.subtitle_count', 'hy', '{count} դաշտ սահմանված է'],
            ['admin.bucket3.custom_fields.subtitle_count', 'ru', '{count} полей задано'],

            ['admin.bucket3.custom_fields.title', 'en', 'Custom fields'],
            ['admin.bucket3.custom_fields.title', 'hy', 'Հատուկ դաշտեր'],
            ['admin.bucket3.custom_fields.title', 'ru', 'Пользовательские поля'],

            // ────────────────────────────────────────────────────────────
            // CUSTOMERS
            // ────────────────────────────────────────────────────────────
            ['admin.bucket3.customers.col.bookings', 'en', 'Bookings'],
            ['admin.bucket3.customers.col.bookings', 'hy', 'Ամրագրումներ'],
            ['admin.bucket3.customers.col.bookings', 'ru', 'Бронирования'],

            ['admin.bucket3.customers.col.email', 'en', 'Email'],
            ['admin.bucket3.customers.col.email', 'hy', 'Էլ. փոստ'],
            ['admin.bucket3.customers.col.email', 'ru', 'Эл. почта'],

            ['admin.bucket3.customers.col.joined', 'en', 'Joined'],
            ['admin.bucket3.customers.col.joined', 'hy', 'Միացել է'],
            ['admin.bucket3.customers.col.joined', 'ru', 'Присоединился'],

            ['admin.bucket3.customers.col.language', 'en', 'Language'],
            ['admin.bucket3.customers.col.language', 'hy', 'Լեզու'],
            ['admin.bucket3.customers.col.language', 'ru', 'Язык'],

            ['admin.bucket3.customers.col.name', 'en', 'Name'],
            ['admin.bucket3.customers.col.name', 'hy', 'Անուն'],
            ['admin.bucket3.customers.col.name', 'ru', 'Имя'],

            ['admin.bucket3.customers.col.nationality', 'en', 'Nationality'],
            ['admin.bucket3.customers.col.nationality', 'hy', 'Քաղաքացիություն'],
            ['admin.bucket3.customers.col.nationality', 'ru', 'Гражданство'],

            ['admin.bucket3.customers.col.phone', 'en', 'Phone'],
            ['admin.bucket3.customers.col.phone', 'hy', 'Հեռախոս'],
            ['admin.bucket3.customers.col.phone', 'ru', 'Телефон'],

            ['admin.bucket3.customers.col.status', 'en', 'Status'],
            ['admin.bucket3.customers.col.status', 'hy', 'Կարգավիճակ'],
            ['admin.bucket3.customers.col.status', 'ru', 'Статус'],

            ['admin.bucket3.customers.empty', 'en', 'No customers yet.'],
            ['admin.bucket3.customers.empty', 'hy', 'Հաճախորդներ դեռ չկան։'],
            ['admin.bucket3.customers.empty', 'ru', 'Клиентов пока нет.'],

            ['admin.bucket3.customers.empty_filter', 'en', 'No customers match the current filter.'],
            ['admin.bucket3.customers.empty_filter', 'hy', 'Ընթացիկ զտիչին համապատասխանող հաճախորդ չկա։'],
            ['admin.bucket3.customers.empty_filter', 'ru', 'Под текущий фильтр клиенты не найдены.'],

            ['admin.bucket3.customers.filter.status', 'en', 'Status'],
            ['admin.bucket3.customers.filter.status', 'hy', 'Կարգավիճակ'],
            ['admin.bucket3.customers.filter.status', 'ru', 'Статус'],

            ['admin.bucket3.customers.refresh', 'en', 'Refresh'],
            ['admin.bucket3.customers.refresh', 'hy', 'Թարմացնել'],
            ['admin.bucket3.customers.refresh', 'ru', 'Обновить'],

            ['admin.bucket3.customers.search_placeholder', 'en', 'Search by name, email or phone…'],
            ['admin.bucket3.customers.search_placeholder', 'hy', 'Որոնել ըստ անվան, էլ. փոստի կամ հեռախոսի…'],
            ['admin.bucket3.customers.search_placeholder', 'ru', 'Поиск по имени, эл. почте или телефону…'],

            ['admin.bucket3.customers.subtitle', 'en', 'Browse and manage B2C customer accounts'],
            ['admin.bucket3.customers.subtitle', 'hy', 'Դիտեք և կառավարեք B2C հաճախորդների հաշիվները'],
            ['admin.bucket3.customers.subtitle', 'ru', 'Просмотр и управление B2C-аккаунтами клиентов'],

            ['admin.bucket3.customers.subtitle_count', 'en', '{count} customers'],
            ['admin.bucket3.customers.subtitle_count', 'hy', '{count} հաճախորդ'],
            ['admin.bucket3.customers.subtitle_count', 'ru', '{count} клиентов'],

            ['admin.bucket3.customers.title', 'en', 'Customers'],
            ['admin.bucket3.customers.title', 'hy', 'Հաճախորդներ'],
            ['admin.bucket3.customers.title', 'ru', 'Клиенты'],

            // ────────────────────────────────────────────────────────────
            // EMPLOYEES
            // ────────────────────────────────────────────────────────────
            ['admin.bucket3.employees.col.companies', 'en', 'Companies'],
            ['admin.bucket3.employees.col.companies', 'hy', 'Ընկերություններ'],
            ['admin.bucket3.employees.col.companies', 'ru', 'Компании'],

            ['admin.bucket3.employees.col.email', 'en', 'Email'],
            ['admin.bucket3.employees.col.email', 'hy', 'Էլ. փոստ'],
            ['admin.bucket3.employees.col.email', 'ru', 'Эл. почта'],

            ['admin.bucket3.employees.col.joined', 'en', 'Joined'],
            ['admin.bucket3.employees.col.joined', 'hy', 'Միացել է'],
            ['admin.bucket3.employees.col.joined', 'ru', 'Присоединился'],

            ['admin.bucket3.employees.col.name', 'en', 'Name'],
            ['admin.bucket3.employees.col.name', 'hy', 'Անուն'],
            ['admin.bucket3.employees.col.name', 'ru', 'Имя'],

            ['admin.bucket3.employees.col.status', 'en', 'Status'],
            ['admin.bucket3.employees.col.status', 'hy', 'Կարգավիճակ'],
            ['admin.bucket3.employees.col.status', 'ru', 'Статус'],

            ['admin.bucket3.employees.empty', 'en', 'No employees yet.'],
            ['admin.bucket3.employees.empty', 'hy', 'Աշխատակիցներ դեռ չկան։'],
            ['admin.bucket3.employees.empty', 'ru', 'Сотрудников пока нет.'],

            ['admin.bucket3.employees.filter.status', 'en', 'Status'],
            ['admin.bucket3.employees.filter.status', 'hy', 'Կարգավիճակ'],
            ['admin.bucket3.employees.filter.status', 'ru', 'Статус'],

            ['admin.bucket3.employees.search_placeholder', 'en', 'Search by name or email…'],
            ['admin.bucket3.employees.search_placeholder', 'hy', 'Որոնել ըստ անվան կամ էլ. փոստի…'],
            ['admin.bucket3.employees.search_placeholder', 'ru', 'Поиск по имени или эл. почте…'],

            ['admin.bucket3.employees.status.active', 'en', 'Active'],
            ['admin.bucket3.employees.status.active', 'hy', 'Ակտիվ'],
            ['admin.bucket3.employees.status.active', 'ru', 'Активен'],

            ['admin.bucket3.employees.status.inactive', 'en', 'Inactive'],
            ['admin.bucket3.employees.status.inactive', 'hy', 'Ոչ ակտիվ'],
            ['admin.bucket3.employees.status.inactive', 'ru', 'Неактивен'],

            ['admin.bucket3.employees.status.pending', 'en', 'Pending'],
            ['admin.bucket3.employees.status.pending', 'hy', 'Սպասվող'],
            ['admin.bucket3.employees.status.pending', 'ru', 'Ожидает'],

            ['admin.bucket3.employees.status.suspended', 'en', 'Suspended'],
            ['admin.bucket3.employees.status.suspended', 'hy', 'Կասեցված'],
            ['admin.bucket3.employees.status.suspended', 'ru', 'Приостановлен'],

            ['admin.bucket3.employees.subtitle', 'en', 'Browse and manage staff accounts across companies'],
            ['admin.bucket3.employees.subtitle', 'hy', 'Դիտեք և կառավարեք անձնակազմի հաշիվները ընկերությունների միջև'],
            ['admin.bucket3.employees.subtitle', 'ru', 'Просмотр и управление аккаунтами персонала по компаниям'],

            ['admin.bucket3.employees.subtitle_count', 'en', '{count} employees'],
            ['admin.bucket3.employees.subtitle_count', 'hy', '{count} աշխատակից'],
            ['admin.bucket3.employees.subtitle_count', 'ru', '{count} сотрудников'],

            ['admin.bucket3.employees.title', 'en', 'Employees'],
            ['admin.bucket3.employees.title', 'hy', 'Աշխատակիցներ'],
            ['admin.bucket3.employees.title', 'ru', 'Сотрудники'],

            // ────────────────────────────────────────────────────────────
            // NON SERVICE HOURS
            // ────────────────────────────────────────────────────────────
            ['admin.bucket3.non_service_hours.approve', 'en', 'Approve'],
            ['admin.bucket3.non_service_hours.approve', 'hy', 'Հաստատել'],
            ['admin.bucket3.non_service_hours.approve', 'ru', 'Утвердить'],

            ['admin.bucket3.non_service_hours.clock_in', 'en', 'Clock in'],
            ['admin.bucket3.non_service_hours.clock_in', 'hy', 'Մուտք գործել ժամկետին'],
            ['admin.bucket3.non_service_hours.clock_in', 'ru', 'Начать смену'],

            ['admin.bucket3.non_service_hours.clock_out', 'en', 'Clock out'],
            ['admin.bucket3.non_service_hours.clock_out', 'hy', 'Ավարտել ժամկետը'],
            ['admin.bucket3.non_service_hours.clock_out', 'ru', 'Завершить смену'],

            ['admin.bucket3.non_service_hours.clock_out_open', 'en', 'Clock out (started {time})'],
            ['admin.bucket3.non_service_hours.clock_out_open', 'hy', 'Ավարտել (սկսվել է {time})'],
            ['admin.bucket3.non_service_hours.clock_out_open', 'ru', 'Завершить (начата {time})'],

            ['admin.bucket3.non_service_hours.col.actions', 'en', 'Actions'],
            ['admin.bucket3.non_service_hours.col.actions', 'hy', 'Գործողություններ'],
            ['admin.bucket3.non_service_hours.col.actions', 'ru', 'Действия'],

            ['admin.bucket3.non_service_hours.col.duration', 'en', 'Duration'],
            ['admin.bucket3.non_service_hours.col.duration', 'hy', 'Տևողություն'],
            ['admin.bucket3.non_service_hours.col.duration', 'ru', 'Длительность'],

            ['admin.bucket3.non_service_hours.col.employee', 'en', 'Employee'],
            ['admin.bucket3.non_service_hours.col.employee', 'hy', 'Աշխատակից'],
            ['admin.bucket3.non_service_hours.col.employee', 'ru', 'Сотрудник'],

            ['admin.bucket3.non_service_hours.col.from', 'en', 'From'],
            ['admin.bucket3.non_service_hours.col.from', 'hy', 'Սկսած'],
            ['admin.bucket3.non_service_hours.col.from', 'ru', 'С'],

            ['admin.bucket3.non_service_hours.col.hours', 'en', 'Hours'],
            ['admin.bucket3.non_service_hours.col.hours', 'hy', 'Ժամեր'],
            ['admin.bucket3.non_service_hours.col.hours', 'ru', 'Часы'],

            ['admin.bucket3.non_service_hours.col.in', 'en', 'In'],
            ['admin.bucket3.non_service_hours.col.in', 'hy', 'Մուտք'],
            ['admin.bucket3.non_service_hours.col.in', 'ru', 'Вход'],

            ['admin.bucket3.non_service_hours.col.out', 'en', 'Out'],
            ['admin.bucket3.non_service_hours.col.out', 'hy', 'Ելք'],
            ['admin.bucket3.non_service_hours.col.out', 'ru', 'Выход'],

            ['admin.bucket3.non_service_hours.col.status', 'en', 'Status'],
            ['admin.bucket3.non_service_hours.col.status', 'hy', 'Կարգավիճակ'],
            ['admin.bucket3.non_service_hours.col.status', 'ru', 'Статус'],

            ['admin.bucket3.non_service_hours.col.to', 'en', 'To'],
            ['admin.bucket3.non_service_hours.col.to', 'hy', 'Մինչև'],
            ['admin.bucket3.non_service_hours.col.to', 'ru', 'До'],

            ['admin.bucket3.non_service_hours.col.type', 'en', 'Type'],
            ['admin.bucket3.non_service_hours.col.type', 'hy', 'Տեսակ'],
            ['admin.bucket3.non_service_hours.col.type', 'ru', 'Тип'],

            ['admin.bucket3.non_service_hours.empty_records', 'en', 'No time-off records yet.'],
            ['admin.bucket3.non_service_hours.empty_records', 'hy', 'Արձակուրդի գրառումներ դեռ չկան։'],
            ['admin.bucket3.non_service_hours.empty_records', 'ru', 'Записей об отгулах пока нет.'],

            ['admin.bucket3.non_service_hours.empty_shifts', 'en', 'No shifts today.'],
            ['admin.bucket3.non_service_hours.empty_shifts', 'hy', 'Այսօր հերթափոխեր չկան։'],
            ['admin.bucket3.non_service_hours.empty_shifts', 'ru', 'Смен сегодня нет.'],

            ['admin.bucket3.non_service_hours.error.dates_required', 'en', 'Start and end dates are required'],
            ['admin.bucket3.non_service_hours.error.dates_required', 'hy', 'Սկզբի և ավարտի ամսաթվերը պարտադիր են'],
            ['admin.bucket3.non_service_hours.error.dates_required', 'ru', 'Даты начала и окончания обязательны'],

            ['admin.bucket3.non_service_hours.field.ends', 'en', 'Ends'],
            ['admin.bucket3.non_service_hours.field.ends', 'hy', 'Ավարտ'],
            ['admin.bucket3.non_service_hours.field.ends', 'ru', 'Окончание'],

            ['admin.bucket3.non_service_hours.field.hours_total', 'en', 'Total hours'],
            ['admin.bucket3.non_service_hours.field.hours_total', 'hy', 'Ընդհանուր ժամեր'],
            ['admin.bucket3.non_service_hours.field.hours_total', 'ru', 'Всего часов'],

            ['admin.bucket3.non_service_hours.field.notes', 'en', 'Notes'],
            ['admin.bucket3.non_service_hours.field.notes', 'hy', 'Նշումներ'],
            ['admin.bucket3.non_service_hours.field.notes', 'ru', 'Примечания'],

            ['admin.bucket3.non_service_hours.field.starts', 'en', 'Starts'],
            ['admin.bucket3.non_service_hours.field.starts', 'hy', 'Սկիզբ'],
            ['admin.bucket3.non_service_hours.field.starts', 'ru', 'Начало'],

            ['admin.bucket3.non_service_hours.field.type', 'en', 'Type'],
            ['admin.bucket3.non_service_hours.field.type', 'hy', 'Տեսակ'],
            ['admin.bucket3.non_service_hours.field.type', 'ru', 'Тип'],

            ['admin.bucket3.non_service_hours.field.user_id', 'en', 'User ID'],
            ['admin.bucket3.non_service_hours.field.user_id', 'hy', 'Օգտատիրոջ ID'],
            ['admin.bucket3.non_service_hours.field.user_id', 'ru', 'ID пользователя'],

            ['admin.bucket3.non_service_hours.field.user_id_helper', 'en', 'Leave empty for yourself'],
            ['admin.bucket3.non_service_hours.field.user_id_helper', 'hy', 'Թողեք դատարկ՝ ձեր համար'],
            ['admin.bucket3.non_service_hours.field.user_id_helper', 'ru', 'Оставьте пустым для себя'],

            ['admin.bucket3.non_service_hours.filter.status', 'en', 'Status'],
            ['admin.bucket3.non_service_hours.filter.status', 'hy', 'Կարգավիճակ'],
            ['admin.bucket3.non_service_hours.filter.status', 'ru', 'Статус'],

            ['admin.bucket3.non_service_hours.on_the_clock', 'en', 'On the clock'],
            ['admin.bucket3.non_service_hours.on_the_clock', 'hy', 'Աշխատանքի մեջ է'],
            ['admin.bucket3.non_service_hours.on_the_clock', 'ru', 'На смене'],

            ['admin.bucket3.non_service_hours.reject', 'en', 'Reject'],
            ['admin.bucket3.non_service_hours.reject', 'hy', 'Մերժել'],
            ['admin.bucket3.non_service_hours.reject', 'ru', 'Отклонить'],

            ['admin.bucket3.non_service_hours.request_time_off', 'en', 'Request time off'],
            ['admin.bucket3.non_service_hours.request_time_off', 'hy', 'Հայցել արձակուրդ'],
            ['admin.bucket3.non_service_hours.request_time_off', 'ru', 'Запросить отгул'],

            ['admin.bucket3.non_service_hours.submit_request', 'en', 'Submit request'],
            ['admin.bucket3.non_service_hours.submit_request', 'hy', 'Ուղարկել հայցը'],
            ['admin.bucket3.non_service_hours.submit_request', 'ru', 'Отправить запрос'],

            ['admin.bucket3.non_service_hours.submitting', 'en', 'Submitting…'],
            ['admin.bucket3.non_service_hours.submitting', 'hy', 'Ուղարկվում է…'],
            ['admin.bucket3.non_service_hours.submitting', 'ru', 'Отправляется…'],

            ['admin.bucket3.non_service_hours.subtitle', 'en', 'Track clock-in/out and time-off requests'],
            ['admin.bucket3.non_service_hours.subtitle', 'hy', 'Հետևեք մուտքի/ելքի և արձակուրդի հայցերին'],
            ['admin.bucket3.non_service_hours.subtitle', 'ru', 'Учёт прихода/ухода и запросов на отгулы'],

            ['admin.bucket3.non_service_hours.title', 'en', 'Non-service hours'],
            ['admin.bucket3.non_service_hours.title', 'hy', 'Ոչ սպասարկման ժամեր'],
            ['admin.bucket3.non_service_hours.title', 'ru', 'Нерабочие часы'],

            ['admin.bucket3.non_service_hours.todays_shifts', 'en', "Today's shifts"],
            ['admin.bucket3.non_service_hours.todays_shifts', 'hy', 'Այսօրվա հերթափոխերը'],
            ['admin.bucket3.non_service_hours.todays_shifts', 'ru', 'Сегодняшние смены'],

            ['admin.bucket3.non_service_hours.todays_shifts_helper', 'en', 'Shows clock-in/out activity for the current day'],
            ['admin.bucket3.non_service_hours.todays_shifts_helper', 'hy', 'Ցույց է տալիս ընթացիկ օրվա մուտք/ելքի շարժը'],
            ['admin.bucket3.non_service_hours.todays_shifts_helper', 'ru', 'Показывает приходы/уходы за текущий день'],

            // ────────────────────────────────────────────────────────────
            // PAYROLL
            // ────────────────────────────────────────────────────────────
            ['admin.bucket3.payroll.add_record', 'en', 'Add payroll record'],
            ['admin.bucket3.payroll.add_record', 'hy', 'Ավելացնել աշխատավարձի գրառում'],
            ['admin.bucket3.payroll.add_record', 'ru', 'Добавить запись зарплаты'],

            ['admin.bucket3.payroll.col.actions', 'en', 'Actions'],
            ['admin.bucket3.payroll.col.actions', 'hy', 'Գործողություններ'],
            ['admin.bucket3.payroll.col.actions', 'ru', 'Действия'],

            ['admin.bucket3.payroll.col.deductions', 'en', 'Deductions'],
            ['admin.bucket3.payroll.col.deductions', 'hy', 'Պահումներ'],
            ['admin.bucket3.payroll.col.deductions', 'ru', 'Удержания'],

            ['admin.bucket3.payroll.col.employee', 'en', 'Employee'],
            ['admin.bucket3.payroll.col.employee', 'hy', 'Աշխատակից'],
            ['admin.bucket3.payroll.col.employee', 'ru', 'Сотрудник'],

            ['admin.bucket3.payroll.col.gross', 'en', 'Gross'],
            ['admin.bucket3.payroll.col.gross', 'hy', 'Համախառն'],
            ['admin.bucket3.payroll.col.gross', 'ru', 'Брутто'],

            ['admin.bucket3.payroll.col.net', 'en', 'Net'],
            ['admin.bucket3.payroll.col.net', 'hy', 'Զուտ'],
            ['admin.bucket3.payroll.col.net', 'ru', 'Нетто'],

            ['admin.bucket3.payroll.col.period', 'en', 'Period'],
            ['admin.bucket3.payroll.col.period', 'hy', 'Ժամանակահատված'],
            ['admin.bucket3.payroll.col.period', 'ru', 'Период'],

            ['admin.bucket3.payroll.col.status', 'en', 'Status'],
            ['admin.bucket3.payroll.col.status', 'hy', 'Կարգավիճակ'],
            ['admin.bucket3.payroll.col.status', 'ru', 'Статус'],

            ['admin.bucket3.payroll.confirm_move', 'en', 'Move record to {status}?'],
            ['admin.bucket3.payroll.confirm_move', 'hy', 'Տեղափոխե՞լ գրառումը {status} կարգավիճակի։'],
            ['admin.bucket3.payroll.confirm_move', 'ru', 'Перевести запись в статус {status}?'],

            ['admin.bucket3.payroll.create_record', 'en', 'Create record'],
            ['admin.bucket3.payroll.create_record', 'hy', 'Ստեղծել գրառում'],
            ['admin.bucket3.payroll.create_record', 'ru', 'Создать запись'],

            ['admin.bucket3.payroll.empty', 'en', 'No payroll records yet.'],
            ['admin.bucket3.payroll.empty', 'hy', 'Աշխատավարձի գրառումներ դեռ չկան։'],
            ['admin.bucket3.payroll.empty', 'ru', 'Записей по зарплате пока нет.'],

            ['admin.bucket3.payroll.error.dates_and_user', 'en', 'User and period dates are required'],
            ['admin.bucket3.payroll.error.dates_and_user', 'hy', 'Օգտատերն ու ժամանակահատվածի ամսաթվերը պարտադիր են'],
            ['admin.bucket3.payroll.error.dates_and_user', 'ru', 'Пользователь и даты периода обязательны'],

            ['admin.bucket3.payroll.export_bank_batch', 'en', 'Export bank batch'],
            ['admin.bucket3.payroll.export_bank_batch', 'hy', 'Արտահանել բանկային փաթեթը'],
            ['admin.bucket3.payroll.export_bank_batch', 'ru', 'Экспорт банковской пачки'],

            ['admin.bucket3.payroll.field.base_salary', 'en', 'Base salary'],
            ['admin.bucket3.payroll.field.base_salary', 'hy', 'Հիմնական աշխատավարձ'],
            ['admin.bucket3.payroll.field.base_salary', 'ru', 'Базовая зарплата'],

            ['admin.bucket3.payroll.field.bonus', 'en', 'Bonus'],
            ['admin.bucket3.payroll.field.bonus', 'hy', 'Հավելավճար'],
            ['admin.bucket3.payroll.field.bonus', 'ru', 'Премия'],

            ['admin.bucket3.payroll.field.commission', 'en', 'Commission'],
            ['admin.bucket3.payroll.field.commission', 'hy', 'Միջնորդավճար'],
            ['admin.bucket3.payroll.field.commission', 'ru', 'Комиссия'],

            ['admin.bucket3.payroll.field.currency', 'en', 'Currency'],
            ['admin.bucket3.payroll.field.currency', 'hy', 'Արժույթ'],
            ['admin.bucket3.payroll.field.currency', 'ru', 'Валюта'],

            ['admin.bucket3.payroll.field.deductions', 'en', 'Deductions'],
            ['admin.bucket3.payroll.field.deductions', 'hy', 'Պահումներ'],
            ['admin.bucket3.payroll.field.deductions', 'ru', 'Удержания'],

            ['admin.bucket3.payroll.field.hourly_rate', 'en', 'Hourly rate'],
            ['admin.bucket3.payroll.field.hourly_rate', 'hy', 'Ժամային դրույք'],
            ['admin.bucket3.payroll.field.hourly_rate', 'ru', 'Почасовая ставка'],

            ['admin.bucket3.payroll.field.hours_worked', 'en', 'Hours worked'],
            ['admin.bucket3.payroll.field.hours_worked', 'hy', 'Աշխատած ժամեր'],
            ['admin.bucket3.payroll.field.hours_worked', 'ru', 'Отработанные часы'],

            ['admin.bucket3.payroll.field.notes', 'en', 'Notes'],
            ['admin.bucket3.payroll.field.notes', 'hy', 'Նշումներ'],
            ['admin.bucket3.payroll.field.notes', 'ru', 'Примечания'],

            ['admin.bucket3.payroll.field.period_ends', 'en', 'Period ends'],
            ['admin.bucket3.payroll.field.period_ends', 'hy', 'Ժամանակահատվածի ավարտ'],
            ['admin.bucket3.payroll.field.period_ends', 'ru', 'Окончание периода'],

            ['admin.bucket3.payroll.field.period_starts', 'en', 'Period starts'],
            ['admin.bucket3.payroll.field.period_starts', 'hy', 'Ժամանակահատվածի սկիզբ'],
            ['admin.bucket3.payroll.field.period_starts', 'ru', 'Начало периода'],

            ['admin.bucket3.payroll.field.user_id', 'en', 'User ID'],
            ['admin.bucket3.payroll.field.user_id', 'hy', 'Օգտատիրոջ ID'],
            ['admin.bucket3.payroll.field.user_id', 'ru', 'ID пользователя'],

            ['admin.bucket3.payroll.filter.status', 'en', 'Status'],
            ['admin.bucket3.payroll.filter.status', 'hy', 'Կարգավիճակ'],
            ['admin.bucket3.payroll.filter.status', 'ru', 'Статус'],

            ['admin.bucket3.payroll.finalize', 'en', 'Finalize'],
            ['admin.bucket3.payroll.finalize', 'hy', 'Վերջնականացնել'],
            ['admin.bucket3.payroll.finalize', 'ru', 'Утвердить'],

            ['admin.bucket3.payroll.gross_net_helper', 'en', 'Gross and net amounts are computed automatically from the values above'],
            ['admin.bucket3.payroll.gross_net_helper', 'hy', 'Համախառն և զուտ գումարները հաշվարկվում են ավտոմատ՝ վերը նշված արժեքներից'],
            ['admin.bucket3.payroll.gross_net_helper', 'ru', 'Брутто и нетто рассчитываются автоматически из указанных выше значений'],

            ['admin.bucket3.payroll.mark_paid', 'en', 'Mark paid'],
            ['admin.bucket3.payroll.mark_paid', 'hy', 'Նշել որպես վճարված'],
            ['admin.bucket3.payroll.mark_paid', 'ru', 'Отметить как выплачено'],

            ['admin.bucket3.payroll.paid', 'en', 'Paid {date}'],
            ['admin.bucket3.payroll.paid', 'hy', 'Վճարված է {date}'],
            ['admin.bucket3.payroll.paid', 'ru', 'Выплачено {date}'],

            ['admin.bucket3.payroll.payslip', 'en', 'Payslip'],
            ['admin.bucket3.payroll.payslip', 'hy', 'Աշխատավարձի թերթիկ'],
            ['admin.bucket3.payroll.payslip', 'ru', 'Расчётный лист'],

            ['admin.bucket3.payroll.subtitle', 'en', 'Manage payroll runs and employee compensation records'],
            ['admin.bucket3.payroll.subtitle', 'hy', 'Կառավարեք աշխատավարձի հաշվարկները և աշխատակիցների վճարման գրառումները'],
            ['admin.bucket3.payroll.subtitle', 'ru', 'Управление зарплатными расчётами и записями выплат сотрудникам'],

            ['admin.bucket3.payroll.subtitle_count', 'en', '{count} payroll records'],
            ['admin.bucket3.payroll.subtitle_count', 'hy', '{count} աշխատավարձի գրառում'],
            ['admin.bucket3.payroll.subtitle_count', 'ru', '{count} записей по зарплате'],

            ['admin.bucket3.payroll.title', 'en', 'Payroll'],
            ['admin.bucket3.payroll.title', 'hy', 'Աշխատավարձ'],
            ['admin.bucket3.payroll.title', 'ru', 'Зарплата'],

            // ────────────────────────────────────────────────────────────
            // PER X INVOICING
            // ────────────────────────────────────────────────────────────
            ['admin.bucket3.per_x_invoicing.col.bucket', 'en', 'Bucket'],
            ['admin.bucket3.per_x_invoicing.col.bucket', 'hy', 'Խմբավորում'],
            ['admin.bucket3.per_x_invoicing.col.bucket', 'ru', 'Группа'],

            ['admin.bucket3.per_x_invoicing.col.currency', 'en', 'Currency'],
            ['admin.bucket3.per_x_invoicing.col.currency', 'hy', 'Արժույթ'],
            ['admin.bucket3.per_x_invoicing.col.currency', 'ru', 'Валюта'],

            ['admin.bucket3.per_x_invoicing.col.invoices', 'en', 'Invoices'],
            ['admin.bucket3.per_x_invoicing.col.invoices', 'hy', 'Հաշիվ-ապրանքագրեր'],
            ['admin.bucket3.per_x_invoicing.col.invoices', 'ru', 'Счета'],

            ['admin.bucket3.per_x_invoicing.col.operator', 'en', 'Operator'],
            ['admin.bucket3.per_x_invoicing.col.operator', 'hy', 'Օպերատոր'],
            ['admin.bucket3.per_x_invoicing.col.operator', 'ru', 'Оператор'],

            ['admin.bucket3.per_x_invoicing.col.total', 'en', 'Total'],
            ['admin.bucket3.per_x_invoicing.col.total', 'hy', 'Ընդամենը'],
            ['admin.bucket3.per_x_invoicing.col.total', 'ru', 'Итого'],

            ['admin.bucket3.per_x_invoicing.empty', 'en', 'No invoicing data for the selected grouping.'],
            ['admin.bucket3.per_x_invoicing.empty', 'hy', 'Ընտրված խմբավորման համար տվյալներ չկան։'],
            ['admin.bucket3.per_x_invoicing.empty', 'ru', 'Данных по выбранной группировке нет.'],

            ['admin.bucket3.per_x_invoicing.group.currency', 'en', 'By currency'],
            ['admin.bucket3.per_x_invoicing.group.currency', 'hy', 'Ըստ արժույթի'],
            ['admin.bucket3.per_x_invoicing.group.currency', 'ru', 'По валюте'],

            ['admin.bucket3.per_x_invoicing.group.month', 'en', 'By month'],
            ['admin.bucket3.per_x_invoicing.group.month', 'hy', 'Ըստ ամսվա'],
            ['admin.bucket3.per_x_invoicing.group.month', 'ru', 'По месяцу'],

            ['admin.bucket3.per_x_invoicing.group.operator', 'en', 'By operator'],
            ['admin.bucket3.per_x_invoicing.group.operator', 'hy', 'Ըստ օպերատորի'],
            ['admin.bucket3.per_x_invoicing.group.operator', 'ru', 'По оператору'],

            ['admin.bucket3.per_x_invoicing.group.status', 'en', 'By status'],
            ['admin.bucket3.per_x_invoicing.group.status', 'hy', 'Ըստ կարգավիճակի'],
            ['admin.bucket3.per_x_invoicing.group.status', 'ru', 'По статусу'],

            ['admin.bucket3.per_x_invoicing.group_by', 'en', 'Group by'],
            ['admin.bucket3.per_x_invoicing.group_by', 'hy', 'Խմբավորել ըստ'],
            ['admin.bucket3.per_x_invoicing.group_by', 'ru', 'Группировать по'],

            ['admin.bucket3.per_x_invoicing.refresh', 'en', 'Refresh'],
            ['admin.bucket3.per_x_invoicing.refresh', 'hy', 'Թարմացնել'],
            ['admin.bucket3.per_x_invoicing.refresh', 'ru', 'Обновить'],

            ['admin.bucket3.per_x_invoicing.subtitle', 'en', 'Aggregate invoicing breakdown by operator, currency, month or status'],
            ['admin.bucket3.per_x_invoicing.subtitle', 'hy', 'Հաշիվ-ապրանքագրերի ընդհանուր բաշխումը՝ ըստ օպերատորի, արժույթի, ամսվա կամ կարգավիճակի'],
            ['admin.bucket3.per_x_invoicing.subtitle', 'ru', 'Сводная разбивка по счетам по оператору, валюте, месяцу или статусу'],

            ['admin.bucket3.per_x_invoicing.title', 'en', 'Per-X invoicing'],
            ['admin.bucket3.per_x_invoicing.title', 'hy', 'Per-X հաշվարկ'],
            ['admin.bucket3.per_x_invoicing.title', 'ru', 'Per-X выставление счетов'],

            ['admin.bucket3.per_x_invoicing.totals', 'en', 'Totals'],
            ['admin.bucket3.per_x_invoicing.totals', 'hy', 'Ընդհանուրներ'],
            ['admin.bucket3.per_x_invoicing.totals', 'ru', 'Итого'],

            ['admin.bucket3.per_x_invoicing.totals_count', 'en', '{count} groups'],
            ['admin.bucket3.per_x_invoicing.totals_count', 'hy', '{count} խումբ'],
            ['admin.bucket3.per_x_invoicing.totals_count', 'ru', '{count} групп'],

            // ────────────────────────────────────────────────────────────
            // PIN SETTINGS
            // ────────────────────────────────────────────────────────────
            ['admin.bucket3.pin_settings.change_pin', 'en', 'Change PIN'],
            ['admin.bucket3.pin_settings.change_pin', 'hy', 'Փոխել PIN-ը'],
            ['admin.bucket3.pin_settings.change_pin', 'ru', 'Изменить PIN'],

            ['admin.bucket3.pin_settings.clear_pin', 'en', 'Clear PIN'],
            ['admin.bucket3.pin_settings.clear_pin', 'hy', 'Մաքրել PIN-ը'],
            ['admin.bucket3.pin_settings.clear_pin', 'ru', 'Удалить PIN'],

            ['admin.bucket3.pin_settings.clear_pin_helper', 'en', 'Removes your PIN. You will need to set a new one to use PIN-protected actions.'],
            ['admin.bucket3.pin_settings.clear_pin_helper', 'hy', 'Հեռացնում է ձեր PIN-ը։ PIN-ով պաշտպանված գործողությունների համար պետք է նորը սահմանեք։'],
            ['admin.bucket3.pin_settings.clear_pin_helper', 'ru', 'Удаляет ваш PIN. Для PIN-защищённых действий потребуется задать новый.'],

            ['admin.bucket3.pin_settings.error.clear_password_required', 'en', 'Account password is required to clear PIN'],
            ['admin.bucket3.pin_settings.error.clear_password_required', 'hy', 'PIN-ը մաքրելու համար անհրաժեշտ է հաշվի գաղտնաբառը'],
            ['admin.bucket3.pin_settings.error.clear_password_required', 'ru', 'Для удаления PIN требуется пароль аккаунта'],

            ['admin.bucket3.pin_settings.error.current_pin_required', 'en', 'Current PIN is required'],
            ['admin.bucket3.pin_settings.error.current_pin_required', 'hy', 'Ընթացիկ PIN-ը պարտադիր է'],
            ['admin.bucket3.pin_settings.error.current_pin_required', 'ru', 'Требуется текущий PIN'],

            ['admin.bucket3.pin_settings.error.mismatch', 'en', "PINs don't match"],
            ['admin.bucket3.pin_settings.error.mismatch', 'hy', 'PIN-երը չեն համընկնում'],
            ['admin.bucket3.pin_settings.error.mismatch', 'ru', 'PIN-коды не совпадают'],

            ['admin.bucket3.pin_settings.error.password_required', 'en', 'Account password is required'],
            ['admin.bucket3.pin_settings.error.password_required', 'hy', 'Հաշվի գաղտնաբառը պարտադիր է'],
            ['admin.bucket3.pin_settings.error.password_required', 'ru', 'Требуется пароль аккаунта'],

            ['admin.bucket3.pin_settings.error.pin_format', 'en', 'PIN must be 4-8 digits'],
            ['admin.bucket3.pin_settings.error.pin_format', 'hy', 'PIN-ը պետք է լինի 4-8 թվանշան'],
            ['admin.bucket3.pin_settings.error.pin_format', 'ru', 'PIN должен содержать 4–8 цифр'],

            ['admin.bucket3.pin_settings.field.account_password', 'en', 'Account password'],
            ['admin.bucket3.pin_settings.field.account_password', 'hy', 'Հաշվի գաղտնաբառ'],
            ['admin.bucket3.pin_settings.field.account_password', 'ru', 'Пароль аккаунта'],

            ['admin.bucket3.pin_settings.field.account_password_helper', 'en', 'Required to confirm sensitive changes'],
            ['admin.bucket3.pin_settings.field.account_password_helper', 'hy', 'Անհրաժեշտ է զգայուն փոփոխությունները հաստատելու համար'],
            ['admin.bucket3.pin_settings.field.account_password_helper', 'ru', 'Требуется для подтверждения важных изменений'],

            ['admin.bucket3.pin_settings.field.confirm_pin', 'en', 'Confirm new PIN'],
            ['admin.bucket3.pin_settings.field.confirm_pin', 'hy', 'Հաստատել նոր PIN-ը'],
            ['admin.bucket3.pin_settings.field.confirm_pin', 'ru', 'Подтвердите новый PIN'],

            ['admin.bucket3.pin_settings.field.current_pin', 'en', 'Current PIN'],
            ['admin.bucket3.pin_settings.field.current_pin', 'hy', 'Ընթացիկ PIN'],
            ['admin.bucket3.pin_settings.field.current_pin', 'ru', 'Текущий PIN'],

            ['admin.bucket3.pin_settings.field.new_pin', 'en', 'New PIN'],
            ['admin.bucket3.pin_settings.field.new_pin', 'hy', 'Նոր PIN'],
            ['admin.bucket3.pin_settings.field.new_pin', 'ru', 'Новый PIN'],

            ['admin.bucket3.pin_settings.field.pin', 'en', 'PIN'],
            ['admin.bucket3.pin_settings.field.pin', 'hy', 'PIN'],
            ['admin.bucket3.pin_settings.field.pin', 'ru', 'PIN'],

            ['admin.bucket3.pin_settings.last_set', 'en', 'Last set {date}'],
            ['admin.bucket3.pin_settings.last_set', 'hy', 'Վերջին անգամ սահմանվել է {date}'],
            ['admin.bucket3.pin_settings.last_set', 'ru', 'Последний раз задан {date}'],

            ['admin.bucket3.pin_settings.no_pin_set', 'en', 'No PIN is currently set.'],
            ['admin.bucket3.pin_settings.no_pin_set', 'hy', 'PIN ներկայումս սահմանված չէ։'],
            ['admin.bucket3.pin_settings.no_pin_set', 'ru', 'PIN сейчас не задан.'],

            ['admin.bucket3.pin_settings.pin_incorrect', 'en', 'PIN is incorrect'],
            ['admin.bucket3.pin_settings.pin_incorrect', 'hy', 'PIN-ը սխալ է'],
            ['admin.bucket3.pin_settings.pin_incorrect', 'ru', 'Неверный PIN'],

            ['admin.bucket3.pin_settings.pin_is_set', 'en', 'PIN is set'],
            ['admin.bucket3.pin_settings.pin_is_set', 'hy', 'PIN-ը սահմանված է'],
            ['admin.bucket3.pin_settings.pin_is_set', 'ru', 'PIN задан'],

            ['admin.bucket3.pin_settings.pin_matches', 'en', 'PIN matches'],
            ['admin.bucket3.pin_settings.pin_matches', 'hy', 'PIN-ը համընկնում է'],
            ['admin.bucket3.pin_settings.pin_matches', 'ru', 'PIN совпадает'],

            ['admin.bucket3.pin_settings.saved_at', 'en', 'Saved at {time}'],
            ['admin.bucket3.pin_settings.saved_at', 'hy', 'Պահպանվել է {time}'],
            ['admin.bucket3.pin_settings.saved_at', 'ru', 'Сохранено {time}'],

            ['admin.bucket3.pin_settings.set_pin', 'en', 'Set PIN'],
            ['admin.bucket3.pin_settings.set_pin', 'hy', 'Սահմանել PIN'],
            ['admin.bucket3.pin_settings.set_pin', 'ru', 'Задать PIN'],

            ['admin.bucket3.pin_settings.status', 'en', 'Status'],
            ['admin.bucket3.pin_settings.status', 'hy', 'Կարգավիճակ'],
            ['admin.bucket3.pin_settings.status', 'ru', 'Статус'],

            ['admin.bucket3.pin_settings.subtitle', 'en', 'Manage your operations PIN for sensitive actions'],
            ['admin.bucket3.pin_settings.subtitle', 'hy', 'Կառավարեք ձեր գործառնական PIN-ը զգայուն գործողությունների համար'],
            ['admin.bucket3.pin_settings.subtitle', 'ru', 'Управление операционным PIN для важных действий'],

            ['admin.bucket3.pin_settings.test_pin', 'en', 'Test PIN'],
            ['admin.bucket3.pin_settings.test_pin', 'hy', 'Փորձարկել PIN-ը'],
            ['admin.bucket3.pin_settings.test_pin', 'ru', 'Проверить PIN'],

            ['admin.bucket3.pin_settings.test_pin_helper', 'en', 'Verify your PIN without performing any action'],
            ['admin.bucket3.pin_settings.test_pin_helper', 'hy', 'Ստուգեք ձեր PIN-ը՝ առանց որևէ գործողություն կատարելու'],
            ['admin.bucket3.pin_settings.test_pin_helper', 'ru', 'Проверьте PIN, не выполняя действие'],

            ['admin.bucket3.pin_settings.title', 'en', 'PIN settings'],
            ['admin.bucket3.pin_settings.title', 'hy', 'PIN կարգավորումներ'],
            ['admin.bucket3.pin_settings.title', 'ru', 'Настройки PIN'],

            ['admin.bucket3.pin_settings.verify', 'en', 'Verify'],
            ['admin.bucket3.pin_settings.verify', 'hy', 'Ստուգել'],
            ['admin.bucket3.pin_settings.verify', 'ru', 'Проверить'],

            // ────────────────────────────────────────────────────────────
            // REQUESTS
            // ────────────────────────────────────────────────────────────
            ['admin.bucket3.requests.close', 'en', 'Close'],
            ['admin.bucket3.requests.close', 'hy', 'Փակել'],
            ['admin.bucket3.requests.close', 'ru', 'Закрыть'],

            ['admin.bucket3.requests.col.created', 'en', 'Created'],
            ['admin.bucket3.requests.col.created', 'hy', 'Ստեղծվել է'],
            ['admin.bucket3.requests.col.created', 'ru', 'Создан'],

            ['admin.bucket3.requests.col.from', 'en', 'From'],
            ['admin.bucket3.requests.col.from', 'hy', 'Ումից'],
            ['admin.bucket3.requests.col.from', 'ru', 'От'],

            ['admin.bucket3.requests.col.status', 'en', 'Status'],
            ['admin.bucket3.requests.col.status', 'hy', 'Կարգավիճակ'],
            ['admin.bucket3.requests.col.status', 'ru', 'Статус'],

            ['admin.bucket3.requests.col.subject', 'en', 'Subject'],
            ['admin.bucket3.requests.col.subject', 'hy', 'Թեմա'],
            ['admin.bucket3.requests.col.subject', 'ru', 'Тема'],

            ['admin.bucket3.requests.col.to', 'en', 'To'],
            ['admin.bucket3.requests.col.to', 'hy', 'Ում'],
            ['admin.bucket3.requests.col.to', 'ru', 'Кому'],

            ['admin.bucket3.requests.created_at', 'en', 'Created {date}'],
            ['admin.bucket3.requests.created_at', 'hy', 'Ստեղծվել է {date}'],
            ['admin.bucket3.requests.created_at', 'ru', 'Создан {date}'],

            ['admin.bucket3.requests.empty', 'en', 'No requests yet.'],
            ['admin.bucket3.requests.empty', 'hy', 'Հարցումներ դեռ չկան։'],
            ['admin.bucket3.requests.empty', 'ru', 'Запросов пока нет.'],

            ['admin.bucket3.requests.field.body', 'en', 'Message'],
            ['admin.bucket3.requests.field.body', 'hy', 'Հաղորդագրություն'],
            ['admin.bucket3.requests.field.body', 'ru', 'Сообщение'],

            ['admin.bucket3.requests.field.resolution_notes', 'en', 'Resolution notes'],
            ['admin.bucket3.requests.field.resolution_notes', 'hy', 'Լուծման նշումներ'],
            ['admin.bucket3.requests.field.resolution_notes', 'ru', 'Заметки о решении'],

            ['admin.bucket3.requests.field.resolution_placeholder', 'en', 'Describe how the request was resolved…'],
            ['admin.bucket3.requests.field.resolution_placeholder', 'hy', 'Նկարագրեք, թե ինչպես է հարցումը լուծվել…'],
            ['admin.bucket3.requests.field.resolution_placeholder', 'ru', 'Опишите, как запрос был решён…'],

            ['admin.bucket3.requests.field.subject', 'en', 'Subject'],
            ['admin.bucket3.requests.field.subject', 'hy', 'Թեմա'],
            ['admin.bucket3.requests.field.subject', 'ru', 'Тема'],

            ['admin.bucket3.requests.field.target_company', 'en', 'Target company'],
            ['admin.bucket3.requests.field.target_company', 'hy', 'Թիրախ ընկերություն'],
            ['admin.bucket3.requests.field.target_company', 'ru', 'Целевая компания'],

            ['admin.bucket3.requests.field.target_company_placeholder', 'en', 'Company ID or slug'],
            ['admin.bucket3.requests.field.target_company_placeholder', 'hy', 'Ընկերության ID կամ slug'],
            ['admin.bucket3.requests.field.target_company_placeholder', 'ru', 'ID компании или slug'],

            ['admin.bucket3.requests.filter.status', 'en', 'Status'],
            ['admin.bucket3.requests.filter.status', 'hy', 'Կարգավիճակ'],
            ['admin.bucket3.requests.filter.status', 'ru', 'Статус'],

            ['admin.bucket3.requests.from', 'en', 'From {name}'],
            ['admin.bucket3.requests.from', 'hy', '{name}-ից'],
            ['admin.bucket3.requests.from', 'ru', 'От {name}'],

            ['admin.bucket3.requests.mark_in_progress', 'en', 'Mark in progress'],
            ['admin.bucket3.requests.mark_in_progress', 'hy', 'Նշել որպես ընթացքի մեջ'],
            ['admin.bucket3.requests.mark_in_progress', 'ru', 'Отметить в работе'],

            ['admin.bucket3.requests.new_request', 'en', 'New request'],
            ['admin.bucket3.requests.new_request', 'hy', 'Նոր հարցում'],
            ['admin.bucket3.requests.new_request', 'ru', 'Новый запрос'],

            ['admin.bucket3.requests.reject', 'en', 'Reject'],
            ['admin.bucket3.requests.reject', 'hy', 'Մերժել'],
            ['admin.bucket3.requests.reject', 'ru', 'Отклонить'],

            ['admin.bucket3.requests.resolution_notes', 'en', 'Resolution notes'],
            ['admin.bucket3.requests.resolution_notes', 'hy', 'Լուծման նշումներ'],
            ['admin.bucket3.requests.resolution_notes', 'ru', 'Заметки о решении'],

            ['admin.bucket3.requests.resolve', 'en', 'Resolve'],
            ['admin.bucket3.requests.resolve', 'hy', 'Լուծել'],
            ['admin.bucket3.requests.resolve', 'ru', 'Решить'],

            ['admin.bucket3.requests.resolved_at', 'en', 'Resolved {date}'],
            ['admin.bucket3.requests.resolved_at', 'hy', 'Լուծվել է {date}'],
            ['admin.bucket3.requests.resolved_at', 'ru', 'Решён {date}'],

            ['admin.bucket3.requests.resolved_by', 'en', 'Resolved by {name}'],
            ['admin.bucket3.requests.resolved_by', 'hy', 'Լուծել է {name}-ը'],
            ['admin.bucket3.requests.resolved_by', 'ru', 'Решил {name}'],

            ['admin.bucket3.requests.send', 'en', 'Send'],
            ['admin.bucket3.requests.send', 'hy', 'Ուղարկել'],
            ['admin.bucket3.requests.send', 'ru', 'Отправить'],

            ['admin.bucket3.requests.sending', 'en', 'Sending…'],
            ['admin.bucket3.requests.sending', 'hy', 'Ուղարկվում է…'],
            ['admin.bucket3.requests.sending', 'ru', 'Отправляется…'],

            ['admin.bucket3.requests.subtitle', 'en', 'Send and resolve requests between companies'],
            ['admin.bucket3.requests.subtitle', 'hy', 'Ուղարկեք և լուծեք հարցումներ ընկերությունների միջև'],
            ['admin.bucket3.requests.subtitle', 'ru', 'Отправка и закрытие запросов между компаниями'],

            ['admin.bucket3.requests.subtitle_count', 'en', '{count} requests'],
            ['admin.bucket3.requests.subtitle_count', 'hy', '{count} հարցում'],
            ['admin.bucket3.requests.subtitle_count', 'ru', '{count} запросов'],

            ['admin.bucket3.requests.title', 'en', 'Requests'],
            ['admin.bucket3.requests.title', 'hy', 'Հարցումներ'],
            ['admin.bucket3.requests.title', 'ru', 'Запросы'],

            ['admin.bucket3.requests.view', 'en', 'View'],
            ['admin.bucket3.requests.view', 'hy', 'Տեսք'],
            ['admin.bucket3.requests.view', 'ru', 'Вид'],

            ['admin.bucket3.requests.view.inbox', 'en', 'Inbox'],
            ['admin.bucket3.requests.view.inbox', 'hy', 'Մուտքային'],
            ['admin.bucket3.requests.view.inbox', 'ru', 'Входящие'],

            ['admin.bucket3.requests.view.outbox', 'en', 'Outbox'],
            ['admin.bucket3.requests.view.outbox', 'hy', 'Ելքային'],
            ['admin.bucket3.requests.view.outbox', 'ru', 'Исходящие'],

            // ────────────────────────────────────────────────────────────
            // SERVICE CATALOG
            // ────────────────────────────────────────────────────────────
            ['admin.bucket3.service_catalog.add_item', 'en', 'Add item'],
            ['admin.bucket3.service_catalog.add_item', 'hy', 'Ավելացնել տարր'],
            ['admin.bucket3.service_catalog.add_item', 'ru', 'Добавить позицию'],

            ['admin.bucket3.service_catalog.cancel_edit', 'en', 'Cancel edit'],
            ['admin.bucket3.service_catalog.cancel_edit', 'hy', 'Չեղարկել խմբագրումը'],
            ['admin.bucket3.service_catalog.cancel_edit', 'ru', 'Отменить правку'],

            ['admin.bucket3.service_catalog.col.actions', 'en', 'Actions'],
            ['admin.bucket3.service_catalog.col.actions', 'hy', 'Գործողություններ'],
            ['admin.bucket3.service_catalog.col.actions', 'ru', 'Действия'],

            ['admin.bucket3.service_catalog.col.active', 'en', 'Active'],
            ['admin.bucket3.service_catalog.col.active', 'hy', 'Ակտիվ'],
            ['admin.bucket3.service_catalog.col.active', 'ru', 'Активен'],

            ['admin.bucket3.service_catalog.col.category', 'en', 'Category'],
            ['admin.bucket3.service_catalog.col.category', 'hy', 'Կատեգորիա'],
            ['admin.bucket3.service_catalog.col.category', 'ru', 'Категория'],

            ['admin.bucket3.service_catalog.col.name', 'en', 'Name'],
            ['admin.bucket3.service_catalog.col.name', 'hy', 'Անվանում'],
            ['admin.bucket3.service_catalog.col.name', 'ru', 'Название'],

            ['admin.bucket3.service_catalog.col.price', 'en', 'Price'],
            ['admin.bucket3.service_catalog.col.price', 'hy', 'Գին'],
            ['admin.bucket3.service_catalog.col.price', 'ru', 'Цена'],

            ['admin.bucket3.service_catalog.col.unit', 'en', 'Unit'],
            ['admin.bucket3.service_catalog.col.unit', 'hy', 'Միավոր'],
            ['admin.bucket3.service_catalog.col.unit', 'ru', 'Единица'],

            ['admin.bucket3.service_catalog.edit_item', 'en', 'Edit item #{id}'],
            ['admin.bucket3.service_catalog.edit_item', 'hy', 'Խմբագրել տարր №{id}'],
            ['admin.bucket3.service_catalog.edit_item', 'ru', 'Редактировать позицию №{id}'],

            ['admin.bucket3.service_catalog.empty', 'en', 'No items in the catalog yet.'],
            ['admin.bucket3.service_catalog.empty', 'hy', 'Կատալոգում տարրեր դեռ չկան։'],
            ['admin.bucket3.service_catalog.empty', 'ru', 'В каталоге пока нет позиций.'],

            ['admin.bucket3.service_catalog.error.name_required', 'en', 'Name is required'],
            ['admin.bucket3.service_catalog.error.name_required', 'hy', 'Անվանումը պարտադիր է'],
            ['admin.bucket3.service_catalog.error.name_required', 'ru', 'Название обязательно'],

            ['admin.bucket3.service_catalog.field.active', 'en', 'Active'],
            ['admin.bucket3.service_catalog.field.active', 'hy', 'Ակտիվ'],
            ['admin.bucket3.service_catalog.field.active', 'ru', 'Активно'],

            ['admin.bucket3.service_catalog.field.base_price', 'en', 'Base price'],
            ['admin.bucket3.service_catalog.field.base_price', 'hy', 'Հիմնական գին'],
            ['admin.bucket3.service_catalog.field.base_price', 'ru', 'Базовая цена'],

            ['admin.bucket3.service_catalog.field.category', 'en', 'Category'],
            ['admin.bucket3.service_catalog.field.category', 'hy', 'Կատեգորիա'],
            ['admin.bucket3.service_catalog.field.category', 'ru', 'Категория'],

            ['admin.bucket3.service_catalog.field.category_placeholder', 'en', 'e.g. tour, transfer, addon'],
            ['admin.bucket3.service_catalog.field.category_placeholder', 'hy', 'օր.՝ տուր, տրանսֆեր, հավելում'],
            ['admin.bucket3.service_catalog.field.category_placeholder', 'ru', 'напр.: тур, трансфер, доп'],

            ['admin.bucket3.service_catalog.field.currency', 'en', 'Currency'],
            ['admin.bucket3.service_catalog.field.currency', 'hy', 'Արժույթ'],
            ['admin.bucket3.service_catalog.field.currency', 'ru', 'Валюта'],

            ['admin.bucket3.service_catalog.field.description', 'en', 'Description'],
            ['admin.bucket3.service_catalog.field.description', 'hy', 'Նկարագրություն'],
            ['admin.bucket3.service_catalog.field.description', 'ru', 'Описание'],

            ['admin.bucket3.service_catalog.field.name', 'en', 'Name'],
            ['admin.bucket3.service_catalog.field.name', 'hy', 'Անվանում'],
            ['admin.bucket3.service_catalog.field.name', 'ru', 'Название'],

            ['admin.bucket3.service_catalog.field.name_placeholder', 'en', 'e.g. Yerevan city tour'],
            ['admin.bucket3.service_catalog.field.name_placeholder', 'hy', 'օր.՝ Երևանի քաղաքային տուր'],
            ['admin.bucket3.service_catalog.field.name_placeholder', 'ru', 'напр.: Городской тур по Еревану'],

            ['admin.bucket3.service_catalog.field.unit', 'en', 'Unit'],
            ['admin.bucket3.service_catalog.field.unit', 'hy', 'Միավոր'],
            ['admin.bucket3.service_catalog.field.unit', 'ru', 'Единица'],

            ['admin.bucket3.service_catalog.pick', 'en', 'Pick…'],
            ['admin.bucket3.service_catalog.pick', 'hy', 'Ընտրել…'],
            ['admin.bucket3.service_catalog.pick', 'ru', 'Выбрать…'],

            ['admin.bucket3.service_catalog.save_changes', 'en', 'Save changes'],
            ['admin.bucket3.service_catalog.save_changes', 'hy', 'Պահպանել փոփոխությունները'],
            ['admin.bucket3.service_catalog.save_changes', 'ru', 'Сохранить изменения'],

            ['admin.bucket3.service_catalog.search_placeholder', 'en', 'Search by name or category…'],
            ['admin.bucket3.service_catalog.search_placeholder', 'hy', 'Որոնել ըստ անվան կամ կատեգորիայի…'],
            ['admin.bucket3.service_catalog.search_placeholder', 'ru', 'Поиск по названию или категории…'],

            ['admin.bucket3.service_catalog.status.active', 'en', 'Active'],
            ['admin.bucket3.service_catalog.status.active', 'hy', 'Ակտիվ'],
            ['admin.bucket3.service_catalog.status.active', 'ru', 'Активно'],

            ['admin.bucket3.service_catalog.status.inactive', 'en', 'Inactive'],
            ['admin.bucket3.service_catalog.status.inactive', 'hy', 'Ոչ ակտիվ'],
            ['admin.bucket3.service_catalog.status.inactive', 'ru', 'Неактивно'],

            ['admin.bucket3.service_catalog.subtitle', 'en', 'Manage the catalog of services and add-ons sold across the platform'],
            ['admin.bucket3.service_catalog.subtitle', 'hy', 'Կառավարեք պլատֆորմում վաճառվող ծառայությունների և հավելումների կատալոգը'],
            ['admin.bucket3.service_catalog.subtitle', 'ru', 'Управление каталогом услуг и доп. опций, продаваемых на платформе'],

            ['admin.bucket3.service_catalog.subtitle_count', 'en', '{count} items in catalog'],
            ['admin.bucket3.service_catalog.subtitle_count', 'hy', '{count} տարր կատալոգում'],
            ['admin.bucket3.service_catalog.subtitle_count', 'ru', '{count} позиций в каталоге'],

            ['admin.bucket3.service_catalog.title', 'en', 'Service catalog'],
            ['admin.bucket3.service_catalog.title', 'hy', 'Ծառայությունների կատալոգ'],
            ['admin.bucket3.service_catalog.title', 'ru', 'Каталог услуг'],

            // ────────────────────────────────────────────────────────────
            // SERVICE LOGS
            // ────────────────────────────────────────────────────────────
            ['admin.bucket3.service_logs.all_categories', 'en', 'All categories'],
            ['admin.bucket3.service_logs.all_categories', 'hy', 'Բոլոր կատեգորիաները'],
            ['admin.bucket3.service_logs.all_categories', 'ru', 'Все категории'],

            ['admin.bucket3.service_logs.col.action', 'en', 'Action'],
            ['admin.bucket3.service_logs.col.action', 'hy', 'Գործողություն'],
            ['admin.bucket3.service_logs.col.action', 'ru', 'Действие'],

            ['admin.bucket3.service_logs.col.actor', 'en', 'Actor'],
            ['admin.bucket3.service_logs.col.actor', 'hy', 'Կատարող'],
            ['admin.bucket3.service_logs.col.actor', 'ru', 'Исполнитель'],

            ['admin.bucket3.service_logs.col.category', 'en', 'Category'],
            ['admin.bucket3.service_logs.col.category', 'hy', 'Կատեգորիա'],
            ['admin.bucket3.service_logs.col.category', 'ru', 'Категория'],

            ['admin.bucket3.service_logs.col.subject', 'en', 'Subject'],
            ['admin.bucket3.service_logs.col.subject', 'hy', 'Առարկա'],
            ['admin.bucket3.service_logs.col.subject', 'ru', 'Объект'],

            ['admin.bucket3.service_logs.col.when', 'en', 'When'],
            ['admin.bucket3.service_logs.col.when', 'hy', 'Երբ'],
            ['admin.bucket3.service_logs.col.when', 'ru', 'Когда'],

            ['admin.bucket3.service_logs.empty', 'en', 'No log entries match the current filter.'],
            ['admin.bucket3.service_logs.empty', 'hy', 'Ընթացիկ զտիչին համապատասխանող գրառումներ չկան։'],
            ['admin.bucket3.service_logs.empty', 'ru', 'Под текущий фильтр записей нет.'],

            ['admin.bucket3.service_logs.filter.category', 'en', 'Category'],
            ['admin.bucket3.service_logs.filter.category', 'hy', 'Կատեգորիա'],
            ['admin.bucket3.service_logs.filter.category', 'ru', 'Категория'],

            ['admin.bucket3.service_logs.full_audit_log', 'en', 'Full audit log'],
            ['admin.bucket3.service_logs.full_audit_log', 'hy', 'Աուդիտի ամբողջական մատյան'],
            ['admin.bucket3.service_logs.full_audit_log', 'ru', 'Полный аудит-лог'],

            ['admin.bucket3.service_logs.search_placeholder', 'en', 'Search log entries…'],
            ['admin.bucket3.service_logs.search_placeholder', 'hy', 'Որոնել մատյանի գրառումներում…'],
            ['admin.bucket3.service_logs.search_placeholder', 'ru', 'Поиск по записям лога…'],

            ['admin.bucket3.service_logs.subtitle', 'en', 'Browse operational audit log entries'],
            ['admin.bucket3.service_logs.subtitle', 'hy', 'Դիտեք գործառնական աուդիտի մատյանի գրառումները'],
            ['admin.bucket3.service_logs.subtitle', 'ru', 'Просмотр записей операционного аудит-лога'],

            ['admin.bucket3.service_logs.subtitle_count', 'en', '{count} log entries'],
            ['admin.bucket3.service_logs.subtitle_count', 'hy', '{count} գրառում մատյանում'],
            ['admin.bucket3.service_logs.subtitle_count', 'ru', '{count} записей в логе'],

            ['admin.bucket3.service_logs.title', 'en', 'Service logs'],
            ['admin.bucket3.service_logs.title', 'hy', 'Ծառայության մատյաններ'],
            ['admin.bucket3.service_logs.title', 'ru', 'Журналы сервиса'],

            // ────────────────────────────────────────────────────────────
            // SUBSCRIPTIONS
            // ────────────────────────────────────────────────────────────
            ['admin.bucket3.subscriptions.active_subscriptions', 'en', 'Active subscriptions'],
            ['admin.bucket3.subscriptions.active_subscriptions', 'hy', 'Ակտիվ բաժանորդագրություններ'],
            ['admin.bucket3.subscriptions.active_subscriptions', 'ru', 'Активные подписки'],

            ['admin.bucket3.subscriptions.add_plan', 'en', 'Add plan'],
            ['admin.bucket3.subscriptions.add_plan', 'hy', 'Ավելացնել պլան'],
            ['admin.bucket3.subscriptions.add_plan', 'ru', 'Добавить тариф'],

            ['admin.bucket3.subscriptions.assign', 'en', 'Assign'],
            ['admin.bucket3.subscriptions.assign', 'hy', 'Նշանակել'],
            ['admin.bucket3.subscriptions.assign', 'ru', 'Назначить'],

            ['admin.bucket3.subscriptions.assign_plan', 'en', 'Assign plan to company'],
            ['admin.bucket3.subscriptions.assign_plan', 'hy', 'Նշանակել պլան ընկերությանը'],
            ['admin.bucket3.subscriptions.assign_plan', 'ru', 'Назначить тариф компании'],

            ['admin.bucket3.subscriptions.col.active', 'en', 'Active'],
            ['admin.bucket3.subscriptions.col.active', 'hy', 'Ակտիվ'],
            ['admin.bucket3.subscriptions.col.active', 'ru', 'Активен'],

            ['admin.bucket3.subscriptions.col.annual', 'en', 'Annual'],
            ['admin.bucket3.subscriptions.col.annual', 'hy', 'Տարեկան'],
            ['admin.bucket3.subscriptions.col.annual', 'ru', 'Год'],

            ['admin.bucket3.subscriptions.col.code', 'en', 'Code'],
            ['admin.bucket3.subscriptions.col.code', 'hy', 'Կոդ'],
            ['admin.bucket3.subscriptions.col.code', 'ru', 'Код'],

            ['admin.bucket3.subscriptions.col.features', 'en', 'Features'],
            ['admin.bucket3.subscriptions.col.features', 'hy', 'Հնարավորություններ'],
            ['admin.bucket3.subscriptions.col.features', 'ru', 'Возможности'],

            ['admin.bucket3.subscriptions.col.monthly', 'en', 'Monthly'],
            ['admin.bucket3.subscriptions.col.monthly', 'hy', 'Ամսական'],
            ['admin.bucket3.subscriptions.col.monthly', 'ru', 'Месяц'],

            ['admin.bucket3.subscriptions.col.name', 'en', 'Name'],
            ['admin.bucket3.subscriptions.col.name', 'hy', 'Անվանում'],
            ['admin.bucket3.subscriptions.col.name', 'ru', 'Название'],

            ['admin.bucket3.subscriptions.col.order', 'en', 'Order'],
            ['admin.bucket3.subscriptions.col.order', 'hy', 'Հերթ'],
            ['admin.bucket3.subscriptions.col.order', 'ru', 'Порядок'],

            ['admin.bucket3.subscriptions.empty_plans', 'en', 'No plans defined yet.'],
            ['admin.bucket3.subscriptions.empty_plans', 'hy', 'Պլաններ դեռ սահմանված չեն։'],
            ['admin.bucket3.subscriptions.empty_plans', 'ru', 'Тарифы пока не заданы.'],

            ['admin.bucket3.subscriptions.empty_subscriptions', 'en', 'No active subscriptions yet.'],
            ['admin.bucket3.subscriptions.empty_subscriptions', 'hy', 'Ակտիվ բաժանորդագրություններ դեռ չկան։'],
            ['admin.bucket3.subscriptions.empty_subscriptions', 'ru', 'Активных подписок пока нет.'],

            ['admin.bucket3.subscriptions.error.code_name_required', 'en', 'Code and name are required'],
            ['admin.bucket3.subscriptions.error.code_name_required', 'hy', 'Կոդը և անվանումը պարտադիր են'],
            ['admin.bucket3.subscriptions.error.code_name_required', 'ru', 'Код и название обязательны'],

            ['admin.bucket3.subscriptions.error.company_plan_required', 'en', 'Company and plan are required'],
            ['admin.bucket3.subscriptions.error.company_plan_required', 'hy', 'Ընկերությունը և պլանը պարտադիր են'],
            ['admin.bucket3.subscriptions.error.company_plan_required', 'ru', 'Компания и тариф обязательны'],

            ['admin.bucket3.subscriptions.features_helper', 'en', 'Each feature on its own line'],
            ['admin.bucket3.subscriptions.features_helper', 'hy', 'Յուրաքանչյուր հնարավորություն առանձին տողում'],
            ['admin.bucket3.subscriptions.features_helper', 'ru', 'Каждая возможность с новой строки'],

            ['admin.bucket3.subscriptions.field.active', 'en', 'Active'],
            ['admin.bucket3.subscriptions.field.active', 'hy', 'Ակտիվ'],
            ['admin.bucket3.subscriptions.field.active', 'ru', 'Активно'],

            ['admin.bucket3.subscriptions.field.annual_price', 'en', 'Annual price'],
            ['admin.bucket3.subscriptions.field.annual_price', 'hy', 'Տարեկան գին'],
            ['admin.bucket3.subscriptions.field.annual_price', 'ru', 'Годовая цена'],

            ['admin.bucket3.subscriptions.field.billing_period', 'en', 'Billing period'],
            ['admin.bucket3.subscriptions.field.billing_period', 'hy', 'Վճարման ժամանակահատված'],
            ['admin.bucket3.subscriptions.field.billing_period', 'ru', 'Период оплаты'],

            ['admin.bucket3.subscriptions.field.code', 'en', 'Code'],
            ['admin.bucket3.subscriptions.field.code', 'hy', 'Կոդ'],
            ['admin.bucket3.subscriptions.field.code', 'ru', 'Код'],

            ['admin.bucket3.subscriptions.field.code_helper', 'en', 'Short identifier used in the API'],
            ['admin.bucket3.subscriptions.field.code_helper', 'hy', 'Կարճ նույնացուցիչ՝ API-ի համար'],
            ['admin.bucket3.subscriptions.field.code_helper', 'ru', 'Короткий идентификатор для API'],

            ['admin.bucket3.subscriptions.field.company_id', 'en', 'Company'],
            ['admin.bucket3.subscriptions.field.company_id', 'hy', 'Ընկերություն'],
            ['admin.bucket3.subscriptions.field.company_id', 'ru', 'Компания'],

            ['admin.bucket3.subscriptions.field.currency', 'en', 'Currency'],
            ['admin.bucket3.subscriptions.field.currency', 'hy', 'Արժույթ'],
            ['admin.bucket3.subscriptions.field.currency', 'ru', 'Валюта'],

            ['admin.bucket3.subscriptions.field.description', 'en', 'Description'],
            ['admin.bucket3.subscriptions.field.description', 'hy', 'Նկարագրություն'],
            ['admin.bucket3.subscriptions.field.description', 'ru', 'Описание'],

            ['admin.bucket3.subscriptions.field.display_order', 'en', 'Display order'],
            ['admin.bucket3.subscriptions.field.display_order', 'hy', 'Ցուցադրման հերթականություն'],
            ['admin.bucket3.subscriptions.field.display_order', 'ru', 'Порядок отображения'],

            ['admin.bucket3.subscriptions.field.features', 'en', 'Features'],
            ['admin.bucket3.subscriptions.field.features', 'hy', 'Հնարավորություններ'],
            ['admin.bucket3.subscriptions.field.features', 'ru', 'Возможности'],

            ['admin.bucket3.subscriptions.field.monthly_price', 'en', 'Monthly price'],
            ['admin.bucket3.subscriptions.field.monthly_price', 'hy', 'Ամսական գին'],
            ['admin.bucket3.subscriptions.field.monthly_price', 'ru', 'Месячная цена'],

            ['admin.bucket3.subscriptions.field.name', 'en', 'Name'],
            ['admin.bucket3.subscriptions.field.name', 'hy', 'Անվանում'],
            ['admin.bucket3.subscriptions.field.name', 'ru', 'Название'],

            ['admin.bucket3.subscriptions.field.notes', 'en', 'Notes'],
            ['admin.bucket3.subscriptions.field.notes', 'hy', 'Նշումներ'],
            ['admin.bucket3.subscriptions.field.notes', 'ru', 'Примечания'],

            ['admin.bucket3.subscriptions.field.period_ends', 'en', 'Period ends'],
            ['admin.bucket3.subscriptions.field.period_ends', 'hy', 'Ժամանակահատվածի ավարտ'],
            ['admin.bucket3.subscriptions.field.period_ends', 'ru', 'Окончание периода'],

            ['admin.bucket3.subscriptions.field.period_starts', 'en', 'Period starts'],
            ['admin.bucket3.subscriptions.field.period_starts', 'hy', 'Ժամանակահատվածի սկիզբ'],
            ['admin.bucket3.subscriptions.field.period_starts', 'ru', 'Начало периода'],

            ['admin.bucket3.subscriptions.field.plan', 'en', 'Plan'],
            ['admin.bucket3.subscriptions.field.plan', 'hy', 'Պլան'],
            ['admin.bucket3.subscriptions.field.plan', 'ru', 'Тариф'],

            ['admin.bucket3.subscriptions.field.status', 'en', 'Status'],
            ['admin.bucket3.subscriptions.field.status', 'hy', 'Կարգավիճակ'],
            ['admin.bucket3.subscriptions.field.status', 'ru', 'Статус'],

            ['admin.bucket3.subscriptions.pick', 'en', 'Pick…'],
            ['admin.bucket3.subscriptions.pick', 'hy', 'Ընտրել…'],
            ['admin.bucket3.subscriptions.pick', 'ru', 'Выбрать…'],

            ['admin.bucket3.subscriptions.plan_catalog', 'en', 'Plan catalog'],
            ['admin.bucket3.subscriptions.plan_catalog', 'hy', 'Պլանների կատալոգ'],
            ['admin.bucket3.subscriptions.plan_catalog', 'ru', 'Каталог тарифов'],

            ['admin.bucket3.subscriptions.status.active', 'en', 'Active'],
            ['admin.bucket3.subscriptions.status.active', 'hy', 'Ակտիվ'],
            ['admin.bucket3.subscriptions.status.active', 'ru', 'Активно'],

            ['admin.bucket3.subscriptions.status.inactive', 'en', 'Inactive'],
            ['admin.bucket3.subscriptions.status.inactive', 'hy', 'Ոչ ակտիվ'],
            ['admin.bucket3.subscriptions.status.inactive', 'ru', 'Неактивно'],

            ['admin.bucket3.subscriptions.sub_col.billing', 'en', 'Billing'],
            ['admin.bucket3.subscriptions.sub_col.billing', 'hy', 'Վճարում'],
            ['admin.bucket3.subscriptions.sub_col.billing', 'ru', 'Оплата'],

            ['admin.bucket3.subscriptions.sub_col.company', 'en', 'Company'],
            ['admin.bucket3.subscriptions.sub_col.company', 'hy', 'Ընկերություն'],
            ['admin.bucket3.subscriptions.sub_col.company', 'ru', 'Компания'],

            ['admin.bucket3.subscriptions.sub_col.notes', 'en', 'Notes'],
            ['admin.bucket3.subscriptions.sub_col.notes', 'hy', 'Նշումներ'],
            ['admin.bucket3.subscriptions.sub_col.notes', 'ru', 'Примечания'],

            ['admin.bucket3.subscriptions.sub_col.period', 'en', 'Period'],
            ['admin.bucket3.subscriptions.sub_col.period', 'hy', 'Ժամանակահատված'],
            ['admin.bucket3.subscriptions.sub_col.period', 'ru', 'Период'],

            ['admin.bucket3.subscriptions.sub_col.plan', 'en', 'Plan'],
            ['admin.bucket3.subscriptions.sub_col.plan', 'hy', 'Պլան'],
            ['admin.bucket3.subscriptions.sub_col.plan', 'ru', 'Тариф'],

            ['admin.bucket3.subscriptions.sub_col.status', 'en', 'Status'],
            ['admin.bucket3.subscriptions.sub_col.status', 'hy', 'Կարգավիճակ'],
            ['admin.bucket3.subscriptions.sub_col.status', 'ru', 'Статус'],

            ['admin.bucket3.subscriptions.subtitle', 'en', 'Manage subscription plans and assign them to companies'],
            ['admin.bucket3.subscriptions.subtitle', 'hy', 'Կառավարեք բաժանորդագրման պլանները և նշանակեք ընկերություններին'],
            ['admin.bucket3.subscriptions.subtitle', 'ru', 'Управляйте тарифами подписки и назначайте их компаниям'],

            ['admin.bucket3.subscriptions.title', 'en', 'Subscriptions'],
            ['admin.bucket3.subscriptions.title', 'hy', 'Բաժանորդագրություններ'],
            ['admin.bucket3.subscriptions.title', 'ru', 'Подписки'],

            // ────────────────────────────────────────────────────────────
            // UNVERIFIED ACCOUNTS
            // ────────────────────────────────────────────────────────────
            ['admin.bucket3.unverified_accounts.b2c_no_company', 'en', 'B2C (no company)'],
            ['admin.bucket3.unverified_accounts.b2c_no_company', 'hy', 'B2C (առանց ընկերության)'],
            ['admin.bucket3.unverified_accounts.b2c_no_company', 'ru', 'B2C (без компании)'],

            ['admin.bucket3.unverified_accounts.col.companies', 'en', 'Companies'],
            ['admin.bucket3.unverified_accounts.col.companies', 'hy', 'Ընկերություններ'],
            ['admin.bucket3.unverified_accounts.col.companies', 'ru', 'Компании'],

            ['admin.bucket3.unverified_accounts.col.email', 'en', 'Email'],
            ['admin.bucket3.unverified_accounts.col.email', 'hy', 'Էլ. փոստ'],
            ['admin.bucket3.unverified_accounts.col.email', 'ru', 'Эл. почта'],

            ['admin.bucket3.unverified_accounts.col.email_verified', 'en', 'Email verified'],
            ['admin.bucket3.unverified_accounts.col.email_verified', 'hy', 'Էլ. փոստը հաստատված է'],
            ['admin.bucket3.unverified_accounts.col.email_verified', 'ru', 'Эл. почта подтверждена'],

            ['admin.bucket3.unverified_accounts.col.intended_role', 'en', 'Intended role'],
            ['admin.bucket3.unverified_accounts.col.intended_role', 'hy', 'Նախատեսված դեր'],
            ['admin.bucket3.unverified_accounts.col.intended_role', 'ru', 'Запрашиваемая роль'],

            ['admin.bucket3.unverified_accounts.col.name', 'en', 'Name'],
            ['admin.bucket3.unverified_accounts.col.name', 'hy', 'Անուն'],
            ['admin.bucket3.unverified_accounts.col.name', 'ru', 'Имя'],

            ['admin.bucket3.unverified_accounts.col.registered', 'en', 'Registered'],
            ['admin.bucket3.unverified_accounts.col.registered', 'hy', 'Գրանցված'],
            ['admin.bucket3.unverified_accounts.col.registered', 'ru', 'Зарегистрирован'],

            ['admin.bucket3.unverified_accounts.col.status', 'en', 'Status'],
            ['admin.bucket3.unverified_accounts.col.status', 'hy', 'Կարգավիճակ'],
            ['admin.bucket3.unverified_accounts.col.status', 'ru', 'Статус'],

            ['admin.bucket3.unverified_accounts.empty', 'en', 'No unverified accounts.'],
            ['admin.bucket3.unverified_accounts.empty', 'hy', 'Չհաստատված հաշիվներ չկան։'],
            ['admin.bucket3.unverified_accounts.empty', 'ru', 'Неподтверждённых аккаунтов нет.'],

            ['admin.bucket3.unverified_accounts.not_verified', 'en', 'Not verified'],
            ['admin.bucket3.unverified_accounts.not_verified', 'hy', 'Չհաստատված'],
            ['admin.bucket3.unverified_accounts.not_verified', 'ru', 'Не подтверждён'],

            ['admin.bucket3.unverified_accounts.search_placeholder', 'en', 'Search by name or email…'],
            ['admin.bucket3.unverified_accounts.search_placeholder', 'hy', 'Որոնել ըստ անվան կամ էլ. փոստի…'],
            ['admin.bucket3.unverified_accounts.search_placeholder', 'ru', 'Поиск по имени или эл. почте…'],

            ['admin.bucket3.unverified_accounts.subtitle', 'en', 'Accounts that registered but have not yet verified email or been approved'],
            ['admin.bucket3.unverified_accounts.subtitle', 'hy', 'Հաշիվներ, որոնք գրանցվել են, բայց դեռ չեն հաստատել էլ. փոստը կամ չեն հաստատվել'],
            ['admin.bucket3.unverified_accounts.subtitle', 'ru', 'Аккаунты, которые зарегистрировались, но ещё не подтвердили email или не одобрены'],

            ['admin.bucket3.unverified_accounts.subtitle_count', 'en', '{count} unverified accounts'],
            ['admin.bucket3.unverified_accounts.subtitle_count', 'hy', '{count} չհաստատված հաշիվ'],
            ['admin.bucket3.unverified_accounts.subtitle_count', 'ru', '{count} неподтверждённых аккаунтов'],

            ['admin.bucket3.unverified_accounts.title', 'en', 'Unverified accounts'],
            ['admin.bucket3.unverified_accounts.title', 'hy', 'Չհաստատված հաշիվներ'],
            ['admin.bucket3.unverified_accounts.title', 'ru', 'Неподтверждённые аккаунты'],
        ];

        $now = now();
        foreach ($rows as [$key, $lang, $value]) {
            DB::table('ui_translations')->updateOrInsert(
                ['key' => $key, 'language_code' => $lang],
                ['value' => $value, 'updated_at' => $now, 'created_at' => $now]
            );
        }
    }

    public function down(): void
    {
        $keys = [
            'common.all',
            'common.apply',
            'common.cancel',
            'common.edit',
            'common.loading',
            'common.remove',
            'common.saving',
            'admin.bucket3.block_dates.add_block',
            'admin.bucket3.block_dates.col.actions',
            'admin.bucket3.block_dates.col.company',
            'admin.bucket3.block_dates.col.from',
            'admin.bucket3.block_dates.col.item',
            'admin.bucket3.block_dates.col.reason',
            'admin.bucket3.block_dates.col.to',
            'admin.bucket3.block_dates.col.type',
            'admin.bucket3.block_dates.empty',
            'admin.bucket3.block_dates.error.dates_required',
            'admin.bucket3.block_dates.error.from_before_to',
            'admin.bucket3.block_dates.field.from',
            'admin.bucket3.block_dates.field.item_id',
            'admin.bucket3.block_dates.field.item_id_helper',
            'admin.bucket3.block_dates.field.item_type',
            'admin.bucket3.block_dates.field.reason',
            'admin.bucket3.block_dates.field.reason_placeholder',
            'admin.bucket3.block_dates.field.to',
            'admin.bucket3.block_dates.filter.item_id',
            'admin.bucket3.block_dates.filter.placeholder',
            'admin.bucket3.block_dates.subtitle',
            'admin.bucket3.block_dates.subtitle_count',
            'admin.bucket3.block_dates.title',
            'admin.bucket3.bulk_notifications.error.company_id_required',
            'admin.bucket3.bulk_notifications.error.title_message_required',
            'admin.bucket3.bulk_notifications.error.user_ids_required',
            'admin.bucket3.bulk_notifications.field.body',
            'admin.bucket3.bulk_notifications.field.body_placeholder',
            'admin.bucket3.bulk_notifications.field.company_id',
            'admin.bucket3.bulk_notifications.field.priority',
            'admin.bucket3.bulk_notifications.field.target',
            'admin.bucket3.bulk_notifications.field.title',
            'admin.bucket3.bulk_notifications.field.title_placeholder',
            'admin.bucket3.bulk_notifications.field.user_ids',
            'admin.bucket3.bulk_notifications.field.user_ids_helper',
            'admin.bucket3.bulk_notifications.option.all_b2c',
            'admin.bucket3.bulk_notifications.option.all_staff',
            'admin.bucket3.bulk_notifications.option.by_company',
            'admin.bucket3.bulk_notifications.option.specific_users',
            'admin.bucket3.bulk_notifications.priority.high',
            'admin.bucket3.bulk_notifications.priority.low',
            'admin.bucket3.bulk_notifications.priority.normal',
            'admin.bucket3.bulk_notifications.section.message',
            'admin.bucket3.bulk_notifications.section.recipients',
            'admin.bucket3.bulk_notifications.send',
            'admin.bucket3.bulk_notifications.sending',
            'admin.bucket3.bulk_notifications.subtitle',
            'admin.bucket3.bulk_notifications.success',
            'admin.bucket3.bulk_notifications.title',
            'admin.bucket3.cases.close',
            'admin.bucket3.cases.col.assigned',
            'admin.bucket3.cases.col.case_number',
            'admin.bucket3.cases.col.opened',
            'admin.bucket3.cases.col.priority',
            'admin.bucket3.cases.col.sla',
            'admin.bucket3.cases.col.status',
            'admin.bucket3.cases.col.title',
            'admin.bucket3.cases.conversation',
            'admin.bucket3.cases.create',
            'admin.bucket3.cases.creating',
            'admin.bucket3.cases.empty',
            'admin.bucket3.cases.error.title_and_description',
            'admin.bucket3.cases.field.assignee',
            'admin.bucket3.cases.field.closing_notes',
            'admin.bucket3.cases.field.company_id',
            'admin.bucket3.cases.field.description',
            'admin.bucket3.cases.field.priority',
            'admin.bucket3.cases.field.reassign',
            'admin.bucket3.cases.field.reply',
            'admin.bucket3.cases.field.reply_placeholder',
            'admin.bucket3.cases.field.title',
            'admin.bucket3.cases.field.update_priority',
            'admin.bucket3.cases.field.update_status',
            'admin.bucket3.cases.filter.priority',
            'admin.bucket3.cases.filter.status',
            'admin.bucket3.cases.internal',
            'admin.bucket3.cases.label.assigned_to',
            'admin.bucket3.cases.label.closed_at',
            'admin.bucket3.cases.label.company',
            'admin.bucket3.cases.label.opened_at',
            'admin.bucket3.cases.label.opened_by',
            'admin.bucket3.cases.new_case',
            'admin.bucket3.cases.no_replies',
            'admin.bucket3.cases.replies_count',
            'admin.bucket3.cases.search_placeholder',
            'admin.bucket3.cases.send_internal',
            'admin.bucket3.cases.send_reply',
            'admin.bucket3.cases.sending',
            'admin.bucket3.cases.subtitle',
            'admin.bucket3.cases.subtitle_count',
            'admin.bucket3.cases.title',
            'admin.bucket3.custom_fields.add_field',
            'admin.bucket3.custom_fields.cancel_edit',
            'admin.bucket3.custom_fields.col.actions',
            'admin.bucket3.custom_fields.col.active',
            'admin.bucket3.custom_fields.col.flags',
            'admin.bucket3.custom_fields.col.key',
            'admin.bucket3.custom_fields.col.label',
            'admin.bucket3.custom_fields.col.order',
            'admin.bucket3.custom_fields.col.scope',
            'admin.bucket3.custom_fields.col.type',
            'admin.bucket3.custom_fields.edit_field',
            'admin.bucket3.custom_fields.empty',
            'admin.bucket3.custom_fields.error.key_format',
            'admin.bucket3.custom_fields.error.key_label_required',
            'admin.bucket3.custom_fields.field.active',
            'admin.bucket3.custom_fields.field.display_order',
            'admin.bucket3.custom_fields.field.help_text',
            'admin.bucket3.custom_fields.field.help_text_placeholder',
            'admin.bucket3.custom_fields.field.key',
            'admin.bucket3.custom_fields.field.key_helper',
            'admin.bucket3.custom_fields.field.key_placeholder',
            'admin.bucket3.custom_fields.field.label',
            'admin.bucket3.custom_fields.field.label_placeholder',
            'admin.bucket3.custom_fields.field.options',
            'admin.bucket3.custom_fields.field.options_helper',
            'admin.bucket3.custom_fields.field.required',
            'admin.bucket3.custom_fields.field.scope',
            'admin.bucket3.custom_fields.field.show_in_filter',
            'admin.bucket3.custom_fields.field.type',
            'admin.bucket3.custom_fields.save_changes',
            'admin.bucket3.custom_fields.status.active',
            'admin.bucket3.custom_fields.status.inactive',
            'admin.bucket3.custom_fields.subtitle',
            'admin.bucket3.custom_fields.subtitle_count',
            'admin.bucket3.custom_fields.title',
            'admin.bucket3.customers.col.bookings',
            'admin.bucket3.customers.col.email',
            'admin.bucket3.customers.col.joined',
            'admin.bucket3.customers.col.language',
            'admin.bucket3.customers.col.name',
            'admin.bucket3.customers.col.nationality',
            'admin.bucket3.customers.col.phone',
            'admin.bucket3.customers.col.status',
            'admin.bucket3.customers.empty',
            'admin.bucket3.customers.empty_filter',
            'admin.bucket3.customers.filter.status',
            'admin.bucket3.customers.refresh',
            'admin.bucket3.customers.search_placeholder',
            'admin.bucket3.customers.subtitle',
            'admin.bucket3.customers.subtitle_count',
            'admin.bucket3.customers.title',
            'admin.bucket3.employees.col.companies',
            'admin.bucket3.employees.col.email',
            'admin.bucket3.employees.col.joined',
            'admin.bucket3.employees.col.name',
            'admin.bucket3.employees.col.status',
            'admin.bucket3.employees.empty',
            'admin.bucket3.employees.filter.status',
            'admin.bucket3.employees.search_placeholder',
            'admin.bucket3.employees.status.active',
            'admin.bucket3.employees.status.inactive',
            'admin.bucket3.employees.status.pending',
            'admin.bucket3.employees.status.suspended',
            'admin.bucket3.employees.subtitle',
            'admin.bucket3.employees.subtitle_count',
            'admin.bucket3.employees.title',
            'admin.bucket3.non_service_hours.approve',
            'admin.bucket3.non_service_hours.clock_in',
            'admin.bucket3.non_service_hours.clock_out',
            'admin.bucket3.non_service_hours.clock_out_open',
            'admin.bucket3.non_service_hours.col.actions',
            'admin.bucket3.non_service_hours.col.duration',
            'admin.bucket3.non_service_hours.col.employee',
            'admin.bucket3.non_service_hours.col.from',
            'admin.bucket3.non_service_hours.col.hours',
            'admin.bucket3.non_service_hours.col.in',
            'admin.bucket3.non_service_hours.col.out',
            'admin.bucket3.non_service_hours.col.status',
            'admin.bucket3.non_service_hours.col.to',
            'admin.bucket3.non_service_hours.col.type',
            'admin.bucket3.non_service_hours.empty_records',
            'admin.bucket3.non_service_hours.empty_shifts',
            'admin.bucket3.non_service_hours.error.dates_required',
            'admin.bucket3.non_service_hours.field.ends',
            'admin.bucket3.non_service_hours.field.hours_total',
            'admin.bucket3.non_service_hours.field.notes',
            'admin.bucket3.non_service_hours.field.starts',
            'admin.bucket3.non_service_hours.field.type',
            'admin.bucket3.non_service_hours.field.user_id',
            'admin.bucket3.non_service_hours.field.user_id_helper',
            'admin.bucket3.non_service_hours.filter.status',
            'admin.bucket3.non_service_hours.on_the_clock',
            'admin.bucket3.non_service_hours.reject',
            'admin.bucket3.non_service_hours.request_time_off',
            'admin.bucket3.non_service_hours.submit_request',
            'admin.bucket3.non_service_hours.submitting',
            'admin.bucket3.non_service_hours.subtitle',
            'admin.bucket3.non_service_hours.title',
            'admin.bucket3.non_service_hours.todays_shifts',
            'admin.bucket3.non_service_hours.todays_shifts_helper',
            'admin.bucket3.payroll.add_record',
            'admin.bucket3.payroll.col.actions',
            'admin.bucket3.payroll.col.deductions',
            'admin.bucket3.payroll.col.employee',
            'admin.bucket3.payroll.col.gross',
            'admin.bucket3.payroll.col.net',
            'admin.bucket3.payroll.col.period',
            'admin.bucket3.payroll.col.status',
            'admin.bucket3.payroll.confirm_move',
            'admin.bucket3.payroll.create_record',
            'admin.bucket3.payroll.empty',
            'admin.bucket3.payroll.error.dates_and_user',
            'admin.bucket3.payroll.export_bank_batch',
            'admin.bucket3.payroll.field.base_salary',
            'admin.bucket3.payroll.field.bonus',
            'admin.bucket3.payroll.field.commission',
            'admin.bucket3.payroll.field.currency',
            'admin.bucket3.payroll.field.deductions',
            'admin.bucket3.payroll.field.hourly_rate',
            'admin.bucket3.payroll.field.hours_worked',
            'admin.bucket3.payroll.field.notes',
            'admin.bucket3.payroll.field.period_ends',
            'admin.bucket3.payroll.field.period_starts',
            'admin.bucket3.payroll.field.user_id',
            'admin.bucket3.payroll.filter.status',
            'admin.bucket3.payroll.finalize',
            'admin.bucket3.payroll.gross_net_helper',
            'admin.bucket3.payroll.mark_paid',
            'admin.bucket3.payroll.paid',
            'admin.bucket3.payroll.payslip',
            'admin.bucket3.payroll.subtitle',
            'admin.bucket3.payroll.subtitle_count',
            'admin.bucket3.payroll.title',
            'admin.bucket3.per_x_invoicing.col.bucket',
            'admin.bucket3.per_x_invoicing.col.currency',
            'admin.bucket3.per_x_invoicing.col.invoices',
            'admin.bucket3.per_x_invoicing.col.operator',
            'admin.bucket3.per_x_invoicing.col.total',
            'admin.bucket3.per_x_invoicing.empty',
            'admin.bucket3.per_x_invoicing.group.currency',
            'admin.bucket3.per_x_invoicing.group.month',
            'admin.bucket3.per_x_invoicing.group.operator',
            'admin.bucket3.per_x_invoicing.group.status',
            'admin.bucket3.per_x_invoicing.group_by',
            'admin.bucket3.per_x_invoicing.refresh',
            'admin.bucket3.per_x_invoicing.subtitle',
            'admin.bucket3.per_x_invoicing.title',
            'admin.bucket3.per_x_invoicing.totals',
            'admin.bucket3.per_x_invoicing.totals_count',
            'admin.bucket3.pin_settings.change_pin',
            'admin.bucket3.pin_settings.clear_pin',
            'admin.bucket3.pin_settings.clear_pin_helper',
            'admin.bucket3.pin_settings.error.clear_password_required',
            'admin.bucket3.pin_settings.error.current_pin_required',
            'admin.bucket3.pin_settings.error.mismatch',
            'admin.bucket3.pin_settings.error.password_required',
            'admin.bucket3.pin_settings.error.pin_format',
            'admin.bucket3.pin_settings.field.account_password',
            'admin.bucket3.pin_settings.field.account_password_helper',
            'admin.bucket3.pin_settings.field.confirm_pin',
            'admin.bucket3.pin_settings.field.current_pin',
            'admin.bucket3.pin_settings.field.new_pin',
            'admin.bucket3.pin_settings.field.pin',
            'admin.bucket3.pin_settings.last_set',
            'admin.bucket3.pin_settings.no_pin_set',
            'admin.bucket3.pin_settings.pin_incorrect',
            'admin.bucket3.pin_settings.pin_is_set',
            'admin.bucket3.pin_settings.pin_matches',
            'admin.bucket3.pin_settings.saved_at',
            'admin.bucket3.pin_settings.set_pin',
            'admin.bucket3.pin_settings.status',
            'admin.bucket3.pin_settings.subtitle',
            'admin.bucket3.pin_settings.test_pin',
            'admin.bucket3.pin_settings.test_pin_helper',
            'admin.bucket3.pin_settings.title',
            'admin.bucket3.pin_settings.verify',
            'admin.bucket3.requests.close',
            'admin.bucket3.requests.col.created',
            'admin.bucket3.requests.col.from',
            'admin.bucket3.requests.col.status',
            'admin.bucket3.requests.col.subject',
            'admin.bucket3.requests.col.to',
            'admin.bucket3.requests.created_at',
            'admin.bucket3.requests.empty',
            'admin.bucket3.requests.field.body',
            'admin.bucket3.requests.field.resolution_notes',
            'admin.bucket3.requests.field.resolution_placeholder',
            'admin.bucket3.requests.field.subject',
            'admin.bucket3.requests.field.target_company',
            'admin.bucket3.requests.field.target_company_placeholder',
            'admin.bucket3.requests.filter.status',
            'admin.bucket3.requests.from',
            'admin.bucket3.requests.mark_in_progress',
            'admin.bucket3.requests.new_request',
            'admin.bucket3.requests.reject',
            'admin.bucket3.requests.resolution_notes',
            'admin.bucket3.requests.resolve',
            'admin.bucket3.requests.resolved_at',
            'admin.bucket3.requests.resolved_by',
            'admin.bucket3.requests.send',
            'admin.bucket3.requests.sending',
            'admin.bucket3.requests.subtitle',
            'admin.bucket3.requests.subtitle_count',
            'admin.bucket3.requests.title',
            'admin.bucket3.requests.view',
            'admin.bucket3.requests.view.inbox',
            'admin.bucket3.requests.view.outbox',
            'admin.bucket3.service_catalog.add_item',
            'admin.bucket3.service_catalog.cancel_edit',
            'admin.bucket3.service_catalog.col.actions',
            'admin.bucket3.service_catalog.col.active',
            'admin.bucket3.service_catalog.col.category',
            'admin.bucket3.service_catalog.col.name',
            'admin.bucket3.service_catalog.col.price',
            'admin.bucket3.service_catalog.col.unit',
            'admin.bucket3.service_catalog.edit_item',
            'admin.bucket3.service_catalog.empty',
            'admin.bucket3.service_catalog.error.name_required',
            'admin.bucket3.service_catalog.field.active',
            'admin.bucket3.service_catalog.field.base_price',
            'admin.bucket3.service_catalog.field.category',
            'admin.bucket3.service_catalog.field.category_placeholder',
            'admin.bucket3.service_catalog.field.currency',
            'admin.bucket3.service_catalog.field.description',
            'admin.bucket3.service_catalog.field.name',
            'admin.bucket3.service_catalog.field.name_placeholder',
            'admin.bucket3.service_catalog.field.unit',
            'admin.bucket3.service_catalog.pick',
            'admin.bucket3.service_catalog.save_changes',
            'admin.bucket3.service_catalog.search_placeholder',
            'admin.bucket3.service_catalog.status.active',
            'admin.bucket3.service_catalog.status.inactive',
            'admin.bucket3.service_catalog.subtitle',
            'admin.bucket3.service_catalog.subtitle_count',
            'admin.bucket3.service_catalog.title',
            'admin.bucket3.service_logs.all_categories',
            'admin.bucket3.service_logs.col.action',
            'admin.bucket3.service_logs.col.actor',
            'admin.bucket3.service_logs.col.category',
            'admin.bucket3.service_logs.col.subject',
            'admin.bucket3.service_logs.col.when',
            'admin.bucket3.service_logs.empty',
            'admin.bucket3.service_logs.filter.category',
            'admin.bucket3.service_logs.full_audit_log',
            'admin.bucket3.service_logs.search_placeholder',
            'admin.bucket3.service_logs.subtitle',
            'admin.bucket3.service_logs.subtitle_count',
            'admin.bucket3.service_logs.title',
            'admin.bucket3.subscriptions.active_subscriptions',
            'admin.bucket3.subscriptions.add_plan',
            'admin.bucket3.subscriptions.assign',
            'admin.bucket3.subscriptions.assign_plan',
            'admin.bucket3.subscriptions.col.active',
            'admin.bucket3.subscriptions.col.annual',
            'admin.bucket3.subscriptions.col.code',
            'admin.bucket3.subscriptions.col.features',
            'admin.bucket3.subscriptions.col.monthly',
            'admin.bucket3.subscriptions.col.name',
            'admin.bucket3.subscriptions.col.order',
            'admin.bucket3.subscriptions.empty_plans',
            'admin.bucket3.subscriptions.empty_subscriptions',
            'admin.bucket3.subscriptions.error.code_name_required',
            'admin.bucket3.subscriptions.error.company_plan_required',
            'admin.bucket3.subscriptions.features_helper',
            'admin.bucket3.subscriptions.field.active',
            'admin.bucket3.subscriptions.field.annual_price',
            'admin.bucket3.subscriptions.field.billing_period',
            'admin.bucket3.subscriptions.field.code',
            'admin.bucket3.subscriptions.field.code_helper',
            'admin.bucket3.subscriptions.field.company_id',
            'admin.bucket3.subscriptions.field.currency',
            'admin.bucket3.subscriptions.field.description',
            'admin.bucket3.subscriptions.field.display_order',
            'admin.bucket3.subscriptions.field.features',
            'admin.bucket3.subscriptions.field.monthly_price',
            'admin.bucket3.subscriptions.field.name',
            'admin.bucket3.subscriptions.field.notes',
            'admin.bucket3.subscriptions.field.period_ends',
            'admin.bucket3.subscriptions.field.period_starts',
            'admin.bucket3.subscriptions.field.plan',
            'admin.bucket3.subscriptions.field.status',
            'admin.bucket3.subscriptions.pick',
            'admin.bucket3.subscriptions.plan_catalog',
            'admin.bucket3.subscriptions.status.active',
            'admin.bucket3.subscriptions.status.inactive',
            'admin.bucket3.subscriptions.sub_col.billing',
            'admin.bucket3.subscriptions.sub_col.company',
            'admin.bucket3.subscriptions.sub_col.notes',
            'admin.bucket3.subscriptions.sub_col.period',
            'admin.bucket3.subscriptions.sub_col.plan',
            'admin.bucket3.subscriptions.sub_col.status',
            'admin.bucket3.subscriptions.subtitle',
            'admin.bucket3.subscriptions.title',
            'admin.bucket3.unverified_accounts.b2c_no_company',
            'admin.bucket3.unverified_accounts.col.companies',
            'admin.bucket3.unverified_accounts.col.email',
            'admin.bucket3.unverified_accounts.col.email_verified',
            'admin.bucket3.unverified_accounts.col.intended_role',
            'admin.bucket3.unverified_accounts.col.name',
            'admin.bucket3.unverified_accounts.col.registered',
            'admin.bucket3.unverified_accounts.col.status',
            'admin.bucket3.unverified_accounts.empty',
            'admin.bucket3.unverified_accounts.not_verified',
            'admin.bucket3.unverified_accounts.search_placeholder',
            'admin.bucket3.unverified_accounts.subtitle',
            'admin.bucket3.unverified_accounts.subtitle_count',
            'admin.bucket3.unverified_accounts.title',
        ];

        DB::table('ui_translations')
            ->whereIn('key', $keys)
            ->delete();
    }
};
