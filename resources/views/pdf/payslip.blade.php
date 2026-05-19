<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Payslip #{{ $row->id }} — ZULU</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; line-height: 1.45; color: #222; margin: 24px; }
        .header-row { width: 100%; margin-bottom: 20px; overflow: hidden; }
        .brand { float: left; font-size: 22px; font-weight: bold; color: #6C2BD9; }
        .title-block { float: right; text-align: right; }
        .title-block h1 { margin: 0; font-size: 20px; letter-spacing: 1px; color: #6C2BD9; }
        .meta-grid { width: 100%; margin: 14px 0; border-collapse: collapse; }
        .meta-grid td { padding: 4px 8px 4px 0; vertical-align: top; }
        .meta-label { color: #555; width: 130px; }
        .employee-box { border: 1px solid #ddd; padding: 12px 14px; margin: 16px 0; background: #f8fafc; }
        .employee-box strong { color: #6C2BD9; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 4px; font-weight: bold; font-size: 10px; text-transform: uppercase; }
        .badge-draft { background: #e5e7eb; color: #374151; }
        .badge-finalized { background: #dbeafe; color: #1e40af; }
        .badge-paid { background: #d1fae5; color: #065f46; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 16px; }
        table.items th { background: #6C2BD9; color: #fff; padding: 8px; text-align: left; font-size: 10px; }
        table.items td { padding: 8px; border: 1px solid #ddd; }
        table.items td.num { text-align: right; }
        table.totals { width: 320px; margin-left: auto; margin-top: 12px; border-collapse: collapse; }
        table.totals td { padding: 6px 8px; border: none; }
        table.totals td.num { text-align: right; }
        .net-bold { font-weight: bold; font-size: 14px; border-top: 2px solid #6C2BD9 !important; color: #6C2BD9; }
        .footer { margin-top: 36px; padding-top: 12px; border-top: 1px solid #ccc; text-align: center; font-size: 9px; color: #666; }
    </style>
</head>
<body>
    @php
        $currency = $row->currency ?? '';
        $statusBadge = [
            'draft' => ['badge-draft', 'Draft'],
            'finalized' => ['badge-finalized', 'Finalized'],
            'paid' => ['badge-paid', 'Paid'],
        ][$row->status] ?? ['badge-draft', ucfirst($row->status)];
    @endphp

    <div class="header-row">
        <div class="brand">ZULU</div>
        <div class="title-block">
            <h1>PAYSLIP</h1>
        </div>
        <div style="clear:both;"></div>
    </div>

    <div class="employee-box">
        <strong>{{ $row->user?->name ?? 'Employee' }}</strong><br>
        @if($row->user?->email){{ $row->user->email }}<br>@endif
        @if($row->company?->name)<span style="color:#666;">Company:</span> {{ $row->company->name }}@endif
    </div>

    <table class="meta-grid">
        <tr>
            <td class="meta-label">Payslip #</td>
            <td><strong>{{ $row->id }}</strong></td>
            <td class="meta-label">Status</td>
            <td><span class="badge {{ $statusBadge[0] }}">{{ $statusBadge[1] }}</span></td>
        </tr>
        <tr>
            <td class="meta-label">Pay period</td>
            <td colspan="3">{{ $row->period_start?->format('Y-m-d') }} → {{ $row->period_end?->format('Y-m-d') }}</td>
        </tr>
        @if($row->paid_at)
        <tr>
            <td class="meta-label">Paid on</td>
            <td colspan="3">{{ $row->paid_at->format('Y-m-d') }}</td>
        </tr>
        @endif
        <tr>
            <td class="meta-label">Issued</td>
            <td colspan="3">{{ $row->created_at?->format('Y-m-d') ?? '—' }}</td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>Earnings &amp; deductions</th>
                <th style="width:140px;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @if((float) $row->base_salary > 0)
            <tr>
                <td>Base salary</td>
                <td class="num">{{ number_format((float) $row->base_salary, 2) }} {{ $currency }}</td>
            </tr>
            @endif
            @if((float) $row->hours_worked > 0 && (float) $row->hourly_rate > 0)
            <tr>
                <td>Hours worked ({{ number_format((float) $row->hours_worked, 2) }} × {{ number_format((float) $row->hourly_rate, 2) }} {{ $currency }})</td>
                <td class="num">{{ number_format((float) $row->hours_worked * (float) $row->hourly_rate, 2) }} {{ $currency }}</td>
            </tr>
            @endif
            @if((float) $row->commission_amount > 0)
            <tr>
                <td>Commission</td>
                <td class="num">{{ number_format((float) $row->commission_amount, 2) }} {{ $currency }}</td>
            </tr>
            @endif
            @if((float) $row->bonus_amount > 0)
            <tr>
                <td>Bonus</td>
                <td class="num">{{ number_format((float) $row->bonus_amount, 2) }} {{ $currency }}</td>
            </tr>
            @endif
            @if((float) $row->deductions_amount > 0)
            <tr>
                <td style="color:#991b1b;">Deductions</td>
                <td class="num" style="color:#991b1b;">− {{ number_format((float) $row->deductions_amount, 2) }} {{ $currency }}</td>
            </tr>
            @endif
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td>Gross pay</td>
            <td class="num">{{ number_format((float) $row->gross_pay, 2) }} {{ $currency }}</td>
        </tr>
        <tr>
            <td>Total deductions</td>
            <td class="num">{{ number_format((float) $row->deductions_amount, 2) }} {{ $currency }}</td>
        </tr>
        <tr class="net-bold">
            <td>Net pay</td>
            <td class="num">{{ number_format((float) $row->net_pay, 2) }} {{ $currency }}</td>
        </tr>
    </table>

    @if($row->notes)
    <div style="margin-top: 22px; padding: 10px 12px; background: #f9fafb; border-left: 3px solid #6C2BD9; font-size: 10px; color: #555;">
        <strong>Notes:</strong> {{ $row->notes }}
    </div>
    @endif

    <div class="footer">
        ZULU Payroll | Generated {{ now()->format('Y-m-d H:i') }} UTC
    </div>
</body>
</html>
