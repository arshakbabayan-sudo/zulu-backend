<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            if (! Schema::hasColumn('companies', 'slug')) {
                $table->string('slug', 191)->nullable()->unique();
            }
            if (! Schema::hasColumn('companies', 'legal_name')) {
                $table->string('legal_name')->nullable()->after('name');
            }
            if (! Schema::hasColumn('companies', 'tax_id')) {
                $table->string('tax_id', 64)->nullable();
            }
            if (! Schema::hasColumn('companies', 'country')) {
                $table->string('country', 64)->nullable();
            }
            if (! Schema::hasColumn('companies', 'city')) {
                $table->string('city', 128)->nullable();
            }
            if (! Schema::hasColumn('companies', 'address')) {
                $table->text('address')->nullable();
            }
            if (! Schema::hasColumn('companies', 'phone')) {
                $table->string('phone', 32)->nullable();
            }
            if (! Schema::hasColumn('companies', 'website')) {
                $table->string('website', 512)->nullable();
            }
            if (! Schema::hasColumn('companies', 'description')) {
                $table->text('description')->nullable();
            }
            if (! Schema::hasColumn('companies', 'logo')) {
                $table->string('logo', 2048)->nullable();
            }
            if (! Schema::hasColumn('companies', 'governance_status')) {
                $table->string('governance_status', 32)->default('active');
            }
            if (! Schema::hasColumn('companies', 'is_seller')) {
                $table->boolean('is_seller')->default(false);
            }
            if (! Schema::hasColumn('companies', 'seller_activated_at')) {
                $table->timestamp('seller_activated_at')->nullable();
            }
            if (! Schema::hasColumn('companies', 'profile_completed')) {
                $table->boolean('profile_completed')->default(false);
            }
        });

        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'governance_status')) {
            if (! Schema::hasIndex('companies', 'companies_governance_status_index')) {
                Schema::table('companies', function (Blueprint $table): void {
                    $table->index('governance_status', 'companies_governance_status_index');
                });
            }
        }

        if (! Schema::hasTable('company_seller_permissions')) {
            Schema::create('company_seller_permissions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->string('service_type', 64);
                $table->string('status', 32)->default('active');
                $table->unsignedBigInteger('granted_by')->nullable();
                $table->timestamp('granted_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'service_type']);
                $table->index('company_id');

                $table->foreign('granted_by')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('approvals')) {
            Schema::table('approvals', function (Blueprint $table): void {
                if (! Schema::hasColumn('approvals', 'requested_by')) {
                    $table->foreignId('requested_by')
                        ->nullable()
                        ->after('status')
                        ->constrained('users')
                        ->nullOnDelete();
                }
                if (! Schema::hasColumn('approvals', 'notes')) {
                    $table->text('notes')->nullable();
                }
                if (! Schema::hasColumn('approvals', 'decision_notes')) {
                    $table->text('decision_notes')->nullable();
                }
            });

            if (! Schema::hasIndex('approvals', ['entity_type', 'entity_id'])) {
                Schema::table('approvals', function (Blueprint $table): void {
                    $table->index(['entity_type', 'entity_id'], 'approvals_entity_type_entity_id_phase9_index');
                });
            }
        }

        if (Schema::hasTable('permissions')) {
            $ts = now();
            foreach ([
                'companies.edit_profile',
                'companies.view_dashboard',
                'companies.manage_seller_permissions',
                'seller_permissions.view',
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
        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->whereIn('name', [
                'companies.edit_profile',
                'companies.view_dashboard',
                'companies.manage_seller_permissions',
                'seller_permissions.view',
            ])->delete();
        }

        if (Schema::hasTable('approvals')) {
            if (Schema::hasIndex('approvals', 'approvals_entity_type_entity_id_phase9_index')) {
                Schema::table('approvals', function (Blueprint $table): void {
                    $table->dropIndex('approvals_entity_type_entity_id_phase9_index');
                });
            }

            Schema::table('approvals', function (Blueprint $table): void {
                if (Schema::hasColumn('approvals', 'decision_notes')) {
                    $table->dropColumn('decision_notes');
                }
                if (Schema::hasColumn('approvals', 'notes')) {
                    $table->dropColumn('notes');
                }
            });
        }

        Schema::dropIfExists('company_seller_permissions');

        Schema::table('companies', function (Blueprint $table): void {
            if (Schema::hasIndex('companies', 'companies_governance_status_index')) {
                $table->dropIndex('companies_governance_status_index');
            }
        });

        Schema::table('companies', function (Blueprint $table): void {
            foreach ([
                'profile_completed',
                'seller_activated_at',
                'is_seller',
                'governance_status',
                'logo',
                'description',
                'website',
                'phone',
                'address',
                'city',
                'country',
                'tax_id',
                'legal_name',
                'slug',
            ] as $col) {
                if (Schema::hasColumn('companies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
