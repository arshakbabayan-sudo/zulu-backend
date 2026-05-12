<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Supplier APIs per service type
    |--------------------------------------------------------------------------
    |
    | Each service type can be wired to a real supplier API via env vars.
    | If credentials are missing for a service type, the saga reserver
    | falls back to StubComponentReserver (always-succeeds with a
    | synthetic supplier_ref), so dev + tests keep working with no
    | upstream dependency.
    |
    | The generic SupplierApiReserver expects a REST endpoint exposing
    | /reserve, /confirm, /rollback. Specialized reservers (e.g.
    | AmadeusFlightReserver) override this with the vendor-native shape.
    |
    */

    'flight' => [
        // Generic supplier API (used when amadeus.* is not configured
        // but supplier.flight.endpoint is).
        'endpoint' => env('SUPPLIER_FLIGHT_ENDPOINT', ''),
        'api_key' => env('SUPPLIER_FLIGHT_API_KEY', ''),
        'timeout' => env('SUPPLIER_FLIGHT_TIMEOUT', 15),

        // Amadeus PNR. AmadeusFlightReserver picks these up; takes
        // precedence over the generic endpoint when configured.
        'amadeus' => [
            'base_url' => env('AMADEUS_BASE_URL', ''),
            'api_key' => env('AMADEUS_API_KEY', ''),
            'api_secret' => env('AMADEUS_API_SECRET', ''),
            'requires_ticket_call' => env('AMADEUS_REQUIRES_TICKET_CALL', false),
        ],
    ],

    'hotel' => [
        'endpoint' => env('SUPPLIER_HOTEL_ENDPOINT', ''),
        'api_key' => env('SUPPLIER_HOTEL_API_KEY', ''),
        'timeout' => env('SUPPLIER_HOTEL_TIMEOUT', 15),
    ],

    'transfer' => [
        'endpoint' => env('SUPPLIER_TRANSFER_ENDPOINT', ''),
        'api_key' => env('SUPPLIER_TRANSFER_API_KEY', ''),
        'timeout' => env('SUPPLIER_TRANSFER_TIMEOUT', 15),
    ],

    'car' => [
        'endpoint' => env('SUPPLIER_CAR_ENDPOINT', ''),
        'api_key' => env('SUPPLIER_CAR_API_KEY', ''),
        'timeout' => env('SUPPLIER_CAR_TIMEOUT', 15),
    ],

    'excursion' => [
        'endpoint' => env('SUPPLIER_EXCURSION_ENDPOINT', ''),
        'api_key' => env('SUPPLIER_EXCURSION_API_KEY', ''),
        'timeout' => env('SUPPLIER_EXCURSION_TIMEOUT', 15),
    ],

    'visa' => [
        'endpoint' => env('SUPPLIER_VISA_ENDPOINT', ''),
        'api_key' => env('SUPPLIER_VISA_API_KEY', ''),
        'timeout' => env('SUPPLIER_VISA_TIMEOUT', 30),
    ],

    'insurance' => [
        'endpoint' => env('SUPPLIER_INSURANCE_ENDPOINT', ''),
        'api_key' => env('SUPPLIER_INSURANCE_API_KEY', ''),
        'timeout' => env('SUPPLIER_INSURANCE_TIMEOUT', 15),
    ],
];
