<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Invoice statement — {{ $companyName }} — {{ $month }}</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #1F2937; margin: 0; padding: 24px; }
  h1 { font-size: 18px; margin: 0 0 6px 0; color: #631351; }
  .meta { color: #6B7280; font-size: 11px; margin-bottom: 18px; }
  .summary {
    border: 1px solid #E5E7EB;
    border-radius: 6px;
    padding: 12px 14px;
    margin-bottom: 16px;
    display: table;
    width: 100%;
  }
  .summary .cell {
    display: table-cell;
    padding: 4px 12px;
    border-right: 1px solid #E5E7EB;
  }
  .summary .cell:last-child { border-right: none; }
  .summary .label { color: #6B7280; text-transform: uppercase; font-size: 9px; letter-spacing: 0.4px; }
  .summary .value { font-size: 14px; font-weight: 600; color: #1F2937; margin-top: 2px; }
  table { width: 100%; border-collapse: collapse; margin-top: 8px; }
  th { background: #F9FAFB; color: #6B7280; text-transform: uppercase; font-size: 9px; letter-spacing: 0.4px; padding: 8px 10px; text-align: left; border-bottom: 1px solid #E5E7EB; }
  td { padding: 8px 10px; border-bottom: 1px solid #F3F4F6; }
  td.r { text-align: right; }
  td.mono { font-family: DejaVu Sans Mono, Courier New, monospace; font-size: 10px; color: #4B5563; }
  .status { display: inline-block; padding: 2px 6px; border-radius: 3px; font-size: 9px; font-weight: 600; text-transform: uppercase; }
  .status.paid { background: #E8F6EA; color: #1F5F25; }
  .status.issued { background: #E2F1FD; color: #145EBE; }
  .status.cancelled { background: #FCEDF0; color: #8A2426; }
  .status.pending { background: #FFF8E1; color: #B07000; }
  .footer { margin-top: 24px; font-size: 9px; color: #9CA3AF; text-align: center; border-top: 1px solid #E5E7EB; padding-top: 10px; }
</style>
</head>
<body>
<h1>Invoice statement</h1>
<div class="meta">
  <strong>{{ $companyName }}</strong>
  &nbsp;·&nbsp;
  Period: {{ $month }}
  &nbsp;·&nbsp;
  Generated: {{ $generatedAt->format('Y-m-d H:i') }}
</div>

<div class="summary">
  <div class="cell">
    <div class="label">Invoices</div>
    <div class="value">{{ $invoiceCount }}</div>
  </div>
  @foreach ($totalsByCurrency as $currency => $total)
    <div class="cell">
      <div class="label">Total {{ $currency }}</div>
      <div class="value">{{ number_format($total, 2) }}</div>
    </div>
  @endforeach
</div>

<table>
  <thead>
    <tr>
      <th>#</th>
      <th>Reference</th>
      <th>Order</th>
      <th>Status</th>
      <th>Issued</th>
      <th>Paid</th>
      <th class="r">Amount</th>
    </tr>
  </thead>
  <tbody>
    @forelse ($invoices as $i => $inv)
      <tr>
        <td class="mono">{{ $inv->id }}</td>
        <td class="mono">{{ $inv->unique_booking_reference ?? '—' }}</td>
        <td class="mono">{{ $inv->order_id ? substr((string) $inv->order_id, 0, 8) . '…' : '—' }}</td>
        <td><span class="status {{ $inv->status }}">{{ $inv->status }}</span></td>
        <td>{{ $inv->issued_at ? $inv->issued_at->format('Y-m-d') : '—' }}</td>
        <td>{{ $inv->paid_at ? $inv->paid_at->format('Y-m-d') : '—' }}</td>
        <td class="r">{{ number_format((float) $inv->total_amount, 2) }} {{ $inv->currency }}</td>
      </tr>
    @empty
      <tr><td colspan="7" style="text-align:center; color:#9CA3AF; padding: 20px;">No invoices in this period.</td></tr>
    @endforelse
  </tbody>
</table>

<div class="footer">
  ZULU Platform — automated statement. For questions contact your account manager.
</div>
</body>
</html>
