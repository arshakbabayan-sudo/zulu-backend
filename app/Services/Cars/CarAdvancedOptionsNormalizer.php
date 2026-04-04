<?php

namespace App\Services\Cars;

use App\Models\Car;
use Illuminate\Support\Arr;

/**
 * Normalized schema for car rental advanced options (Step C1 + Step C2 pricing rules).
 * Stored as JSON on {@see Car::$advanced_options}; v1 envelope is stable for API consumers.
 *
 * Step C2 — `pricing_rules` semantics (booking engines should apply these on top of base rent):
 *
 * - **mileage**: `unlimited` ignores included/extra km fields (they must be null). `limited` requires
 *   `included_km_per_rental` (≥1); `extra_km_price` is optional (≥0) and applies to distance beyond the cap.
 *
 * - **cross_border**: `not_allowed` / `included` must not carry a surcharge. `surcharge_fixed` and
 *   `surcharge_daily` require `surcharge_amount` > 0 (currency follows the parent offer).
 *
 * - **radius**: `service_radius_km` is the max one-way distance from the agreed hub/pickup point (km).
 *   If unset, out-of-radius fields are not used (`out_of_radius_mode` = `not_applicable`). If set,
 *   choose how travel beyond that radius is handled: `flat_fee`, `per_km`, `not_allowed`, or `quote_only`.
 *   Fee fields must only be set for the matching mode (`flat_fee` vs `per_km`).
 */
class CarAdvancedOptionsNormalizer
{
    public const SCHEMA_VERSION = 1;

    /** @var list<string> */
    public const MILEAGE_MODES = ['limited', 'unlimited'];

    /** @var list<string> */
    public const CROSS_BORDER_POLICIES = ['not_allowed', 'included', 'surcharge_fixed', 'surcharge_daily'];

    /**
     * When {@see pricing_rules.radius.service_radius_km} is null, only `not_applicable` is stored.
     * When radius is set, `not_applicable` is invalid — use one of the other modes.
     *
     * @var list<string>
     */
    public const OUT_OF_RADIUS_MODES = ['not_applicable', 'flat_fee', 'per_km', 'not_allowed', 'quote_only'];

    /** @var list<string> */
    public const CHILD_SEAT_TYPES = ['infant', 'toddler', 'booster', 'convertible'];

    /** @var list<string> */
    public const SERVICE_KEYS = [
        'wifi',
        'ac',
        'gps',
        'bluetooth',
        'usb_charger',
        'dashcam',
        'child_seat_included',
        'snow_chains',
        'roof_rack',
        'winter_tires',
    ];

    /**
     * Default envelope when DB value is null (backward compatible).
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'v' => self::SCHEMA_VERSION,
            'child_seats' => [
                'available' => false,
                'types' => [],
            ],
            'extra_luggage' => [
                'additional_suitcases_max' => 0,
                'additional_small_bags_max' => 0,
                'notes' => null,
            ],
            'services' => [],
            'driver_languages' => [],
            'pricing_rules' => $this->defaultPricingRules(),
        ];
    }

    /**
     * Step C2 — mileage, cross-border, service radius / out-of-radius commercial rules (additive JSON).
     *
     * @return array<string, mixed>
     */
    public function defaultPricingRules(): array
    {
        return [
            'mileage' => [
                'mode' => 'unlimited',
                'included_km_per_rental' => null,
                'extra_km_price' => null,
            ],
            'cross_border' => [
                'policy' => 'not_allowed',
                'surcharge_amount' => null,
            ],
            'radius' => [
                'service_radius_km' => null,
                'out_of_radius_mode' => 'not_applicable',
                'out_of_radius_flat_fee' => null,
                'out_of_radius_per_km' => null,
            ],
        ];
    }

    /**
     * API / discovery: always return full v1 shape.
     *
     * @param  array<string, mixed>|null  $stored
     * @return array<string, mixed>
     */
    public function forApi(?array $stored): array
    {
        return $this->normalizeForStorage($this->mergeForUpdate(null, $stored));
    }

    /**
     * Merge DB value with a PATCH payload (partial keys allowed).
     *
     * @param  array<string, mixed>|null  $stored
     * @param  array<string, mixed>|null  $patch
     * @return array<string, mixed>
     */
    public function mergeForUpdate(?array $stored, ?array $patch): array
    {
        $base = $this->defaults();
        if (is_array($stored) && $stored !== []) {
            $base = $this->merge($base, $stored);
        }
        if ($patch === null || $patch === []) {
            return $base;
        }

        return $this->merge($base, $patch);
    }

