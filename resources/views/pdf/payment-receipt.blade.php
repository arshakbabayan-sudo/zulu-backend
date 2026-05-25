<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Payment receipt #{{ $payment->id }} — ZULU</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; line-height: 1.5; color: #222; margin: 24px; }
        .header-row { width: 100%; margin-bottom: 24px; overflow: hidden; }
        .brand { float: left; font-size: 22px; font-weight: bold; color: #8A2B79; }
        .title-block { float: right; text-align: right; }
        .title-block h1 { margin: 0; font-size: 20px; letter-spacing: 1px; color: #8A2B79; }
        .title-block .subtitle { font-size: 10px; color: #717689; margin-top: 4px; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 4px; font-weight: bold; font-size: 10px; text-transform: uppercase; }
        .badge-paid { background: #E8F6EA; color: #1F5F25; }
        .badge-pending { background: #FFF8E1; color: #B07000; }
        .badge-failed { background: #FCEDF0; color: #8A2426; }
        .amount-block { border: 2px solid #8A2B79; padding: 18px 20px; margin: 20px 0; background: #F2E6F0; text-align: center; }
        .amount-block .label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #717689; }
        .amount-block .value { font-size: 28px; font-weight: 700; color: #8A2B79; margin-top: 4px; }
        .meta-grid { width: 100%; margin: 16px 0; border-collapse: collapse; }
        .meta-grid td { padding: 8px 12px; border-bottom: 1px solid #E6E6E6; vertical-align: top; }
        .meta-label { color: #717689; width: 160px; font-weight: 600; font-size: 10px; text-transform: uppercase; letter-spacing: 0.3px; }
        .meta-value { color: #161343; font-size: 12px; }
        .section-title { margin-top: 28px; margin-bottom: 8px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #8A2B79; border-bottom: 1px solid #DEC1D9; padding-bottom: 4px; }
        .footer { margin-top: 40px; padding-top: 14px; border-top: 1px solid #E6E6E6; text-align: center; font-size: 9px; color: #717689; }
        .thanks { margin-top: 24px; padding: 14px; background: #FAFBFC; border-radius: 6px; text-align: center; color: #717689; font-size: 11px; }
    </style>
</head>
<body>
@php
    $invoice = $payment->invoice;
    $order = $invoice?->order;
    $company = $order?->company;
    $user = $order?->user;
    $status = $payment->status;
    if ($status === \App\Models\Payment::STATUS_PAID) {
        $badgeClass = 'badge-paid';
        $badgeLabel = 'Paid';
    } elseif ($status === \App\Models\Payment::STATUS_FAILED) {
        $badgeClass = 'badge-failed';
        $badgeLabel = 'Failed';
    } else {
        $badgeClass = 'badge-pending';
        $badgeLabel = ucfirst($status);
    }
    $method = $payment->payment_method;
    $methodLabel = $method
        ? implode(' ', array_map('ucfirst', explode('_', (string) $method)))
        : '—';
@endphp

<div class="header-row">
    <div class="brand">ZULU</div>
    <div class="title-block">
        <h1>RECEIPT</h1>
        <div class="subtitle">Payment confirmation</div>
    </div>
    <div style="clear:both;"></div>
</div>

<div class="amount-block">
    <div class="label">Amount paid</div>
    <div class="value">{{ number_format((float) $payment->amount, 2) }} {{ strtoupper($payment->currency ?? '') }}</div>
</div>

<table class="meta-grid">
    <tr>
        <td class="meta-label">Receipt #</td>
        <td class="meta-value"><strong>R-{{ str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT) }}</strong></td>
    </tr>
    <tr>
        <td class="meta-label">Status</td>
        <td class="meta-value"><span class="badge {{ $badgeClass }}">{{ $badgeLabel }}</span></td>
    </tr>
    <tr>
        <td class="meta-label">Payment date</td>
        <td class="meta-value">{{ $payment->paid_at?->format('Y-m-d H:i') ?? $payment->created_at?->format('Y-m-d H:i') ?? '—' }}</td>
    </tr>
    <tr>
        <td class="meta-label">Payment method</td>
        <td class="meta-value">{{ $methodLabel }}</td>
    </tr>
    @if($payment->reference_code)
    <tr>
        <td class="meta-label">Reference code</td>
        <td class="meta-value" style="font-family: monospace;">{{ $payment->reference_code }}</td>
    </tr>
    @endif
</table>

@if($invoice)
<div class="section-title">Linked invoice</div>
<table class="meta-grid">
    <tr>
        <td class="meta-label">Invoice #</td>
        <td class="meta-value" style="font-family: monospace;">INV-{{ str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT) }}</td>
    </tr>
    @if($invoice->unique_booking_reference)
    <tr>
        <td class="meta-label">Booking ref</td>
        <td class="meta-value" style="font-family: monospace;">{{ $invoice->unique_booking_reference }}</td>
    </tr>
    @endif
    <tr>
        <td class="meta-label">Invoice total</td>
        <td class="meta-value">{{ number_format((float) $invoice->total_amount, 2) }} {{ strtoupper($invoice->currency ?? $payment->currency ?? '') }}</td>
    </tr>
</table>
@endif

@if($company)
<div class="section-title">Operator</div>
<table class="meta-grid">
    <tr>
        <td class="meta-label">Company</td>
        <td class="meta-value"><strong>{{ $company->name }}</strong></td>
    </tr>
    @if($company->address)
    <tr>
        <td class="meta-label">Address</td>
        <td class="meta-value">{{ $company->address }}</td>
    </tr>
    @endif
    @if($company->phone)
    <tr>
        <td class="meta-label">Phone</td>
        <td class="meta-value">{{ $company->phone }}</td>
    </tr>
    @endif
</table>
@endif

@if($user)
<div class="section-title">Customer</div>
<table class="meta-grid">
    <tr>
        <td class="meta-label">Name</td>
        <td class="meta-value">{{ trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: '—' }}</td>
    </tr>
    @if($user->email)
    <tr>
        <td class="meta-label">Email</td>
        <td class="meta-value">{{ $user->email }}</td>
    </tr>
    @endif
</table>
@endif

@if($payment->status === \App\Models\Payment::STATUS_PAID)
<div class="thanks">
    Thank you for your payment. This receipt confirms that your transaction has been successfully processed.
</div>
@endif

<div class="footer">
    Generated by ZULU on {{ now()->format('Y-m-d H:i:s') }} · Receipt is computer-generated and does not require a signature.
</div>
</body>
</html>
