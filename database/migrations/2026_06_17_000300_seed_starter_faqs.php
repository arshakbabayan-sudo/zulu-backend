<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed a starter set of trilingual FAQs so the public /help page has content
 * before staff author their own (managed via /platform-admin/faqs). Idempotent:
 * skips if the table already has rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Don't seed content into the test database — feature tests assert on
        // exact FAQ counts against an empty table.
        if (app()->environment('testing')) {
            return;
        }

        if (DB::table('faqs')->count() > 0) {
            return;
        }

        $now = now();
        $rows = [
            [
                'category' => 'general', 'sort_order' => 1,
                'question_hy' => 'Ի՞նչ է ZULU-ն', 'question_ru' => 'Что такое ZULU?', 'question_en' => 'What is ZULU?',
                'answer_hy' => 'ZULU-ն ճանապարհորդության ամրագրման հարթակ է՝ թռիչքներ, հյուրանոցներ, փաթեթներ, փոխադրումներ, էքսկուրսիաներ ու վիզայի աջակցություն մեկ տեղում։',
                'answer_ru' => 'ZULU — это платформа бронирования путешествий: авиабилеты, отели, пакеты, трансферы, экскурсии и визовая поддержка в одном месте.',
                'answer_en' => 'ZULU is a travel booking platform — flights, hotels, packages, transfers, excursions and visa support, all in one place.',
            ],
            [
                'category' => 'booking', 'sort_order' => 1,
                'question_hy' => 'Ինչպե՞ս ամրագրել ճանապարհորդություն', 'question_ru' => 'Как забронировать поездку?', 'question_en' => 'How do I book a trip?',
                'answer_hy' => 'Ընտրիր ծառայությունը (թռիչք, հյուրանոց, փաթեթ և այլն), լրացրու ամսաթվերն ու ուղևորների տվյալները, ապա անցիր վճարման։ Հաստատումից հետո կստանաս վաուչեր էլ. փոստով ու քո հաշվում։',
                'answer_ru' => 'Выберите услугу (авиабилет, отель, пакет и т.д.), укажите даты и данные путешественников и перейдите к оплате. После подтверждения вы получите ваучер на эл. почту и в личном кабинете.',
                'answer_en' => 'Pick a service (flight, hotel, package, etc.), enter your dates and traveller details, then proceed to payment. After confirmation you receive a voucher by email and in your account.',
            ],
            [
                'category' => 'payment', 'sort_order' => 1,
                'question_hy' => 'Ի՞նչ վճարման եղանակներ եք ընդունում', 'question_ru' => 'Какие способы оплаты вы принимаете?', 'question_en' => 'What payment methods do you accept?',
                'answer_hy' => 'Ընդունում ենք բանկային քարտերով վճարում։ Վճարումն անվտանգ է. քարտի տվյալները մշակվում են վճարային համակարգի կողմից ու չեն պահվում մեր մոտ։',
                'answer_ru' => 'Мы принимаем оплату банковскими картами. Оплата безопасна: данные карты обрабатываются платёжной системой и у нас не хранятся.',
                'answer_en' => 'We accept payment by bank card. Payment is secure — card details are handled by the payment provider and are not stored by us.',
            ],
            [
                'category' => 'payment', 'sort_order' => 2,
                'question_hy' => 'Կարո՞ղ եմ գումարը հետ ստանալ', 'question_ru' => 'Могу ли я вернуть деньги?', 'question_en' => 'Can I get a refund?',
                'answer_hy' => 'Այո՝ ըստ ամրագրման չեղարկման պայմանների։ Հաշվիդ «Աջակցություն» բաժնից բացիր վերադարձի հայց՝ ընտրելով ամրագրումը. թիմը կստուգի ու հաստատվելու դեպքում գումարը կվերադարձվի։',
                'answer_ru' => 'Да, согласно условиям отмены брони. В разделе «Поддержка» личного кабинета создайте запрос на возврат, выбрав бронь; команда проверит, и при одобрении средства будут возвращены.',
                'answer_en' => 'Yes, subject to the booking\'s cancellation terms. In your account under "Support", open a refund request and pick the booking; the team reviews it and, if approved, the amount is returned.',
            ],
            [
                'category' => 'account', 'sort_order' => 1,
                'question_hy' => 'Ինչպե՞ս վերականգնել գաղտնաբառը', 'question_ru' => 'Как сбросить пароль?', 'question_en' => 'How do I reset my password?',
                'answer_hy' => 'Մուտքի էջում սեղմիր «Մոռացե՞լ եք գաղտնաբառը», նշիր էլ. փոստդ, ու կստանաս վերականգնման հղում։',
                'answer_ru' => 'На странице входа нажмите «Забыли пароль?», укажите свою эл. почту — и вы получите ссылку для сброса.',
                'answer_en' => 'On the login page click "Forgot password?", enter your email, and you\'ll receive a reset link.',
            ],
            [
                'category' => 'account', 'sort_order' => 2,
                'question_hy' => 'Ինչպե՞ս փոխել ծանուցումների նախընտրությունները', 'question_ru' => 'Как изменить настройки уведомлений?', 'question_en' => 'How do I change my notification preferences?',
                'answer_hy' => 'Հաշվիդ «Ծանուցումներ → Նախընտրություններ» բաժնում ընտրիր՝ ո՛ր իրադարձության համար ո՛ր ճանապարհով (էլ. փոստ, կայք, push) ստանաս ծանուցում։',
                'answer_ru' => 'В разделе «Уведомления → Предпочтения» личного кабинета выберите, по какому событию и каким способом (эл. почта, сайт, push) получать уведомления.',
                'answer_en' => 'In your account under "Notifications → Preferences", choose which event you get notified about and by which channel (email, in-app, push).',
            ],
            [
                'category' => 'partners', 'sort_order' => 1,
                'question_hy' => 'Ինչպե՞ս դառնալ գործընկեր կամ գործակալ', 'question_ru' => 'Как стать партнёром или агентом?', 'question_en' => 'How do I become a partner or agent?',
                'answer_hy' => 'Գրանցվիր որպես գործընկեր/գործակալ ընկերության գրանցման էջից։ Հաստատումից հետո կստանաս մուտք քո վահանակ՝ ապրանքներ ավելացնելու ու վաճառքները վարելու համար։',
                'answer_ru' => 'Зарегистрируйтесь как партнёр/агент на странице регистрации компании. После одобрения вы получите доступ к панели для добавления товаров и управления продажами.',
                'answer_en' => 'Register as a partner/agent from the company sign-up page. Once approved, you get access to your dashboard to add inventory and manage sales.',
            ],
            [
                'category' => 'visa', 'sort_order' => 1,
                'question_hy' => 'ZULU-ն օգնու՞մ է վիզայի հարցում', 'question_ru' => 'Помогает ли ZULU с визой?', 'question_en' => 'Does ZULU help with visas?',
                'answer_hy' => 'Այո՝ առաջարկում ենք վիզայի աջակցություն ընտրված ուղղությունների համար։ Տես «Վիզա» բաժինը՝ պահանջներն ու դիմելու քայլերը ստուգելու համար։',
                'answer_ru' => 'Да, мы предлагаем визовую поддержку по ряду направлений. Смотрите раздел «Виза», чтобы узнать требования и шаги подачи.',
                'answer_en' => 'Yes, we offer visa support for selected destinations. See the "Visa" section to check requirements and how to apply.',
            ],
        ];

        foreach ($rows as &$row) {
            $row['is_active'] = true;
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }
        unset($row);

        DB::table('faqs')->insert($rows);
    }

    public function down(): void
    {
        DB::table('faqs')->truncate();
    }
};
