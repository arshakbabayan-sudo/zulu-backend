<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Seller Application Approved</title>
</head>
<body>
    <p>Hello {{ $company->name ?? 'Partner' }},</p>

    <p>Your seller permission request has been approved.</p>
    <p>Approved service type: <strong>{{ $application->service_type }}</strong></p>

    <p>ZULU Platform Team</p>
</body>
</html>
