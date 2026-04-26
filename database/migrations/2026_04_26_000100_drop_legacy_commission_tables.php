<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Sprint 1 Step 4e (ADR-002): Legacy Commission Demolition
|--------------------------------------------------------------------------
|
| Drops legacy commission tables after resolver migration cutover:
| - commission_records
| - commission_policies
| - commissions
|
| This migration is intentionally one-way. Legacy schema recreation must be
| done from the original create migrations in git history.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('commission_records');
        Schema::dropIfExists('commission_policies');
        Schema::dropIfExists('commissions');
    }

    public function down(): void
    {
        throw new RuntimeException('Sprint 1 Step 4e drop is one-way. To restore old commission schema, restore from the create-migrations in git history (search for "commissions" + "commission_policies" + "commission_records" create migrations).');
    }
};
