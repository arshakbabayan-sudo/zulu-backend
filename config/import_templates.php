<?php

/**
 * Import template registry (minimal; extend with version prefixes later).
 *
 * @var array<string, array{
 *     entity_type: string,
 *     allowed_extensions: list<string>,
 *     sheet_names: list<string>,
 *     required_headers: list<string>,
 *     external_key_column: string,
 *     parent_external_key_column: string|null
 * }>
 */
return [
    'templates' => [
        'offers' => [
            'entity_type' => 'offers',
            'allowed_extensions' => ['csv', 'xlsx'],
            'sheet_names' => ['Offers', 'offers'],
            'required_headers' => ['offer_external_key'],
            'external_key_column' => 'offer_external_key',
            'parent_external_key_column' => null,
        ],
        'flights' => [
            'entity_type' => 'flights',
            'allowed_extensions' => ['csv', 'xlsx'],
            'sheet_names' => ['Flights', 'flights'],
            'required_headers' => ['flight_external_key'],
            'external_key_column' => 'flight_external_key',
            'parent_external_key_column' => null,
        ],
        'flight_cabins' => [
            'entity_type' => 'flight_cabins',
            'allowed_extensions' => ['csv', 'xlsx'],
            'sheet_names' => ['Flight Cabins', 'Flight_Cabins', 'flight_cabins'],
            'required_headers' => ['cabin_external_key', 'flight_external_key'],
            'external_key_column' => 'cabin_external_key',
            'parent_external_key_column' => 'flight_external_key',
        ],
        'hotels' => [
            'entity_type' => 'hotels',
            'allowed_extensions' => ['csv', 'xlsx'],
            'sheet_names' => ['Hotels', 'hotels'],
            'required_headers' => ['hotel_external_key'],
            'external_key_column' => 'hotel_external_key',
            'parent_external_key_column' => null,
        ],
        'hotel_rooms' => [
            'entity_type' => 'hotel_rooms',
            'allowed_extensions' => ['csv', 'xlsx'],
            'sheet_names' => ['Hotel Rooms', 'Hotel_Rooms', 'hotel_rooms'],
            'required_headers' => ['room_external_key', 'hotel_external_key'],
            'external_key_column' => 'room_external_key',
            'parent_external_key_column' => 'hotel_external_key',
        ],
        'hotel_room_pricings' => [
            'entity_type' => 'hotel_room_pricings',
            'allowed_extensions' => ['csv', 'xlsx'],
            'sheet_names' => ['Hotel Room Pricings', 'Hotel_Room_Pricings', 'hotel_room_pricings'],
            'required_headers' => ['pricing_external_key', 'room_external_key'],
            'external_key_column' => 'pricing_external_key',
            'parent_external_key_column' => 'room_external_key',
        ],
        'transfers' => [
            'entity_type' => 'transfers',
            'allowed_extensions' => ['csv', 'xlsx'],
            'sheet_names' => ['Transfers', 'transfers'],
            'required_headers' => ['transfer_external_key'],
            'external_key_column' => 'transfer_external_key',
            'parent_external_key_column' => null,
        ],
        'cars' => [
            'entity_type' => 'cars',
            'allowed_extensions' => ['csv', 'xlsx'],
            'sheet_names' => ['Cars', 'cars'],
            'required_headers' => ['car_external_key'],
            'external_key_column' => 'car_external_key',
            'parent_external_key_column' => null,
        ],
        'excursions' => [
            'entity_type' => 'excursions',
            'allowed_extensions' => ['csv', 'xlsx'],
            'sheet_names' => ['Excursions', 'excursions'],
            'required_headers' => ['excursion_external_key'],
            'external_key_column' => 'excursion_external_key',
            'parent_external_key_column' => null,
        ],
    ],
];
