<?php

return [
    'type' => 'laravel',

    'title' => 'ZULU Platform API',

    'description' => 'Inventory management API for ZULU: Flights, Hotels, Transfers, Cars, Excursions, Offers.',

    'base_url' => env('APP_URL', 'http://localhost'),

    'routes' => [
        [
            'match' => [
                'prefixes' => ['api/*'],
                'domains'  => ['*'],
            ],
            'include' => [],
            'exclude' => [],
            'apply'   => [
                'headers' => [
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer {YOUR_AUTH_TOKEN}',
                ],
                'response_calls' => [
                    'methods' => [],  // disable live calls — use annotations only
                ],
            ],
        ],
    ],

    'auth' => [
        'enabled'     => true,
        'default'     => true,
        'in'          => 'bearer',
        'name'        => 'token',
        'use_value'   => env('SCRIBE_AUTH_KEY', ''),
        'placeholder' => '{YOUR_AUTH_TOKEN}',
        'extra_info'  => 'Obtain a token via POST /api/login.',
    ],

    'intro_text' => <<<'MD'
    ## Authentication
    All inventory endpoints require a **Sanctum bearer token**.
    Obtain it via `POST /api/login` with `email` + `password`.

    ## Offer → Module relationship
    Every inventory item (flight, hotel, transfer, car, excursion) is attached to
    an **Offer** row.  Create the offer first (`POST /api/offers`), then create the
    module (`POST /api/flights`, etc.) referencing `offer_id`.

    ## Pricing anchor
    `offers.price` is always derived from the module's own pricing fields on every
    create/update — never set it manually for inventory modules.
    MD,

    'example_languages' => ['bash', 'javascript'],

    'postman' => [
        'enabled'     => true,
        'overrides'   => [],
    ],

    'openapi' => [
        'enabled'   => true,
        'overrides' => [],
    ],

    'groups' => [
        'default' => 'General',
        'order'   => [
            'Auth',
            'Offers',
            'Flights',
            'Flight Cabins',
            'Hotels',
            'Transfers',
            'Cars',
            'Excursions',
        ],
    ],

    'logo' => false,

    'last_updated' => 'Last updated: {date:F j, Y}',

    'examples' => [
        'faker_seed'      => 1234,
        'models_source'   => ['factoryCreate', 'factoryMake', 'databaseFirst'],
    ],

    'strategies' => [
        'metadata'            => [\Knuckles\Scribe\Extracting\Strategies\Metadata\GetFromDocBlocks::class],
        'urlParameters'       => [\Knuckles\Scribe\Extracting\Strategies\UrlParameters\GetFromLaravelAPI::class],
        'queryParameters'     => [\Knuckles\Scribe\Extracting\Strategies\QueryParameters\GetFromInlineValidator::class],
        'headers'             => [\Knuckles\Scribe\Extracting\Strategies\Headers\GetFromRouteRules::class],
        'bodyParameters'      => [\Knuckles\Scribe\Extracting\Strategies\BodyParameters\GetFromInlineValidator::class],
        'responses'           => [
            \Knuckles\Scribe\Extracting\Strategies\Responses\UseResponseAttributes::class,
            \Knuckles\Scribe\Extracting\Strategies\Responses\UseApiResourceTags::class,
            \Knuckles\Scribe\Extracting\Strategies\Responses\ResponseCalls::class,
        ],
        'responseFields'      => [\Knuckles\Scribe\Extracting\Strategies\ResponseFields\GetFromResponseFieldAttribute::class],
    ],

    'database_connections_to_transact' => [],

    'fractal' => ['serializer' => null],

    'routeMatcher' => \Knuckles\Scribe\Matching\RouteMatcher::class,
];