    /**
     * Sanitize and return persistable JSON (always includes full v1 keys).
     *
     * @param  array<string, mixed>  $merged
     * @return array<string, mixed>
     */
    public function normalizeForStorage(array $merged): array
    {
        $d = $this->defaults();
        $m = $this->merge($d, $merged);

        $m['v'] = self::SCHEMA_VERSION;

        $m['child_seats']['available'] = (bool) ($m['child_seats']['available'] ?? false);
        $types = $m['child_seats']['types'] ?? [];
        $types = is_array($types) ? $types : [];
        $m['child_seats']['types'] = array_values(array_unique(array_filter(
            array_map(static fn ($t) => is_string($t) || is_numeric($t) ? (string) $t : '', $types),
            fn (string $t) => in_array($t, self::CHILD_SEAT_TYPES, true)
        )));
        sort($m['child_seats']['types']);

        $el = $m['extra_luggage'] ?? [];
        $el = is_array($el) ? $el : [];
        $m['extra_luggage'] = [
            'additional_suitcases_max' => max(0, min(255, (int) ($el['additional_suitcases_max'] ?? 0))),
            'additional_small_bags_max' => max(0, min(255, (int) ($el['additional_small_bags_max'] ?? 0))),
            'notes' => $this->nullableTrimmedString($el['notes'] ?? null, 500),
        ];

        $services = $m['services'] ?? [];
        $services = is_array($services) ? $services : [];
        $m['services'] = array_values(array_unique(array_filter(
            array_map(static fn ($s) => is_string($s) || is_numeric($s) ? (string) $s : '', $services),
            fn (string $s) => in_array($s, self::SERVICE_KEYS, true)
        )));
        sort($m['services']);

        $langs = $m['driver_languages'] ?? [];
        $langs = is_array($langs) ? $langs : [];
        $m['driver_languages'] = $this->normalizeLanguageCodes($langs);

        $m['pricing_rules'] = $this->normalizePricingRulesForStorage($m['pricing_rules'] ?? null);

        return $m;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    private function merge(array $base, array $patch): array
    {
        $out = array_replace_recursive($base, $patch);
        if (array_key_exists('services', $patch)) {
            $out['services'] = is_array($patch['services']) ? $patch['services'] : [];
        }
        if (array_key_exists('driver_languages', $patch)) {
            $out['driver_languages'] = is_array($patch['driver_languages']) ? $patch['driver_languages'] : [];
        }
        if (isset($patch['child_seats']['types']) && is_array($patch['child_seats']['types'])) {
            $out['child_seats']['types'] = $patch['child_seats']['types'];
        }
        if (isset($patch['extra_luggage']['notes'])) {
            $out['extra_luggage']['notes'] = $patch['extra_luggage']['notes'];
        }
        if (array_key_exists('pricing_rules', $patch)) {
            $pr = $patch['pricing_rules'];
            $out['pricing_rules'] = is_array($pr)
                ? array_replace_recursive($out['pricing_rules'] ?? $this->defaultPricingRules(), $pr)
                : $out['pricing_rules'] ?? $this->defaultPricingRules();
        }

        $this->applyPricingRulesMergeCoercion($out, $patch);

        return $out;
    }

    /**
     * Avoid stale nested keys when operators flip modes (array_replace_recursive alone keeps old leaves).
     * When the patch includes `pricing_rules.radius.out_of_radius_mode`, fee fields that do not apply to that
     * mode are cleared so PATCH payloads do not leave a stale flat fee or per-km rate from a previous mode.
     *
     * @param  array<string, mixed>  $out
     * @param  array<string, mixed>  $patch
     */
    private function applyPricingRulesMergeCoercion(array &$out, array $patch): void
    {
        if (! isset($out['pricing_rules']) || ! is_array($out['pricing_rules'])) {
            $out['pricing_rules'] = $this->defaultPricingRules();
        }

        if (Arr::get($patch, 'pricing_rules.mileage.mode') === 'unlimited') {
            $out['pricing_rules']['mileage']['mode'] = 'unlimited';
            $out['pricing_rules']['mileage']['included_km_per_rental'] = null;
            $out['pricing_rules']['mileage']['extra_km_price'] = null;
        }

        $pol = Arr::get($patch, 'pricing_rules.cross_border.policy');
        if (is_string($pol) && ! in_array($pol, ['surcharge_fixed', 'surcharge_daily'], true)) {
            $out['pricing_rules']['cross_border']['policy'] = $pol;
            $out['pricing_rules']['cross_border']['surcharge_amount'] = null;
        }

        $srPatch = Arr::get($patch, 'pricing_rules.radius.service_radius_km');
        if (Arr::has($patch, 'pricing_rules.radius.service_radius_km')
            && ($srPatch === null || $srPatch === '' || (int) $srPatch === 0)) {
            $out['pricing_rules']['radius'] = [
                'service_radius_km' => null,
                'out_of_radius_mode' => 'not_applicable',
                'out_of_radius_flat_fee' => null,
                'out_of_radius_per_km' => null,
            ];
        }

        $ormPatch = Arr::get($patch, 'pricing_rules.radius.out_of_radius_mode');
        if (Arr::has($patch, 'pricing_rules.radius.out_of_radius_mode') && is_string($ormPatch)) {
            if (! in_array($ormPatch, ['flat_fee'], true)) {
                $out['pricing_rules']['radius']['out_of_radius_flat_fee'] = null;
            }
            if (! in_array($ormPatch, ['per_km'], true)) {
                $out['pricing_rules']['radius']['out_of_radius_per_km'] = null;
            }
        }
    }

    /**
     * @param  array<string, mixed>|null  $raw
     * @return array<string, mixed>
     */
    private function normalizePricingRulesForStorage(?array $raw): array
    {
        $d = $this->defaultPricingRules();
        $src = is_array($raw) ? $raw : [];

        $mileage = is_array($src['mileage'] ?? null) ? $src['mileage'] : [];
        $mode = $mileage['mode'] ?? $d['mileage']['mode'];
        $mode = is_string($mode) && in_array($mode, self::MILEAGE_MODES, true) ? $mode : $d['mileage']['mode'];

        $includedKm = null;
        $extraKm = null;
        if ($mode === 'limited') {
            $ik = $mileage['included_km_per_rental'] ?? null;
            if ($ik !== null && $ik !== '') {
                $includedKm = max(1, min(1_000_000, (int) $ik));
            }
            $ek = $mileage['extra_km_price'] ?? null;
            if ($ek !== null && $ek !== '') {
                $extraKm = round(max(0, (float) $ek), 4);
            }
        }

        $cb = is_array($src['cross_border'] ?? null) ? $src['cross_border'] : [];
        $policy = $cb['policy'] ?? $d['cross_border']['policy'];
        $policy = is_string($policy) && in_array($policy, self::CROSS_BORDER_POLICIES, true)
            ? $policy
            : $d['cross_border']['policy'];

        $surcharge = null;
        if (in_array($policy, ['surcharge_fixed', 'surcharge_daily'], true)) {
            $sa = $cb['surcharge_amount'] ?? null;
            if ($sa !== null && $sa !== '') {
                $surcharge = round(max(0, (float) $sa), 4);
            }
        }

        $rad = is_array($src['radius'] ?? null) ? $src['radius'] : [];
        $sr = $rad['service_radius_km'] ?? null;
        $radiusKm = null;
        if ($sr !== null && $sr !== '') {
            $r = (int) $sr;
            if ($r > 0) {
                $radiusKm = min(50_000, $r);
            }
        }

        $orm = $rad['out_of_radius_mode'] ?? $d['radius']['out_of_radius_mode'];
        $orm = is_string($orm) && in_array($orm, self::OUT_OF_RADIUS_MODES, true) ? $orm : $d['radius']['out_of_radius_mode'];

        $flat = null;
        $perKm = null;

        if ($radiusKm === null) {
            $orm = 'not_applicable';
        } else {
            if ($orm === 'flat_fee') {
                $fv = $rad['out_of_radius_flat_fee'] ?? null;
                if ($fv !== null && $fv !== '') {
                    $flat = round(max(0, (float) $fv), 4);
                }
            } elseif ($orm === 'per_km') {
                $pv = $rad['out_of_radius_per_km'] ?? null;
                if ($pv !== null && $pv !== '') {
                    $perKm = round(max(0, (float) $pv), 4);
                }
            } else {
                $flat = null;
                $perKm = null;
            }
        }

        return [
            'mileage' => [
                'mode' => $mode,
                'included_km_per_rental' => $mode === 'limited' ? $includedKm : null,
                'extra_km_price' => $mode === 'limited' ? $extraKm : null,
            ],
            'cross_border' => [
                'policy' => $policy,
                'surcharge_amount' => $surcharge,
            ],
            'radius' => [
                'service_radius_km' => $radiusKm,
                'out_of_radius_mode' => $orm,
                'out_of_radius_flat_fee' => $flat,
                'out_of_radius_per_km' => $perKm,
            ],
        ];
    }

    /**
     * @param  list<mixed>  $codes
     * @return list<string>
     */
    private function normalizeLanguageCodes(array $codes): array
    {
        $out = [];
        foreach ($codes as $c) {
            if (! is_string($c) && ! is_numeric($c)) {
                continue;
            }
            $s = strtolower(trim((string) $c));
            if ($s === '' || strlen($s) > 12) {
                continue;
            }
            if (preg_match('/^[a-z]{2,3}(-[a-z]{2})?$/', $s) === 1) {
                $out[] = $s;
            }
        }

        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    private function nullableTrimmedString(mixed $value, int $maxLen): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);

        return $s === '' ? null : mb_substr($s, 0, $maxLen);
    }
}
