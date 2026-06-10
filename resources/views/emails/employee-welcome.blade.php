<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Your account is ready — ZULU SPIN</title>
</head>
<body style="font-family:Arial,Helvetica,sans-serif;color:#222;line-height:1.5;max-width:600px;margin:24px auto;padding:0 16px;">
    <h2 style="color:#1A3C6E;">Welcome to ZULU SPIN</h2>

    <p>Hello {{ $user->name }},</p>

    <p>
        @if ($createdBy)
            <strong>{{ $createdBy->name }}</strong> ({{ $createdBy->email }}) has created an account for you
        @else
            An account has been created for you
        @endif
        at <strong>{{ $company->name }}</strong> on ZULU SPIN as a
        <strong>{{ str_replace('_', ' ', $role->name) }}</strong>.
    </p>

    <p>
        Your sign-in email is <strong>{{ $user->email }}</strong>. Your manager will
        share the password with you personally — it is not included in this email.
    </p>

    <p style="margin:32px 0;">
        <a href="{{ $loginUrl }}"
           style="background:#7c3aed;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:600;display:inline-block;">
            Sign in
        </a>
    </p>

    <p style="color:#666;font-size:13px;">
        If the button doesn't work, copy this link into your browser:<br>
        <a href="{{ $loginUrl }}" style="color:#7c3aed;word-break:break-all;">{{ $loginUrl }}</a>
    </p>

    <p style="color:#666;font-size:13px;">
        After signing in we recommend changing your password from your profile page.
    </p>

    <hr style="border:none;border-top:1px solid #e5e5e5;margin:32px 0;">

    <p style="color:#666;font-size:12px;">
        If you weren't expecting this account, contact your manager or simply ignore this email.
    </p>

    <p style="color:#999;font-size:12px;">ZULU SPIN — Travel marketplace platform</p>
</body>
</html>
