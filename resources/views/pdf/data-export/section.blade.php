<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $sectionTitle }}</title>
    <style>
        @page { margin: 24mm 18mm; }
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            color: #2B2C34;
            font-size: 11px;
            line-height: 1.5;
        }
        h1 {
            color: #923081;
            font-size: 22px;
            margin: 0 0 4px;
        }
        .subtitle {
            color: #7C7F8B;
            font-size: 12px;
            margin: 0 0 18px;
        }
        .meta {
            background: #F5EFF5;
            border-left: 3px solid #923081;
            padding: 10px 14px;
            border-radius: 4px;
            font-size: 11px;
            color: #2B2C34;
            margin-bottom: 18px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        thead th {
            background: #923081;
            color: #fff;
            font-weight: 600;
            text-align: left;
            padding: 7px 9px;
            font-size: 11px;
        }
        tbody td {
            padding: 7px 9px;
            border-bottom: 1px solid #E5E5E5;
            vertical-align: top;
            word-break: break-word;
        }
        tbody tr:nth-child(even) td {
            background: #FAFAFA;
        }
        .kv-table th {
            background: #F5EFF5;
            color: #2B2C34;
            font-weight: 600;
            width: 32%;
        }
        .empty {
            color: #7C7F8B;
            font-style: italic;
            padding: 18px 0;
            text-align: center;
        }
        .footer {
            position: fixed;
            bottom: -12mm;
            left: 0;
            right: 0;
            text-align: center;
            color: #7C7F8B;
            font-size: 9px;
        }
    </style>
</head>
<body>
    <h1>{{ $sectionTitle }}</h1>
    <div class="subtitle">{{ $sectionSubtitle }}</div>

    <div class="meta">
        <strong>{{ $userName ?: 'ZULU account' }}</strong>
        @if($userEmail) — {{ $userEmail }}@endif<br>
        {{ __('Generated') }}: {{ $generatedAt }}
    </div>

    @if($isKeyValue)
        @if(empty($rows))
            <div class="empty">No data available.</div>
        @else
            <table class="kv-table">
                <tbody>
                    @foreach($rows as $label => $value)
                        <tr>
                            <th>{{ $label }}</th>
                            <td>{!! $value !== null && $value !== '' ? e($value) : '<span style="color:#bbb;">—</span>' !!}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @else
        @if(empty($rows))
            <div class="empty">No records.</div>
        @else
            <table>
                <thead>
                    <tr>
                        @foreach($columns as $colLabel)
                            <th>{{ $colLabel }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                        <tr>
                            @foreach($columns as $colKey => $colLabel)
                                <td>{!! ($row[$colKey] ?? null) !== null && ($row[$colKey] ?? '') !== '' ? e($row[$colKey]) : '<span style="color:#bbb;">—</span>' !!}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endif

    <div class="footer">ZULU — Personal data export · {{ $generatedAt }}</div>
</body>
</html>
