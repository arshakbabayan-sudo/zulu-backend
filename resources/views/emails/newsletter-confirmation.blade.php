@extends('emails.layout')

@php
    $copy = match ($lang ?? 'en') {
        'hy' => [
            'title' => 'Հաստատեք ձեր բաժանորդագրությունը',
            'hi' => 'Բարև,',
            'body' => 'Շնորհակալություն ZULU լրահոսին գրանցվելու համար։ Որպեսզի հաստատեք բաժանորդագրությունը, սեղմեք ստորև բերված կոճակը։',
            'cta' => 'Հաստատել բաժանորդագրությունը',
            'foot' => 'Եթե դուք չեք գրանցվել, անտեսեք այս նամակը՝ ոչինչ չի փոխվի։',
        ],
        'ru' => [
            'title' => 'Подтвердите подписку',
            'hi' => 'Здравствуйте,',
            'body' => 'Спасибо за подписку на рассылку ZULU. Чтобы подтвердить подписку, нажмите кнопку ниже.',
            'cta' => 'Подтвердить подписку',
            'foot' => 'Если вы не подписывались, просто игнорируйте это письмо — ничего не произойдёт.',
        ],
        default => [
            'title' => 'Confirm your subscription',
            'hi' => 'Hi,',
            'body' => "Thanks for subscribing to the ZULU newsletter. To confirm your subscription, click the button below.",
            'cta' => 'Confirm subscription',
            'foot' => "If you didn't subscribe, just ignore this email — nothing will happen.",
        ],
    };
@endphp

@section('title', $copy['title'])

@section('content')
    <h1 style="margin:0 0 16px;font-size:20px;color:#1A3C6E;">{{ $copy['title'] }}</h1>

    <p style="margin:0 0 16px;">{{ $copy['hi'] }}</p>

    <p style="margin:0 0 16px;">{{ $copy['body'] }}</p>

    <p style="margin:24px 0;">
        <a href="{{ $confirmUrl }}"
           style="background-color:#923081;color:#ffffff;padding:12px 20px;text-decoration:none;border-radius:6px;display:inline-block;font-weight:600;">
            {{ $copy['cta'] }}
        </a>
    </p>

    <p style="margin:0 0 16px;font-size:13px;color:#555;">
        {{ $copy['foot'] }}
    </p>
@endsection
