<?php

namespace App\Services\Subscriptions;

/**
 * Catalog of features that subscription plans can gate.
 *
 * Each feature has a key (stored in `subscription_plans.features` jsonb),
 * a human label, a type (bool flag or int limit), and a default applied
 * when no plan is active or the key is absent.
 *
 * To add a new gated feature:
 *   1. Add an entry here.
 *   2. Call PlanGateService::allows / ::limit at the relevant code path.
 *   3. Add a default to subscription plans (or leave to the safe default).
 */
final class PlanFeature
{
    public const TYPE_BOOL = 'bool';

    public const TYPE_LIMIT = 'limit';

    /**
     * @return array<string, array{label: string, type: string, default: bool|int}>
     */
    public static function all(): array
    {
        return [
            'max_hotels' => [
                'label' => 'Max hotels per company',
                'type' => self::TYPE_LIMIT,
                'default' => 5,
            ],
            'max_users' => [
                'label' => 'Max users per company',
                'type' => self::TYPE_LIMIT,
                'default' => 10,
            ],
            'max_bulk_recipients' => [
                'label' => 'Max recipients per bulk notification',
                'type' => self::TYPE_LIMIT,
                'default' => 100,
            ],
            'bulk_notifications' => [
                'label' => 'Send bulk notifications',
                'type' => self::TYPE_BOOL,
                'default' => true,
            ],
            'external_api' => [
                'label' => 'External API access',
                'type' => self::TYPE_BOOL,
                'default' => false,
            ],
            'priority_support' => [
                'label' => 'Priority support (urgent SLA)',
                'type' => self::TYPE_BOOL,
                'default' => false,
            ],
            'custom_fields' => [
                'label' => 'Custom field definitions',
                'type' => self::TYPE_BOOL,
                'default' => true,
            ],
            'payroll' => [
                'label' => 'Payroll module',
                'type' => self::TYPE_BOOL,
                'default' => true,
            ],
        ];
    }

    public static function definition(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    public static function isLimit(string $key): bool
    {
        return (self::definition($key)['type'] ?? null) === self::TYPE_LIMIT;
    }

    public static function defaultValue(string $key): bool|int|null
    {
        return self::definition($key)['default'] ?? null;
    }
}
