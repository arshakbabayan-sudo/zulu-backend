<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Confirm your ZULU email</title>
</head>
<body style="font-family:Arial,Helvetica,sans-serif;color:#222;line-height:1.5;max-width:600px;margin:24px auto;padding:0 16px;">
    <h2 style="color:#1A3C6E;margin-bottom:8px;">Welcome to ZULU</h2>

    <p>Hello {{ $user->name }},</p>

    <p>
        Thanks for signing up. To finish setting up your account, enter this
        6-digit code on the confirmation page. It expires in
        <strong>10 minutes</strong>.
    </p>

    <div style="margin:32px 0;text-align:center;">
        <span style="display:inline-block;font-family:'Courier New',monospace;font-size:36px;letter-spacing:0.4em;color:#7c3aed;font-weight:700;background:#f5f3ff;border-radius:8px;padding:16px 28px;">{{ $code }}</span>
    </div>

    <p style="color:#555;font-size:14px;">
        If you didn't create a ZULU account just now, you can safely ignore
        this email — no further action is needed.
    </p>

    <hr style="border:none;border-top:1px solid #eee;margin:32px 0;">
    <p style="color:#999;font-size:12px;">
        ZULU · Travel platform · <a href="https://zulu.am" style="color:#999;">zulu.am</a>
    </p>
</body>
</html>
