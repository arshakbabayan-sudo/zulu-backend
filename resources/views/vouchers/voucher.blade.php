<!DOCTYPE html>
<html lang="{{ $voucher->language }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('voucher.title') }} {{ $voucher->voucher_number }}</title>
    <style>
        @page { margin: 28px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11pt; color: #222; }
        .header { border-bottom: 2px solid #1f4e8c; padding-bottom: 12px; margin-bottom: 18px; }
        .brand { font-size: 22pt; font-weight: bold; color: #1f4e8c; }
        .lang-badge { float: right; font-size: 9pt; color: #777; padding: 4px 8px; border: 1px solid #ccc; border-radius: 4px; }
        .meta { margin-top: 6px; font-size: 9pt; color: #666; }
        h1 { font-size: 16pt; color: #1f4e8c; margin: 6px 0 12px; }
        .grid { width: 100%; }
        .grid td { vertical-align: top; padding: 4px 6px; }
        .label { color: #666; font-size: 9pt; }
        .value { font-weight: bold; }
        .section { margin: 18px 0; padding: 10px 12px; background: #f7f9fc; border-left: 3px solid #1f4e8c; }
        .section h2 { font-size: 12pt; margin: 0 0 8px; color: #1f4e8c; }
        .qr-block { text-align: center; margin-top: 24px; padding: 12px; border: 1px dashed #aaa; }
        .qr-token { font-family: DejaVu Sans Mono, monospace; font-size: 8pt; color: #333; word-break: break-all; }
        .footer { margin-top: 28px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 8pt; color: #999; text-align: center; }
        .status-badge { display: inline-block; padding: 3px 10px; font-size: 9pt; border-radius: 3px; }
        .status-issued { background: #d4edda; color: #155724; }
        .status-used { background: #d1ecf1; color: #0c5460; }
        .status-void { background: #f8d7da; color: #721c24; }
        .status-reissued, .status-expired { background: #fff3cd; color: #856404; }
        ul.passenger-list { margin: 4px 0 0 16px; padding: 0; }
    </style>
</head>
<body>
    <div class="header">
        <span class="lang-badge">{{ __('voucher.language_label') }}</span>
        <div class="brand">ZULU</div>
        <div class="meta">{{ __('voucher.title') }} · {{ __('voucher.service_type.'.$voucher->service_type) }}</div>
    </div>

    <h1>{{ __('voucher.service_type.'.$voucher->service_type) }}</h1>

    <table class="grid">
        <tr>
            <td width="50%">
                <div class="label">{{ __('voucher.voucher_number') }}</div>
                <div class="value">{{ $voucher->voucher_number }}</div>
            </td>
            <td width="50%">
                <div class="label">{{ __('voucher.issued_at') }}</div>
                <div class="value">{{ $voucher->created_at?->format('Y-m-d H:i') }}</div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="label">{{ __('voucher.holder') }}</div>
                <div class="value">{{ $voucher->holder_name }}</div>
            </td>
            <td>
                <div class="label">{{ __('voucher.passport') }}</div>
                <div class="value">{{ $voucher->holder_passport ?: '—' }}</div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="label">{{ __('voucher.valid_from') }}</div>
                <div class="value">{{ $voucher->valid_from?->format('Y-m-d') ?: '—' }}</div>
            </td>
            <td>
                <div class="label">{{ __('voucher.valid_to') }}</div>
                <div class="value">{{ $voucher->valid_to?->format('Y-m-d') ?: '—' }}</div>
            </td>
        </tr>
        <tr>
            <td colspan="2">
                <span class="status-badge status-{{ $voucher->status }}">
                    {{ __('voucher.status.'.$voucher->status) }}
                </span>
            </td>
        </tr>
    </table>

    @if(is_array($voucher->passenger_data) && count($voucher->passenger_data) > 0)
        <div class="section">
            <h2>{{ __('voucher.passengers') }}</h2>
            <ul class="passenger-list">
                @foreach($voucher->passenger_data as $p)
                    <li>
                        {{ $p['name'] ?? '—' }}
                        @if(!empty($p['passport'])) ({{ $p['passport'] }})@endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if(is_array($voucher->service_snapshot) && count($voucher->service_snapshot) > 0)
        <div class="section">
            <h2>{{ __('voucher.service_details') }}</h2>
            <table class="grid">
                @foreach($voucher->service_snapshot as $key => $val)
                    @if(is_scalar($val))
                        <tr>
                            <td width="35%" class="label">{{ str_replace('_', ' ', ucfirst((string) $key)) }}</td>
                            <td class="value">{{ $val }}</td>
                        </tr>
                    @endif
                @endforeach
            </table>
        </div>
    @endif

    <div class="qr-block">
        <div class="label">{{ __('voucher.verify') }}</div>
        <div style="margin: 6px 0; font-size: 9pt;">{{ __('voucher.verify_instructions') }}</div>
        <div class="qr-token">{{ $verifyUrl }}</div>
    </div>

    <div class="footer">
        {{ __('voucher.footer') }}
    </div>
</body>
</html>
