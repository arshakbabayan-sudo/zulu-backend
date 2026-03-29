<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Application Rejected</title>
</head>
<body>
    <p>Hello {{ $application->contact_person ?: $application->company_name }},</p>

    <p>Your company application for {{ $application->company_name }} was not approved this time.</p>

    @if(!empty($application->rejection_reason))
        <p>Reason: {{ $application->rejection_reason }}</p>
    @endif

    <p>ZULU Platform Team</p>
</body>
</html>
