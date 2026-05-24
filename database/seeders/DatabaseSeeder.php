<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RbacBootstrapSeeder::class,
            WidgetSeeder::class,
            LocationSeeder::class,
            ProductsQaSeeder::class,
            // Phase 1 / C.5 — pricing engine + money-flow defaults
            // (idempotent; safe on prod re-run via `db:seed --class=PricingDefaultsSeeder`).
            PricingDefaultsSeeder::class,
        ]);
    }
}
