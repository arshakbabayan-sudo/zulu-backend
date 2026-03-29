<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice #{{ $invoice->id }} — ZULU SPIN</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; line-height: 1.45; color: #222; margin: 24px; }
        .header-row { width: 100%; margin-bottom: 20px; overflow: hidden; }
        .brand { float: left; font-size: 22px; font-weight: bold; color: #1A3C6E; }
        .title-block { float: right; text-align: right; }
        .title-block h1 { margin: 0; font-size: 20px; letter-spacing: 1px; color: #1A3C6E; }
        .company-box { border: 1px solid #ccc; padding: 12px 14px; margin: 16px 0; background: #f8fafc; }
        .company-box strong { color: #1A3C6E; }
        .meta-grid { width: 100%; margin: 14px 0; border-collapse: collapse; }
        .meta-grid td { padding: 4px 8px 4px 0; vertical-align: top; }
        .meta-label { color: #555; width: 120px; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 4px; font-weight: bold; font-size: 10px; text-transform: uppercase; }
        .badge-paid { background: #d1fae5; color: #065f46; }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-cancelled { background: #fee2e2; color: #991b1b; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 16px; }
        table.items th { background: #1A3C6E; color: #fff; padding: 8px; text-align: left; font-size: 10px; }
        table.items td { padding: 8px; border: 1px solid #ddd; }
        table.items td.num { text-align: right; }
        table.totals { width: 280px; margin-left: auto; margin-top: 12px; border-collapse: collapse; }
        table.totals td { padding: 6px 8px; border: none; }
        table.totals td.num { text-align: right; }
        .total-bold { font-weight: bold; font-size: 12px; border-top: 2px solid #1A3C6E !important; }
        .footer { margin-top: 36px; padding-top: 12px; border-top: 1px solid #ccc; text-align: center; font-size: 9px; color: #666; }
    </style>
</head>
<body>
    @php
        $booking = $invoice->booking;
        $company = $booking?->company;
        $items = $booking?->items ?? collect();
        $subtotal = $items->sum(fn ($i) => (float) $i->price);
        $currency = $invoice->currency ?? '';
        $ref = $invoice->unique_booking_reference ?? ($booking ? (string) $booking->id : '—');
        $status = $invoice->status;
        if ($status === \App\Models\Invoice::STATUS_PAID) {
            $badgeClass = 'badge-paid';
            $badgeLabel = 'Paid';
        } elseif ($status === \App\Models\Invoice::STATUS_CANCELLED) {
            $badgeClass = 'badge-cancelled';
            $badgeLabel = 'Cancelled';
        } else {
            $badgeClass = 'badge-pending';
            $badgeLabel = 'Pending';
        }
    @endphp

    <div class="header-row">
        <div class="brand">ZULU SPIN</div>
        <div class="title-block">
            <h1>INVOICE</h1>
        </div>
        <div style="clear:both;"></div>
    </div>

    @if($company)
    <div class="company-box">
        <strong>{{ $company->name }}</strong><br>
        @if($company->address){{ $company->address }}<br>@endif
        @if($company->tax_id)Tax ID: {{ $company->tax_id }}<br>@endif
        @if($company->phone)Phone: {{ $company->phone }}@endif
    </div>
    @endif

    <table class="meta-grid">
        <tr>
            <td class="meta-label">Invoice #</td>
            <td><strong>{{ $invoice->id }}</strong></td>
            <td class="meta-label">Status</td>
            <td><span class="badge {{ $badgeClass }}">{{ $badgeLabel }}</span></td>
        </tr>
        <tr>
            <td class="meta-label">Date</td>
            <td>{{ $invoice->created_at?->format('Y-m-d') ?? '—' }}</td>
            <td class="meta-label">Due date</td>
            <td>{{ $dueDate?->format('Y-m-d') ?? '—' }}</td>
        </tr>
        <tr>
            <td class="meta-label">Booking reference</td>
            <td colspan="3">{{ $ref }}</td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>Description</th>
                <th style="width:70px;">Quantity</th>
                <th style="width:90px;">Unit Price</th>
                <th style="width:90px;">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $item)
                @php $price = (float) $item->price; @endphp
                <tr>
                    <td>Travel Service — Offer #{{ $item->offer_id }}</td>
                    <td class="num">1</td>
                    <td class="num">{{ number_format($price, 2) }}@if($currency) {{ $currency }}@endif</td>
                    <td class="num">{{ number_format($price, 2) }}@if($currency) {{ $currency }}@endif</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4">No line items.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td>Subtotal</td>
            <td class="num">{{ number_format($subtotal, 2) }}@if($currency) {{ $currency }}@endif</td>
        </tr>
        <tr class="total-bold">
            <td>Total</td>
            <td class="num">{{ number_format((float) $invoice->total_amount, 2) }}@if($currency) {{ $currency }}@endif</td>
        </tr>
    </table>

    <div class="footer">
        Thank you for choosing ZULU SPIN | support@zuluspin.com
    </div>
</body>
</html>
