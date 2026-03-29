<?php

namespace App\Services\Infrastructure;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PlatformReadinessService
{
    public function getHealthPayload(): array
    {
        $status = 'ok';

        $database = ['status' => 'ok', 'message' => null];
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $database = ['status' => 'error', 'message' => $e->getMessage()];
            $status = 'degraded';
        }

        $modules = [
            'flights' => $this->safeTableModule('flights', $status),
            'hotels' => $this->safeTableModule('hotels', $status),
            'packages' => $this->safeTableModule('packages', $status),
            'package_orders' => $this->safeTableModule('package_orders', $status),
            'supported_languages' => $this->safeSupportedLanguagesModule($status),
        ];

        $permissionsCount = 0;
        try {
            $permissionsCount = (int) DB::table('permissions')->count();
        } catch (\Throwable $e) {
            $status = 'degraded';
        }

        $rolesCount = 0;
        try {
            $rolesCount = (int) DB::table('roles')->count();
        } catch (\Throwable $e) {
            $status = 'degraded';
        }

        return [
            'status' => $status,
            'api_version' => $this->getApiVersion(),
            'timestamp' => now()->toIso8601String(),
            'mobile_ready' => (bool) config('zulu_platform.mobile.ready_alignment', true),
            'queue_connection' => (string) config('queue.default'),
            'cache_store' => (string) config('cache.default'),
            'database' => $database,
            'modules' => $modules,
            'permissions_count' => $permissionsCount,
            'roles_count' => $rolesCount,
        ];
    }

    public function getApiVersion(): string
    {
        return (string) config('zulu_platform.api.version', 'v1');
    }

    /**
     * @return array{table_exists: bool, count: int}
     */
    private function safeTableModule(string $table, string &$status): array
    {
        try {
            $exists = Schema::hasTable($table);
            $count = $exists ? (int) DB::table($table)->count() : 0;

            return [
                'table_exists' => $exists,
                'count' => $count,
            ];
        } catch (\Throwable $e) {
            $status = 'degraded';

            return [
                'table_exists' => false,
                'count' => 0,
            ];
        }
    }

    /**
     * @return array{count: int, default_language: string|null}
     */
    private function safeSupportedLanguagesModule(string &$status): array
    {
        try {
            if (! Schema::hasTable('supported_languages')) {
                return [
                    'count' => 0,
                    'default_language' => null,
                ];
            }

            $count = (int) DB::table('supported_languages')->count();
            $default = DB::table('supported_languages')->where('is_default', true)->value('code');

            return [
                'count' => $count,
                'default_language' => $default !== null ? (string) $default : null,
            ];
        } catch (\Throwable $e) {
            $status = 'degraded';

            return [
                'count' => 0,
                'default_language' => null,
            ];
        }
    }
}
