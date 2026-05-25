<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice reminder</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #FAFBFC; color: #161343; margin: 0; padding: 24px; }
        .container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
        .header { background: #8A2B79; color: #ffffff; padding: 24px; text-align: center; }
        .header h1 { margin: 0; font-size: 22px; letter-spacing: 1px; }
        .body { padding: 28px 32px; line-height: 1.6; }
        .body p { margin: 0 0 14px; font-size: 14px; }
        .amount { background: #F2E6F0; border: 2px solid #8A2B79; padding: 16px; border-radius: 8px; text-align: center; margin: 20px 0; }
        .amount .label { font-size: 11px; text-transform: uppercase; color: #717689; letter-spacing: 0.5px; }
        .amount .value { font-size: 24px; font-weight: 700; color: #8A2B79; margin-top: 4px; }
        .meta { background: #FAFBFC; padding: 14px 18px; border-radius: 6px; margin: 16px 0; font-size: 13px; }
        .meta div { padding: 4px 0; }
        .meta strong { color: #8A2B79; }
        .footer { padding: 20px; background: #FAFBFC; text-align: center; font-size: 11px; color: #717689; }
        .cta { display: inline-block; background: #8A2B79; color: #ffffff; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: 600; margin: 16px 0; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>ZULU</h1>
        <div style="font-size:11px;opacity:0.85;margin-top:4px;">Invoice reminder</div>
    </div>
    <div class="body">
        <p>Hello,</p>
        <p>This is a friendly reminder that the following invoice is awaiting payment:</p>

        <div class="amount">
            <div class="label">Amount due</div>
            <div class="value">{{ number_format($totalAmount, 2) }} {{ $currency }}</div>
        </div>

        <div class="meta">
            <div><strong>Invoice #:</strong> INV-{{ str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT) }}</div>
            @if($reference)
            <div><strong>Booking ref:</strong> {{ $reference }}</div>
            @endif
            <div><strong>Issued:</strong> {{ $invoice->created_at?->format('Y-m-d') ?? '—' }}</div>
        </div>

        <p>Please settle the outstanding amount at your earliest convenience to avoid late fees.</p>

        <p style="text-align:center;">
            <a href="{{ config('app.frontend_url', 'https://zulu.am') }}/account/orders" class="cta">View invoice</a>
        </p>

        <p style="font-size:12px;color:#717689;">If you have already paid this invoice, please disregard this message.</p>
    </div>
    <div class="footer">
        Sent by ZULU on {{ now()->format('Y-m-d H:i') }} · Need help? Contact support.
    </div>
</div>
</body>
</html>
