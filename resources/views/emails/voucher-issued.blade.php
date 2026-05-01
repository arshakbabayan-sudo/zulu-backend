@php
    $previousLocale = app()->getLocale();
    app()->setLocale($language);
@endphp
<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('voucher.email.subject', ['number' => $voucher->voucher_number]) }}</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #222; max-width: 640px; margin: 0 auto; padding: 16px;">
    <h2 style="color: #1f4e8c;">{{ __('voucher.email.greeting', ['name' => $voucher->holder_name]) }}</h2>

    <p>{{ __('voucher.email.intro') }}</p>

    <table cellpadding="6" cellspacing="0" border="0" style="background:#f7f9fc; border-left:3px solid #1f4e8c; margin:14px 0;">
        <tr>
            <td style="color:#666; font-size:12px;">{{ __('voucher.voucher_number') }}</td>
            <td style="font-weight:bold;">{{ $voucher->voucher_number }}</td>
        </tr>
        <tr>
            <td style="color:#666; font-size:12px;">{{ __('voucher.service_type.'.$voucher->service_type) }}</td>
            <td>{{ __('voucher.status.'.$voucher->status) }}</td>
        </tr>
        @if($voucher->valid_from)
            <tr>
                <td style="color:#666; font-size:12px;">{{ __('voucher.valid_from') }}</td>
                <td>{{ $voucher->valid_from->format('Y-m-d') }}</td>
            </tr>
        @endif
        @if($voucher->valid_to)
            <tr>
                <td style="color:#666; font-size:12px;">{{ __('voucher.valid_to') }}</td>
                <td>{{ $voucher->valid_to->format('Y-m-d') }}</td>
            </tr>
        @endif
    </table>

    <p>{{ __('voucher.email.attached_note') }}</p>

    <p style="margin-top: 24px; padding-top: 12px; border-top: 1px solid #ddd; font-size: 11px; color: #888;">
        {{ __('voucher.email.signature') }}<br>
        ZULU · zulu.am
    </p>
</body>
</html>
@php
    app()->setLocale($previousLocale);
@endphp
