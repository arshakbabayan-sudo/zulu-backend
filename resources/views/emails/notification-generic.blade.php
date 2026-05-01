<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ $notification->title }}</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #222; max-width: 640px; margin: 0 auto; padding: 16px;">
    <h2 style="color: #1f4e8c; margin-bottom: 12px;">{{ $notification->title }}</h2>

    <div style="font-size: 14px; line-height: 1.5; white-space: pre-wrap;">{{ $notification->message }}</div>

    @if($notification->priority === 'critical' || $notification->priority === 'high')
        <p style="margin-top: 18px; padding: 10px 14px; background: #fff3cd; border-left: 3px solid #f0ad4e; font-size: 13px;">
            {{ strtoupper($notification->priority) }} — please review promptly.
        </p>
    @endif

    <p style="margin-top: 24px; padding-top: 12px; border-top: 1px solid #ddd; font-size: 11px; color: #888;">
        ZULU · zulu.am
    </p>
</body>
</html>
