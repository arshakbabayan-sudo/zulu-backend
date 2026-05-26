<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>You've been invited — ZULU SPIN</title>
</head>
<body style="font-family:Arial,Helvetica,sans-serif;color:#222;line-height:1.5;max-width:600px;margin:24px auto;padding:0 16px;">
    <h2 style="color:#1A3C6E;">Welcome to ZULU SPIN</h2>

    <p>Hello {{ $user->name }},</p>

    <p>
        @if ($invitedBy)
            <strong>{{ $invitedBy->name }}</strong> ({{ $invitedBy->email }}) has invited you
        @else
            You've been invited
        @endif
        to join <strong>{{ $company->name }}</strong> on ZULU SPIN as a
        <strong>{{ str_replace('_', ' ', $role->name) }}</strong>.
    </p>

    <p>
        To accept the invitation and set your password, click the link below.
        The link is valid for 7 days (until {{ $expiresAt->format('Y-m-d H:i') }} UTC).
    </p>

    <p style="margin:32px 0;">
        <a href="{{ $acceptUrl }}"
           style="background:#7c3aed;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:600;display:inline-block;">
            Accept invitation
        </a>
    </p>

    <p style="color:#666;font-size:13px;">
        If the button doesn't work, copy this link into your browser:<br>
        <a href="{{ $acceptUrl }}" style="color:#7c3aed;word-break:break-all;">{{ $acceptUrl }}</a>
    </p>

    <hr style="border:none;border-top:1px solid #e5e5e5;margin:32px 0;">

    <p style="color:#666;font-size:12px;">
        If you weren't expecting this invitation, you can safely ignore this email — the
        invitation will expire automatically.
    </p>

    <p style="color:#999;font-size:12px;">ZULU SPIN — Travel marketplace platform</p>
</body>
</html>
