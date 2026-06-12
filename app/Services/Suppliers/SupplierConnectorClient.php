<?php

namespace App\Services\Suppliers;

use App\Models\SupplierConnection;
use Illuminate\Support\Facades\Http;

/**
 * Roadmap §4 — HTTP client for the ZULU supplier-connector contract.
 *
 * The external base must expose, under its base URL, HTTP-basic-auth
 * protected endpoints (contract doc: docs/integrations/
 * supplier-connector-contract.md in the platform workspace):
 *
 *   GET {base}/ping                  → 200 {"ok": true}
 *   GET {base}/inventory?page=N      → 200 {"items": [...], "next_page": N|null}
 */
class SupplierConnectorClient
{
    public const TIMEOUT_SECONDS = 12;

    /**
     * @return array{ok: bool, error: string|null}
     */
    public function ping(SupplierConnection $connection): array
    {
        try {
            $response = $this->request($connection)->get($this->url($connection, 'ping'));
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $this->connectError($e)];
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return ['ok' => false, 'error' => 'Authentication failed (wrong login or password).'];
        }
        if ($response->failed() || $response->json('ok') !== true) {
            return ['ok' => false, 'error' => 'Unexpected ping response (HTTP '.$response->status().').'];
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * @return array{ok: bool, items: list<array<string, mixed>>, next_page: int|null, error: string|null}
     */
    public function fetchInventoryPage(SupplierConnection $connection, int $page): array
    {
        try {
            $response = $this->request($connection)->get($this->url($connection, 'inventory'), ['page' => $page]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'items' => [], 'next_page' => null, 'error' => $this->connectError($e)];
        }

        if ($response->failed()) {
            return [
                'ok' => false,
                'items' => [],
                'next_page' => null,
                'error' => 'Inventory request failed (HTTP '.$response->status().').',
            ];
        }

        $items = $response->json('items');
        if (! is_array($items)) {
            return ['ok' => false, 'items' => [], 'next_page' => null, 'error' => 'Inventory response has no "items" list.'];
        }

        $nextPage = $response->json('next_page');

        return [
            'ok' => true,
            'items' => array_values(array_filter($items, 'is_array')),
            'next_page' => is_numeric($nextPage) ? (int) $nextPage : null,
            'error' => null,
        ];
    }

    private function request(SupplierConnection $connection): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withBasicAuth((string) $connection->login, (string) $connection->password)
            ->acceptJson()
            ->timeout(self::TIMEOUT_SECONDS);
    }

    private function url(SupplierConnection $connection, string $path): string
    {
        return rtrim((string) $connection->base_url, '/').'/'.$path;
    }

    private function connectError(\Throwable $e): string
    {
        return 'Could not reach the external base: '.$e->getMessage();
    }
}
