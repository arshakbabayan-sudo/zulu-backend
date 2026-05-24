<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subjectText }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f4f5;font-family:Arial,Helvetica,sans-serif;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;color:#1f2937;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f4f5;">
        <tr>
            <td align="center" style="padding:24px 16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;">
                    {{-- Brand header (ZULU purple) --}}
                    <tr>
                        <td style="background-color:#6D28D9;border-radius:8px 8px 0 0;padding:24px 28px;color:#ffffff;text-align:left;">
                            <div style="font-size:22px;font-weight:700;letter-spacing:0.3px;">ZULU</div>
                            <div style="font-size:13px;opacity:0.85;margin-top:2px;">Travel, simplified.</div>
                        </td>
                    </tr>

                    {{-- Body card --}}
                    <tr>
                        <td style="background-color:#ffffff;padding:28px;border-radius:0 0 8px 8px;box-shadow:0 1px 3px rgba(0,0,0,0.08);">
                            <h1 style="font-size:18px;margin:0 0 12px;color:#111827;line-height:1.35;">{{ $subjectText }}</h1>

                            <div style="font-size:14px;line-height:1.6;color:#374151;white-space:pre-wrap;">{{ $bodyText }}</div>

                            @if($priority === 'high' || $priority === 'critical')
                                <p style="margin:18px 0 0;padding:10px 14px;background:#fef3c7;border-left:3px solid #f59e0b;font-size:13px;color:#92400e;">
                                    {{ strtoupper($priority) }} — please review promptly.
                                </p>
                            @endif

                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top:24px;">
                                <tr>
                                    <td style="background-color:#6D28D9;border-radius:6px;">
                                        <a href="{{ $ctaUrl }}" style="display:inline-block;padding:10px 20px;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;">
                                            View on Zulu
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td align="center" style="padding-top:20px;font-size:12px;line-height:1.5;color:#6b7280;">
                            © {{ date('Y') }} ZULU. All rights reserved.<br>
                            <a href="{{ $ctaUrl }}" style="color:#6D28D9;text-decoration:none;">zulu.am</a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
