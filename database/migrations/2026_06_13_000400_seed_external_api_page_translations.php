<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Roadmap §4 — External API page went from placeholder to real supplier
 * connections; seed its EN/HY/RU strings.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $rows = [
            ['title', 'External API', 'Արտաքին API', 'Внешний API'],
            ['subtitle', 'Connect your company to an external inventory base — test the link and import its offers as drafts.', 'Միացրու ընկերությունդ արտաքին գույքի բազային — ստուգիր կապը և ներմուծիր նրա առաջարկները որպես սևագիր։', 'Подключите компанию к внешней базе инвентаря — проверьте связь и импортируйте её предложения как черновики.'],
            ['add_title', 'New connection', 'Նոր կապ', 'Новое подключение'],
            ['field_name', 'Name', 'Անվանում', 'Название'],
            ['field_name_ph', 'e.g. My supplier base', 'օր.՝ Իմ մատակարարի բազան', 'напр., База моего поставщика'],
            ['field_base_url', 'Base URL', 'Բազայի հասցե (URL)', 'Адрес базы (URL)'],
            ['field_login', 'Login', 'Մուտքանուն', 'Логин'],
            ['field_password', 'Password / API key', 'Գաղտնաբառ / API բանալի', 'Пароль / API-ключ'],
            ['add_btn', 'Add connection', 'Ավելացնել կապը', 'Добавить подключение'],
            ['fill_required', 'Fill base URL, login and password.', 'Լրացրու հասցեն, մուտքանունն ու գաղտնաբառը։', 'Заполните адрес, логин и пароль.'],
            ['test_btn', 'Test connection', 'Ստուգել կապը', 'Проверить связь'],
            ['import_btn', 'Import inventory', 'Ներմուծել գույքը', 'Импортировать инвентарь'],
            ['disconnect_btn', 'Disconnect', 'Անջատել', 'Отключить'],
            ['disconnect_confirm', 'Disconnect this base? Already-imported items stay in your inventory.', 'Անջատե՞լ այս կապը։ Արդեն ներմուծված ապրանքները կմնան քո գույքացուցակում։', 'Отключить эту базу? Уже импортированные позиции останутся в инвентаре.'],
            ['working', 'Working…', 'Կատարվում է…', 'Выполняется…'],
            ['status_untested', 'Not tested', 'Չստուգված', 'Не проверено'],
            ['status_ok', 'Connected', 'Միացված', 'Подключено'],
            ['status_failed', 'Error', 'Սխալ', 'Ошибка'],
            ['last_tested', 'Last test', 'Վերջին ստուգում', 'Последняя проверка'],
            ['last_synced', 'Last import', 'Վերջին ներմուծում', 'Последний импорт'],
            ['items_imported', 'Imported items', 'Ներմուծված ապրանքներ', 'Импортировано позиций'],
            ['import_done', 'Import finished', 'Ներմուծումն ավարտվեց', 'Импорт завершён'],
            ['sum_created', 'created', 'ստեղծված՝', 'создано:'],
            ['sum_updated', 'updated', 'թարմացված՝', 'обновлено:'],
            ['sum_skipped', 'skipped', 'բաց թողնված՝', 'пропущено:'],
            ['empty', 'No connections yet — add the first one with the form above.', 'Կապեր դեռ չկան — ավելացրու առաջինը վերևի ձևով։', 'Подключений пока нет — добавьте первое через форму выше.'],
            ['err_load', 'Failed to load connections.', 'Չհաջողվեց բեռնել կապերը։', 'Не удалось загрузить подключения.'],
            ['err_save', 'Failed to save the connection.', 'Չհաջողվեց պահել կապը։', 'Не удалось сохранить подключение.'],
            ['err_action', 'The action failed — try again.', 'Գործողությունը չստացվեց — փորձիր նորից։', 'Действие не выполнено — попробуйте ещё раз.'],
            ['demo_title', 'Try it with the built-in demo base', 'Փորձիր ներկառուցված փորձնական բազայով', 'Попробуйте на встроенной демо-базе'],
            ['demo_text', 'ZULU ships a small demo supplier so you can see the whole flow without a real partner: add a connection with the details below, press Test, then Import — a few clearly demo-named draft items will appear in your inventory.', 'ZULU-ն ունի ներկառուցված փորձնական մատակարար, որ ամբողջ հոսքը տեսնես առանց իրական գործընկերոջ. ավելացրու կապը ստորև տվյալներով, սեղմիր «Ստուգել կապը», հետո «Ներմուծել» — գույքացուցակումդ կհայտնվեն մի քանի ակնհայտ փորձնական (Demo) սևագիր ապրանքներ։', 'В ZULU встроен демо-поставщик, чтобы увидеть весь процесс без реального партнёра: добавьте подключение с данными ниже, нажмите «Проверить связь», затем «Импортировать» — в инвентаре появятся несколько явно демонстрационных черновиков.'],
        ];

        $batch = [];
        foreach ($rows as [$suffix, $en, $hy, $ru]) {
            foreach (['en' => $en, 'hy' => $hy, 'ru' => $ru] as $lang => $value) {
                $batch[] = [
                    'language_code' => $lang,
                    'key' => 'admin.crud.external_api.'.$suffix,
                    'value' => $value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('ui_translations')->upsert(
            $batch,
            ['language_code', 'key'],
            ['value', 'updated_at']
        );

        foreach (['en', 'hy', 'ru'] as $lang) {
            Cache::forget("ui_translations_{$lang}");
        }
    }

    public function down(): void
    {
        // Translations may be refined in the admin UI afterwards — keep.
    }
};
