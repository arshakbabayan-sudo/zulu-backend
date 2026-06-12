<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Roadmap §4 — built-in DEMO supplier implementing the ZULU
 * supplier-connector contract. Lets operators (and Arshak) try the
 * External API page end-to-end without a real external base:
 * connect to {app}/api/connector-demo with login `zulu-demo` /
 * password `zulu-demo-2026`. Data is static and clearly demo-named.
 */
class ConnectorDemoController extends Controller
{
    public const DEMO_LOGIN = 'zulu-demo';

    public const DEMO_PASSWORD = 'zulu-demo-2026';

    public function ping(Request $request): JsonResponse
    {
        if ($response = $this->requireDemoAuth($request)) {
            return $response;
        }

        return response()->json(['ok' => true, 'service' => 'zulu-connector-demo']);
    }

    public function inventory(Request $request): JsonResponse
    {
        if ($response = $this->requireDemoAuth($request)) {
            return $response;
        }

        // Single page; next_page null ends the import loop.
        return response()->json([
            'items' => [
                [
                    'type' => 'hotel',
                    'external_id' => 'DEMO-HOTEL-1',
                    'title' => 'Demo Grand Ararat Hotel',
                    'city' => 'Yerevan',
                    'price' => 120,
                    'currency' => 'USD',
                    'meal_type' => 'bed_and_breakfast',
                    'description' => 'Demo item from the built-in ZULU connector demo.',
                    'rooms' => [
                        ['room_name' => 'Standard double', 'room_type' => 'double', 'max_adults' => 2, 'max_total_guests' => 2, 'price' => 120, 'currency' => 'USD'],
                    ],
                ],
                [
                    'type' => 'hotel',
                    'external_id' => 'DEMO-HOTEL-2',
                    'title' => 'Demo City Inn',
                    'city' => 'Yerevan',
                    'price' => 80,
                    'currency' => 'USD',
                    'rooms' => [
                        ['room_name' => 'Twin room', 'room_type' => 'twin', 'max_adults' => 2, 'max_total_guests' => 3, 'price' => 80, 'currency' => 'USD'],
                    ],
                ],
                [
                    'type' => 'car',
                    'external_id' => 'DEMO-CAR-1',
                    'title' => 'Demo Toyota Corolla 2022',
                    'city' => 'Yerevan',
                    'pickup_location' => 'Yerevan Zvartnots Airport',
                    'dropoff_location' => 'Yerevan Zvartnots Airport',
                    'vehicle_class' => 'economy',
                    'brand' => 'Toyota',
                    'model' => 'Corolla',
                    'seats' => 5,
                    'price' => 45,
                    'currency' => 'USD',
                ],
                [
                    'type' => 'transfer',
                    'external_id' => 'DEMO-TRANSFER-1',
                    'title' => 'Demo airport → Gyumri transfer',
                    'origin_city' => 'Yerevan',
                    'destination_city' => 'Gyumri',
                    'pickup_point_name' => 'Zvartnots Airport arrivals',
                    'dropoff_point_name' => 'Gyumri city-centre hotel',
                    'price' => 25,
                    'currency' => 'USD',
                ],
            ],
            'next_page' => null,
        ]);
    }

    private function requireDemoAuth(Request $request): ?JsonResponse
    {
        $login = (string) $request->getUser();
        $password = (string) $request->getPassword();

        if ($login !== self::DEMO_LOGIN || ! hash_equals(self::DEMO_PASSWORD, $password)) {
            return response()->json(['ok' => false, 'error' => 'Unauthorized'], 401);
        }

        return null;
    }
}
