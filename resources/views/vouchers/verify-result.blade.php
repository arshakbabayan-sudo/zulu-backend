<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('voucher.verify_page.title') }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 24px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f5f6fa; color: #222; }
        .card { max-width: 480px; margin: 40px auto; background: #fff; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,.08); overflow: hidden; }
        .header { padding: 18px 22px; background: #1f4e8c; color: #fff; }
        .brand { font-size: 20px; font-weight: 700; }
        .subtitle { font-size: 11px; opacity: .8; margin-top: 2px; }
        .result { padding: 28px 22px; text-align: center; border-bottom: 1px solid #eee; }
        .result-valid { background: #d4edda; color: #155724; }
        .result-used { background: #d1ecf1; color: #0c5460; }
        .result-void, .result-invalid { background: #f8d7da; color: #721c24; }
        .result-expired { background: #fff3cd; color: #856404; }
        .result-icon { font-size: 36px; line-height: 1; margin-bottom: 6px; }
        .result-headline { font-size: 18px; font-weight: 700; margin: 6px 0; }
        .result-message { font-size: 13px; opacity: .85; }
        .meta { padding: 18px 22px; font-size: 13px; }
        .row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px dashed #eee; }
        .row:last-child { border-bottom: none; }
        .row .label { color: #777; }
        .row .value { font-weight: 600; }
        .footer { padding: 14px 22px; font-size: 11px; color: #999; text-align: center; }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            <div class="brand">ZULU</div>
            <div class="subtitle">{{ __('voucher.verify_page.title') }}</div>
        </div>

        <div class="result result-{{ $result }}">
            <div class="result-icon">
                @switch($result)
                    @case('valid') ✓ @break
                    @case('used') ⓘ @break
                    @case('void') ✕ @break
                    @case('expired') ⏱ @break
                    @default ?
                @endswitch
            </div>
            <div class="result-headline">{{ __('voucher.verify_page.result_'.$result) }}</div>
            <div class="result-message">{{ __('voucher.verify_page.message_'.$result) }}</div>
        </div>

        @if($voucher !== null)
            <div class="meta">
                <div class="row">
                    <span class="label">{{ __('voucher.voucher_number') }}</span>
                    <span class="value">{{ $voucher->voucher_number }}</span>
                </div>
                <div class="row">
                    <span class="label">{{ __('voucher.service_type.'.$voucher->service_type) }}</span>
                    <span class="value">{{ $voucher->holder_name }}</span>
                </div>
                @if($voucher->valid_from)
                    <div class="row">
                        <span class="label">{{ __('voucher.valid_from') }}</span>
                        <span class="value">{{ $voucher->valid_from->format('Y-m-d') }}</span>
                    </div>
                @endif
                @if($voucher->valid_to)
                    <div class="row">
                        <span class="label">{{ __('voucher.valid_to') }}</span>
                        <span class="value">{{ $voucher->valid_to->format('Y-m-d') }}</span>
                    </div>
                @endif
                <div class="row">
                    <span class="label">{{ __('voucher.verify_page.scan_count') }}</span>
                    <span class="value">{{ $voucher->verification_count }}</span>
                </div>
            </div>
        @endif

        <div class="footer">ZULU · zulu.am</div>
    </div>
</body>
</html>
