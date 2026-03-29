<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_TABLE = 'packages_phase4_legacy';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        $packagesIsPhase4 = Schema::hasTable('packages') && Schema::hasColumn('packages', 'company_id');

        if (! $packagesIsPhase4) {
            if (Schema::hasTable('packages') && ! Schema::hasTable(self::LEGACY_TABLE)) {
                $this->dropOfferForeignKeyOnPackagesTable('packages');
                Schema::rename('packages', self::LEGACY_TABLE);
            }

            if (! Schema::hasTable('packages')) {
                Schema::create('packages', function (Blueprint $table) {
                    $table->id();
                    $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
                    $table->unique('offer_id', 'packages_phase4_offer_id_unique');
                    $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                    $table->string('package_type', 32);
                    $table->string('package_title')->nullable();
                    $table->string('package_subtitle')->nullable();
                    $table->string('destination_country')->nullable();
                    $table->string('destination_city')->nullable();
                    $table->unsignedSmallInteger('duration_days')->nullable();
                    $table->unsignedSmallInteger('min_nights')->nullable();
                    $table->unsignedSmallInteger('adults_count')->nullable();
                    $table->unsignedSmallInteger('children_count')->default(0);
                    $table->unsignedSmallInteger('infants_count')->default(0);
                    $table->decimal('base_price', 12, 2)->nullable();
                    $table->string('display_price_mode', 32)->default('total');
                    $table->string('currency', 3)->nullable();
                    $table->boolean('is_public')->default(false);
                    $table->boolean('is_bookable')->default(false);
                    $table->boolean('is_package_eligible')->default(true);
                    $table->string('status', 32)->default('draft');
                    $table->timestamps();
                });
            }
        }

        if (Schema::hasTable(self::LEGACY_TABLE) && DB::table('packages')->count() === 0) {
            $now = now();
            $legacy = DB::table(self::LEGACY_TABLE)->orderBy('id')->get();
            $seenOfferIds = [];

            foreach ($legacy as $row) {
                $offer = DB::table('offers')->where('id', $row->offer_id)->first();
                if ($offer === null) {
                    continue;
                }
                if (isset($seenOfferIds[$row->offer_id])) {
                    continue;
                }
                $seenOfferIds[$row->offer_id] = true;

                $destination = data_get($row, 'destination') ?? data_get($row, 'destination_city');
                $durationDays = data_get($row, 'duration_days');
                $durationDays = $durationDays !== null ? (int) $durationDays : null;
                if ($durationDays !== null && ($durationDays < 0 || $durationDays > 65535)) {
                    $durationDays = null;
                }

                DB::table('packages')->insert([
                    'offer_id' => $row->offer_id,
                    'company_id' => $offer->company_id,
                    'package_type' => substr((string) data_get($row, 'package_type'), 0, 32),
                    'package_title' => data_get($row, 'package_title'),
                    'package_subtitle' => data_get($row, 'package_subtitle'),
                    'destination_country' => data_get($row, 'destination_country'),
                    'destination_city' => $destination !== null ? (string) $destination : null,
                    'duration_days' => $durationDays,
                    'min_nights' => data_get($row, 'min_nights'),
                    'adults_count' => data_get($row, 'adults_count'),
                    'children_count' => (int) (data_get($row, 'children_count') ?? 0),
                    'infants_count' => (int) (data_get($row, 'infants_count') ?? 0),
                    'base_price' => data_get($row, 'base_price'),
                    'display_price_mode' => data_get($row, 'display_price_mode') ?? 'total',
                    'currency' => data_get($row, 'currency'),
                    'is_public' => (bool) (data_get($row, 'is_public') ?? false),
                    'is_bookable' => (bool) (data_get($row, 'is_bookable') ?? false),
                    'is_package_eligible' => (bool) (data_get($row, 'is_package_eligible') ?? true),
                    'status' => data_get($row, 'status') ?? 'draft',
                    'created_at' => data_get($row, 'created_at') ?? $now,
                    'updated_at' => data_get($row, 'updated_at') ?? $now,
                ]);
            }
        }

        Schema::dropIfExists(self::LEGACY_TABLE);

        if (! Schema::hasTable('package_components')) {
            Schema::create('package_components', function (Blueprint $table) {
                $table->id();
                $table->foreignId('package_id')->constrained('packages')->cascadeOnDelete();
                $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
                $table->string('module_type', 32);
                $table->string('package_role', 32);
                $table->boolean('is_required')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->string('selection_mode', 32)->default('fixed');
                $table->decimal('price_override', 12, 2)->nullable();
                $table->timestamps();

                $table->unique(['package_id', 'offer_id']);
                $table->index('package_id');
            });
        }

        Schema::enableForeignKeyConstraints();

        if (Schema::hasTable('permissions')) {
            $ts = now();
            foreach ([
                'packages.view',
                'packages.create',
                'packages.edit',
                'packages.delete',
                'packages.manage_components',
            ] as $name) {
                DB::table('permissions')->insertOrIgnore([
                    'name' => $name,
                    'created_at' => $ts,
                    'updated_at' => $ts,
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::dropIfExists('package_components');

        $this->dropOfferForeignKeyOnPackagesTable('packages');

        $rows = DB::table('packages')->orderBy('id')->get();

        Schema::dropIfExists('packages');

        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->string('destination');
            $table->unsignedInteger('duration_days');
            $table->string('package_type');
            $table->timestamps();
            $table->unique('offer_id', 'packages_offer_id_unique');
        });

        foreach ($rows as $row) {
            $parts = array_filter([
                $row->destination_city ?? null,
                $row->destination_country ?? null,
            ], fn ($v) => $v !== null && $v !== '');
            $destination = $parts !== [] ? implode(', ', $parts) : '—';

            DB::table('packages')->insert([
                'offer_id' => $row->offer_id,
                'destination' => $destination,
                'duration_days' => (int) ($row->duration_days ?? 0),
                'package_type' => $row->package_type,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }

        Schema::enableForeignKeyConstraints();

        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->whereIn('name', [
                'packages.view',
                'packages.create',
                'packages.edit',
                'packages.delete',
                'packages.manage_components',
            ])->delete();
        }
    }

    private function dropOfferForeignKeyOnPackagesTable(string $tableName): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        try {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['offer_id']);
            });
        } catch (\Throwable) {
            // Missing FK, non-standard constraint names, or SQLite in-memory quirks — rename path still works.
        }
    }
};
