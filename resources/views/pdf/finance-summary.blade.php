<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Finance summary — ZULU</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; line-height: 1.5; color: #222; margin: 24px; }
        .header-row { width: 100%; margin-bottom: 24px; overflow: hidden; }
        .brand { float: left; font-size: 22px; font-weight: bold; color: #8A2B79; }
        .title-block { float: right; text-align: right; }
        .title-block h1 { margin: 0; font-size: 20px; letter-spacing: 1px; color: #8A2B79; }
        .title-block .subtitle { font-size: 10px; color: #717689; margin-top: 4px; }
        .hero-grid { width: 100%; border-collapse: separate; border-spacing: 8px 0; margin: 12px -8px 8px; }
        .hero-cell { width: 33.33%; border: 2px solid #8A2B79; background: #F2E6F0; padding: 14px 16px; text-align: center; vertical-align: top; }
        .hero-cell .label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; color: #717689; }
        .hero-cell .value { font-size: 20px; font-weight: 700; color: #8A2B79; margin-top: 4px; }
        .hero-cell .sub { font-size: 9px; color: #717689; margin-top: 6px; }
        .section-title { margin-top: 28px; margin-bottom: 8px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #8A2B79; border-bottom: 1px solid #DEC1D9; padding-bottom: 4px; }
        .data-table { width: 100%; border-collapse: collapse; margin: 8px 0; }
        .data-table th { padding: 6px 12px; border-bottom: 1px solid #DEC1D9; text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: 0.3px; color: #717689; }
        .data-table td { padding: 7px 12px; border-bottom: 1px solid #E6E6E6; font-size: 11px; color: #161343; vertical-align: top; }
        .data-table .num { text-align: right; }
        .empty-note { padding: 10px 12px; background: #FAFBFC; border-radius: 6px; color: #717689; font-size: 10px; }
        .footer { margin-top: 40px; padding-top: 14px; border-top: 1px solid #E6E6E6; text-align: center; font-size: 9px; color: #717689; }
    </style>
</head>
<body>
@php
    $totalRevenue = (float) ($summary['total_payments_paid'] ?? 0);
    $paymentsCountPaid = (int) ($summary['payments_count_paid'] ?? 0);
    $commissionAccrued = (float) ($summary['total_commission_accrued'] ?? 0);
    $split = $summary['commission_split'] ?? ['platform' => 0.0, 'agent' => 0.0];
    $pending = $summary['pending_meta'] ?? ['count' => 0, 'avg_age_days' => 0.0, 'oldest_days' => 0.0];
    $currencyBreakdown = $summary['currency_breakdown'] ?? [];
    $revenueByService = $summary['revenue_by_service'] ?? [];
@endphp

<div class="header-row">
    <div class="brand">ZULU</div>
    <div class="title-block">
        <h1>FINANCE SUMMARY</h1>
        <div class="subtitle">Platform financial overview · {{ $rangeLabel }}</div>
    </div>
    <div style="clear:both;"></div>
</div>

<table class="hero-grid">
    <tr>
        <td class="hero-cell">
            <div class="label">Total revenue</div>
            <div class="value">{{ number_format($totalRevenue, 2) }}</div>
            <div class="sub">{{ $paymentsCountPaid }} paid payment{{ $paymentsCountPaid === 1 ? '' : 's' }}</div>
        </td>
        <td class="hero-cell">
            <div class="label">Commissions accrued</div>
            <div class="value">{{ number_format($commissionAccrued, 2) }}</div>
            <div class="sub">Platform: {{ number_format((float) ($split['platform'] ?? 0), 2) }} · Agent: {{ number_format((float) ($split['agent'] ?? 0), 2) }}</div>
        </td>
        <td class="hero-cell">
            <div class="label">Pending payments</div>
            <div class="value">{{ (int) ($pending['count'] ?? 0) }}</div>
            <div class="sub">Avg. age: {{ number_format((float) ($pending['avg_age_days'] ?? 0), 1) }} days · Oldest: {{ number_format((float) ($pending['oldest_days'] ?? 0), 0) }} days</div>
        </td>
    </tr>
</table>

<div class="section-title">Currency breakdown ({{ $rangeLabel }})</div>
@if(count($currencyBreakdown) > 0)
<table class="data-table">
    <thead>
    <tr>
        <th>Currency</th>
        <th class="num">Paid amount</th>
    </tr>
    </thead>
    <tbody>
    @foreach($currencyBreakdown as $currency => $amount)
    <tr>
        <td>{{ strtoupper((string) $currency) }}</td>
        <td class="num">{{ number_format((float) $amount, 2) }}</td>
    </tr>
    @endforeach
    </tbody>
</table>
@else
<div class="empty-note">No paid payments in this range.</div>
@endif

@if(count($revenueByService) > 0)
<div class="section-title">Revenue by service ({{ $rangeLabel }})</div>
<table class="data-table">
    <thead>
    <tr>
        <th>Service</th>
        <th class="num">Amount</th>
        <th class="num">Share</th>
    </tr>
    </thead>
    <tbody>
    @foreach($revenueByService as $row)
    <tr>
        <td>{{ ucfirst((string) ($row['service'] ?? '—')) }}</td>
        <td class="num">{{ number_format((float) ($row['amount'] ?? 0), 2) }}</td>
        <td class="num">{{ number_format((float) ($row['pct'] ?? 0), 1) }}%</td>
    </tr>
    @endforeach
    </tbody>
</table>
@endif

<div class="footer">
    Generated by ZULU on {{ $generatedAt->format('Y-m-d H:i:s') }} · This summary is computer-generated and does not require a signature.
</div>
</body>
</html>
