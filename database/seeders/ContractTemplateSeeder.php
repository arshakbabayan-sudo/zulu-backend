<?php

namespace Database\Seeders;

use App\Models\ContractTemplate;
use Illuminate\Database\Seeder;

class ContractTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $platformVariables = [
            ['key' => 'seller_company_name', 'label' => 'Seller company name', 'type' => 'string'],
            ['key' => 'seller_legal_name', 'label' => 'Seller legal entity', 'type' => 'string'],
            ['key' => 'commission_rate_pct', 'label' => 'Commission %', 'type' => 'number'],
            ['key' => 'commission_currency', 'label' => 'Commission currency', 'type' => 'string'],
            ['key' => 'payment_frequency', 'label' => 'Payment frequency', 'type' => 'string'],
            ['key' => 'effective_date', 'label' => 'Effective date', 'type' => 'date'],
            ['key' => 'jurisdiction', 'label' => 'Jurisdiction', 'type' => 'string'],
        ];

        $partnerVariables = [
            ['key' => 'seller_a_name', 'label' => 'Seller A name', 'type' => 'string'],
            ['key' => 'seller_b_name', 'label' => 'Seller B name', 'type' => 'string'],
            ['key' => 'commission_override_pct', 'label' => 'Commission override %', 'type' => 'number'],
            ['key' => 'territory', 'label' => 'Territorial scope', 'type' => 'string'],
            ['key' => 'effective_date', 'label' => 'Effective date', 'type' => 'date'],
        ];

        $templates = [
            [
                'name' => 'Platform Contract — English (default)',
                'type' => 'platform',
                'language' => 'en',
                'body' => $this->platformBodyEn(),
                'variables' => $platformVariables,
            ],
            [
                'name' => 'Platform Contract — Հայերեն (default)',
                'type' => 'platform',
                'language' => 'hy',
                'body' => $this->platformBodyHy(),
                'variables' => $platformVariables,
            ],
            [
                'name' => 'Platform Contract — Русский (default)',
                'type' => 'platform',
                'language' => 'ru',
                'body' => $this->platformBodyRu(),
                'variables' => $platformVariables,
            ],
            [
                'name' => 'Partner Agreement — Default (English)',
                'type' => 'partner_default',
                'language' => 'en',
                'body' => $this->partnerBodyEn(),
                'variables' => $partnerVariables,
            ],
        ];

        foreach ($templates as $tpl) {
            $exists = ContractTemplate::query()
                ->where('type', $tpl['type'])
                ->where('language', $tpl['language'])
                ->where('name', $tpl['name'])
                ->exists();

            if ($exists) {
                continue;
            }

            ContractTemplate::query()->create(array_merge($tpl, [
                'version' => 1,
                'active' => true,
            ]));
        }
    }

    private function platformBodyEn(): string
    {
        return <<<'HTML'
<h1>ZULU Platform Contract</h1>
<p>This Platform Contract (the "Contract") is entered into between <strong>ZULU</strong> ("Platform") and <strong>{{seller_legal_name}}</strong> trading as <strong>{{seller_company_name}}</strong> ("Seller"), effective {{effective_date}}.</p>

<h2>1. Services</h2>
<p>The Seller is granted access to the Platform to list and sell travel services in accordance with the Platform's policies.</p>

<h2>2. Commission</h2>
<p>The Platform shall retain a commission of <strong>{{commission_rate_pct}}%</strong> in {{commission_currency}} on every successful booking.</p>

<h2>3. Payment Terms</h2>
<p>Payouts are made on a <strong>{{payment_frequency}}</strong> basis, net of commission and any applicable platform fees, to the bank account designated by the Seller.</p>

<h2>4. Term and Termination</h2>
<p>The Contract automatically renews for successive periods unless either party gives written notice. Termination requires a notice period as specified in the contract metadata.</p>

<h2>5. Governing Law</h2>
<p>This Contract is governed by the laws of <strong>{{jurisdiction}}</strong>.</p>

<p>By signing this Contract, both parties acknowledge their acceptance of all terms herein.</p>
HTML;
    }

    private function platformBodyHy(): string
    {
        return <<<'HTML'
<h1>ZULU Հարթակային պայմանագիր</h1>
<p>Այս Հարթակային պայմանագիրը ("Պայմանագիր") կնքվում է <strong>ZULU</strong> ("Հարթակ") և <strong>{{seller_legal_name}}</strong> ընկերության միջև, որը գործում է <strong>{{seller_company_name}}</strong> անվանումով ("Վաճառող"), ուժի մեջ է մտնում {{effective_date}}-ից։</p>

<h2>1. Ծառայություններ</h2>
<p>Վաճառողին տրվում է մուտք Հարթակ՝ ճանապարհորդական ծառայություններ ցուցադրելու և վաճառելու համար՝ համաձայն Հարթակի քաղաքականության։</p>

<h2>2. Միջնորդավճար</h2>
<p>Հարթակը պահում է <strong>{{commission_rate_pct}}%</strong> միջնորդավճար {{commission_currency}}-ով ամեն հաջող ամրագրումից։</p>

<h2>3. Վճարման պայմաններ</h2>
<p>Վճարումները կատարվում են <strong>{{payment_frequency}}</strong> հաճախականությամբ՝ Վաճառողի կողմից նշված բանկային հաշվին։</p>

<h2>4. Ժամկետ և դադարեցում</h2>
<p>Պայմանագիրը ինքնաբերաբար երկարացվում է հաջորդական ժամկետներով, եթե կողմերից որևէ մեկը գրավոր ծանուցում չի ուղարկում։</p>

<h2>5. Կիրառելի օրենք</h2>
<p>Այս Պայմանագիրը կարգավորվում է <strong>{{jurisdiction}}</strong>-ի օրենքներով։</p>
HTML;
    }

    private function platformBodyRu(): string
    {
        return <<<'HTML'
<h1>Платформенное соглашение ZULU</h1>
<p>Настоящее Платформенное соглашение ("Соглашение") заключается между <strong>ZULU</strong> ("Платформа") и <strong>{{seller_legal_name}}</strong>, действующим под наименованием <strong>{{seller_company_name}}</strong> ("Продавец"), вступает в силу {{effective_date}}.</p>

<h2>1. Услуги</h2>
<p>Продавцу предоставляется доступ к Платформе для размещения и продажи туристических услуг в соответствии с политикой Платформы.</p>

<h2>2. Комиссия</h2>
<p>Платформа удерживает комиссию в размере <strong>{{commission_rate_pct}}%</strong> в {{commission_currency}} с каждого успешного бронирования.</p>

<h2>3. Условия оплаты</h2>
<p>Выплаты производятся на условиях <strong>{{payment_frequency}}</strong> на банковский счёт, указанный Продавцом.</p>

<h2>4. Срок действия и расторжение</h2>
<p>Соглашение автоматически продлевается на последующие периоды, если ни одна из сторон не направит письменное уведомление.</p>

<h2>5. Применимое право</h2>
<p>Настоящее Соглашение регулируется законодательством <strong>{{jurisdiction}}</strong>.</p>
HTML;
    }

    private function partnerBodyEn(): string
    {
        return <<<'HTML'
<h1>Partner Agreement</h1>
<p>This Partner Agreement is entered into between <strong>{{seller_a_name}}</strong> and <strong>{{seller_b_name}}</strong>, effective {{effective_date}}.</p>

<h2>1. Scope</h2>
<p>The parties agree to collaborate on travel services within the territory of <strong>{{territory}}</strong>.</p>

<h2>2. Commission Override</h2>
<p>Notwithstanding the default commission structure, an override rate of <strong>{{commission_override_pct}}%</strong> shall apply to bookings under this Agreement.</p>

<h2>3. Mutual Obligations</h2>
<p>Each party shall act in good faith and in accordance with the policies of the ZULU Platform.</p>
HTML;
    }
}
