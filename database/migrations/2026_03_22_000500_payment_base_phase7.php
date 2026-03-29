<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->dropForeign(['booking_id']);
            });

            Schema::table('invoices', function (Blueprint $table): void {
                $table->unsignedBigInteger('booking_id')->nullable()->change();
            });

            Schema::table('invoices', function (Blueprint $table): void {
                $table->foreign('booking_id')->references('id')->on('bookings')->cascadeOnDelete();
            });

            Schema::table('invoices', function (Blueprint $table): void {
                if (! Schema::hasColumn('invoices', 'package_order_id')) {
                    $table->foreignId('package_order_id')->nullable()->constrained('package_orders')->nullOnDelete();
                }
                if (! Schema::hasColumn('invoices', 'unique_booking_reference')) {
                    $table->string('unique_booking_reference', 191)->nullable()->unique()->after('package_order_id');
                }
                if (! Schema::hasColumn('invoices', 'currency')) {
                    $table->string('currency', 3)->nullable()->after('total_amount');
                }
                if (! Schema::hasColumn('invoices', 'notes')) {
                    $table->text('notes')->nullable()->after('status');
                }
            });
        }

        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table): void {
                if (! Schema::hasColumn('payments', 'currency')) {
                    $table->string('currency', 3)->nullable()->after('amount');
                }
                if (! Schema::hasColumn('payments', 'reference_code')) {
                    $table->string('reference_code', 191)->nullable()->unique()->after('payment_method');
                }
                if (! Schema::hasColumn('payments', 'paid_at')) {
                    $table->timestamp('paid_at')->nullable()->after('reference_code');
                }
                if (! Schema::hasColumn('payments', 'notes')) {
                    $table->text('notes')->nullable()->after('paid_at');
                }
            });
        }

        if (Schema::hasTable('commissions') && ! Schema::hasColumn('commissions', 'commission_mode')) {
            Schema::rename('commissions', 'commission_policies');
        }

        if (Schema::hasTable('commission_policies')) {
            Schema::table('commission_policies', function (Blueprint $table): void {
                if (! Schema::hasColumn('commission_policies', 'commission_mode')) {
                    $table->string('commission_mode', 32)->default('percent')->after('percent');
                }
                if (! Schema::hasColumn('commission_policies', 'min_amount')) {
                    $table->decimal('min_amount', 10, 2)->nullable()->after('commission_mode');
                }
                if (! Schema::hasColumn('commission_policies', 'max_amount')) {
                    $table->decimal('max_amount', 10, 2)->nullable()->after('min_amount');
                }
                if (! Schema::hasColumn('commission_policies', 'effective_from')) {
                    $table->date('effective_from')->nullable()->after('max_amount');
                }
                if (! Schema::hasColumn('commission_policies', 'effective_to')) {
                    $table->date('effective_to')->nullable()->after('effective_from');
                }
                if (! Schema::hasColumn('commission_policies', 'status')) {
                    $table->string('status', 32)->default('active')->after('effective_to');
                }
            });
        }

        if (! Schema::hasTable('commission_records')) {
            Schema::create('commission_records', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('commission_policy_id')->nullable();
                $table->string('subject_type', 64);
                $table->unsignedBigInteger('subject_id');
                $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
                $table->string('service_type', 64);
                $table->string('commission_mode', 32);
                $table->decimal('commission_value', 10, 4);
                $table->decimal('commission_amount_snapshot', 12, 2);
                $table->string('currency', 3);
                $table->string('status', 32)->default('pending');
                $table->timestamps();

                $table->foreign('commission_policy_id')
                    ->references('id')
                    ->on('commission_policies')
                    ->nullOnDelete();

                $table->index(['subject_type', 'subject_id']);
                $table->index('company_id');
            });
        }

        if (Schema::hasTable('permissions')) {
            $ts = now();
            foreach (['commissions.view', 'commissions.manage', 'commission_records.view'] as $name) {
                DB::table('permissions')->insertOrIgnore([
                    'name' => $name,
                    'created_at' => $ts,
                    'updated_at' => $ts,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->whereIn('name', [
                'commissions.manage',
                'commission_records.view',
            ])->delete();
        }

        Schema::dropIfExists('commission_records');

        if (Schema::hasTable('commission_policies')) {
            Schema::table('commission_policies', function (Blueprint $table): void {
                if (Schema::hasColumn('commission_policies', 'status')) {
                    $table->dropColumn('status');
                }
                if (Schema::hasColumn('commission_policies', 'effective_to')) {
                    $table->dropColumn('effective_to');
                }
                if (Schema::hasColumn('commission_policies', 'effective_from')) {
                    $table->dropColumn('effective_from');
                }
                if (Schema::hasColumn('commission_policies', 'max_amount')) {
                    $table->dropColumn('max_amount');
                }
                if (Schema::hasColumn('commission_policies', 'min_amount')) {
                    $table->dropColumn('min_amount');
                }
                if (Schema::hasColumn('commission_policies', 'commission_mode')) {
                    $table->dropColumn('commission_mode');
                }
            });

            Schema::rename('commission_policies', 'commissions');
        }

        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table): void {
                if (Schema::hasColumn('payments', 'notes')) {
                    $table->dropColumn('notes');
                }
                if (Schema::hasColumn('payments', 'paid_at')) {
                    $table->dropColumn('paid_at');
                }
                if (Schema::hasColumn('payments', 'reference_code')) {
                    $table->dropUnique(['reference_code']);
                    $table->dropColumn('reference_code');
                }
                if (Schema::hasColumn('payments', 'currency')) {
                    $table->dropColumn('currency');
                }
            });
        }

        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table): void {
                if (Schema::hasColumn('invoices', 'package_order_id')) {
                    $table->dropForeign(['package_order_id']);
                }
            });

            Schema::table('invoices', function (Blueprint $table): void {
                if (Schema::hasColumn('invoices', 'notes')) {
                    $table->dropColumn('notes');
                }
                if (Schema::hasColumn('invoices', 'currency')) {
                    $table->dropColumn('currency');
                }
                if (Schema::hasColumn('invoices', 'unique_booking_reference')) {
                    $table->dropUnique(['unique_booking_reference']);
                    $table->dropColumn('unique_booking_reference');
                }
                if (Schema::hasColumn('invoices', 'package_order_id')) {
                    $table->dropColumn('package_order_id');
                }
            });

            Schema::table('invoices', function (Blueprint $table): void {
                $table->dropForeign(['booking_id']);
            });

            Schema::table('invoices', function (Blueprint $table): void {
                $table->unsignedBigInteger('booking_id')->nullable(false)->change();
            });

            Schema::table('invoices', function (Blueprint $table): void {
                $table->foreign('booking_id')->references('id')->on('bookings')->cascadeOnDelete();
            });
        }
    }
};
