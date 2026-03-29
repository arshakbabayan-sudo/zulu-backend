<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Voucher {{ $orderCode }} — ZULU SPIN</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; line-height: 1.45; color: #222; margin: 24px; position: relative; min-height: 100%; }
        .reseller-header { border-bottom: 2px solid #1A3C6E; padding-bottom: 12px; margin-bottom: 18px; overflow: hidden; }
        .reseller-header img { max-height: 48px; float: left; margin-right: 12px; }
        .reseller-name { font-size: 18px; font-weight: bold; color: #1A3C6E; }
        .order-code { font-size: 22px; font-weight: bold; margin: 14px 0 6px 0; letter-spacing: 1px; }
        .purchase-date { color: #555; margin-bottom: 16px; }
        .section { margin-bottom: 18px; }
        .section-title { font-weight: bold; font-size: 12px; color: #1A3C6E; border-bottom: 1px solid #ddd; padding-bottom: 4px; margin-bottom: 8px; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th { background: #f1f5f9; padding: 6px 8px; border: 1px solid #ddd; text-align: left; font-size: 10px; }
        table.data td { padding: 6px 8px; border: 1px solid #ddd; vertical-align: top; }
        .flight-row td { padding: 4px 0; }
        .watermark { position: fixed; bottom: 18mm; right: 12mm; font-size: 9px; color: #bbb; transform: rotate(-12deg); }
        .footer { margin-top: 28px; padding-top: 10px; border-top: 1px solid #ccc; font-size: 9px; color: #555; line-height: 1.5; }
        .pay-summary td { padding: 4px 8px 4px 0; }
    </style>
</head>
<body>
    @php
        $company = $booking->company;
        $flight = $flightItem && $flightItem->offer ? $flightItem->offer->flight : null;
    @endphp

    <div class="watermark">ZULU SPIN</div>

    <div class="reseller-header">
        @if(!empty($logoDataUri))
            <img src="{{ $logoDataUri }}" alt="">
        @endif
        <div class="reseller-name">{{ $company?->name ?? 'Partner' }}</div>
        <div style="clear:both;"></div>
    </div>

    <div class="order-code">{{ $orderCode }}</div>
    <div class="purchase-date">Purchase date: {{ $booking->created_at?->format('Y-m-d H:i') ?? '—' }}</div>

    <div class="section">
        <div class="section-title">PASSENGER(S)</div>
        <table class="data">
            <thead>
                <tr>
                    <th>Full Name</th>
                    <th>Type</th>
                    <th>Passport</th>
                    <th>Nationality</th>
                    <th>DOB</th>
                </tr>
            </thead>
            <tbody>
                @forelse($booking->passengers as $p)
                    <tr>
                        <td>{{ $p->full_name }}</td>
                        <td>{{ $p->passenger_type ?? '—' }}</td>
                        <td>{{ $p->passport_number ?? '—' }}</td>
                        <td>{{ $p->nationality ?? '—' }}</td>
                        <td>{{ $p->date_of_birth?->format('Y-m-d') ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">No passengers listed.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($flight)
    <div class="section">
        <div class="section-title">FLIGHT DETAILS</div>
        <table class="data" style="border: none;">
            <tr class="flight-row">
                <td style="border:none; width: 120px; color:#555;">Route</td>
                <td style="border:none;">
                    <strong>{{ $flight->departure_city ?? $flight->departure_airport_code ?? '—' }}</strong>
                    →
                    <strong>{{ $flight->arrival_city ?? $flight->arrival_airport_code ?? '—' }}</strong>
                    @if($flight->departure_airport_code || $flight->arrival_airport_code)
                        ({{ $flight->departure_airport_code ?? '?' }} → {{ $flight->arrival_airport_code ?? '?' }})
                    @endif
                </td>
            </tr>
            <tr class="flight-row">
                <td style="border:none; color:#555;">Date</td>
                <td style="border:none;">{{ $flight->departure_at?->format('Y-m-d H:i') ?? '—' }}</td>
            </tr>
            <tr class="flight-row">
                <td style="border:none; color:#555;">Flight number</td>
                <td style="border:none;">{{ $flight->flight_code_internal ?? '—' }}</td>
            </tr>
            <tr class="flight-row">
                <td style="border:none; color:#555;">Class</td>
                <td style="border:none;">{{ $flight->cabin_class ?? '—' }}</td>
            </tr>
            <tr class="flight-row">
                <td style="border:none; color:#555;">Baggage policy</td>
                <td style="border:none;">
                    @php
                        $parts = [];
                        if ($flight->hand_baggage_included) {
                            $parts[] = 'Hand baggage included'.($flight->hand_baggage_weight ? ' ('.$flight->hand_baggage_weight.')' : '');
                        }
                        if ($flight->checked_baggage_included) {
                            $parts[] = 'Checked baggage included'.($flight->checked_baggage_weight ? ' ('.$flight->checked_baggage_weight.')' : '');
                        }
                        if ($flight->baggage_notes) {
                            $parts[] = $flight->baggage_notes;
                        }
                        $baggageText = $parts !== [] ? implode('. ', $parts) : 'See fare rules';
                    @endphp
                    {{ $baggageText }}
                </td>
            </tr>
        </table>
    </div>
    @endif

    <div class="section">
        <div class="section-title">PAYMENT SUMMARY</div>
        <table class="pay-summary">
            <tr>
                <td>Total amount</td>
                <td><strong>{{ number_format((float) $booking->total_price, 2) }}</strong></td>
            </tr>
            <tr>
                <td>Status</td>
                <td><strong>{{ $booking->status }}</strong></td>
            </tr>
        </table>
    </div>

    <div class="footer">
        This voucher is your proof of purchase. Present this document at check-in.<br>
        For support: support@zuluspin.com | +X XXX XXX XXXX
    </div>
</body>
</html>
