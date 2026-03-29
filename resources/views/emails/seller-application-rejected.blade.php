<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Seller Application Rejected</title>
</head>
<body>
    <p>Hello {{ $company->name ?? 'Partner' }},</p>

    <p>Your seller permission request has been rejected.</p>
    <p>Service type: <strong>{{ $application->service_type }}</strong></p>

    @if(!empty($application->rejection_reason))
        <p>Reason: {{ $application->rejection_reason }}</p>
    @endif

    <p>ZULU Platform Team</p>
</body>
</html>
