<?php

return [
    'api' => [
        'version' => 'v1',
    ],

    /**
     * Discovery: canonical JSON paths are /api/v1/discovery/* (see routes/api.php).
     * /api/discovery/* remains supported; optional Deprecation/Sunset on that alias when date is set.
     */
    'discovery' => [
        'unversioned_sunset_http_date' => env('DISCOVERY_UNVERSIONED_SUNSET'),
    ],

    'mobile' => [
        'ready_alignment' => true,
    ],
];
