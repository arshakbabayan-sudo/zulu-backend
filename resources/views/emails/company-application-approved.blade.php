<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Application Approved</title>
</head>
<body>
    <p>Hello {{ $application->contact_person ?: $application->company_name }},</p>

    <p>Your company application for {{ $application->company_name }} has been approved.</p>

    <p>You can sign in at: <a href="{{ url('/admin/login') }}">{{ url('/admin/login') }}</a></p>
    <p>Temporary password: <strong>{{ $temporaryPassword }}</strong></p>

    <p>For login, use email: {{ $user->email }}</p>

    <p>ZULU Platform Team</p>
</body>
</html>
