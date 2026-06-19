<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * zulu:reset-data — bring the platform back to a clean "zero" state.
 *
 * Wipes ALL externally-entered DATA (users, companies, purchases, inventory,
 * user activity, CMS content, uploaded files) while KEEPING every config /
 * function / reference table (roles, permissions, languages, ui_translations,
 * geography, prices, exchange rates, commission & money-flow policies,
 * insurance/subscription catalogs, templates, site header/footer menus).
 *
 * The ONLY user/company kept is the super admin (default arshakbabayan@gmail.com)
 * and the company + pivot that grant his platform-scoped super_admin role.
 *
 * Mechanism: raw TRUNCATE ... RESTART IDENTITY CASCADE (Postgres) — NOT Eloquent
 * (≈39 models use SoftDeletes, so Model::delete() would only soft-delete). On
 * sqlite (CI tests) it falls back to FK-disabled DELETEs so the keep/delete LOGIC
 * is still exercised. Everything runs in ONE transaction with post-condition
 * asserts that roll the whole thing back if the super admin would lose access or
 * any global config table is unexpectedly emptied.
 */
class ResetDataCommand extends Command
{
    protected $signature = 'zulu:reset-data
        {--force : Actually perform the wipe (required in production)}
        {--dry-run : Resolve + report only; delete nothing}
        {--wipe-files : Also delete uploaded files from disk after commit}
        {--keep-email=arshakbabayan@gmail.com : The super-admin email to preserve}';

    protected $description = 'Wipe all entered data (users/purchases/inventory/content) but keep all config + the super admin.';

    /**
     * Full set of data tables to TRUNCATE (every DELETE-group table EXCEPT the
     * partial-keep tables handled by id-filtered deletes, and EXCEPT `statuses`
     * which is a config lookup). Order is irrelevant for TRUNCATE ... CASCADE.
     */
    private const TRUNCATE_TABLES = [
        // Users & companies (children only — users/companies/user_company are partial-keep)
        'user_notes', 'user_invitations', 'platform_staff_scopes',
        'company_seller_permissions', 'company_module_permissions', 'company_country_permissions',
        'agent_operator_requests', 'connections', 'company_applications', 'company_seller_applications',
        'company_subscriptions', 'contracts', 'contract_versions', 'crm_company_settings',
        'payroll_records', 'crm_employee_compensation', 'time_off_records', 'time_punches',
        // Purchases & finance
        'orders', 'order_items', 'package_orders', 'package_order_items', 'package_booking_sagas',
        'saga_component_states', 'saga_state_log', 'bookings', 'booking_items', 'booking_passenger',
        'booking_passengers', 'passengers', 'service_holds', 'vouchers', 'voucher_verification_logs',
        'invoices', 'payments', 'payment_logs', 'refund_requests', 'commissions', 'commission_records',
        'commission_transactions', 'commission_resolution_log', 'settlements', 'supplier_entitlements',
        'bonuses', 'order_pricing_snapshots', 'pricing_audit_log', 'insurance_policies',
        'loyalty_accounts', 'loyalty_transactions', 'loyalty_redemptions',
        // Inventory / catalog
        'offers', 'flights', 'flight_cabins', 'hotels', 'hotel_rooms', 'hotel_room_pricings',
        'transfers', 'cars', 'excursions', 'excursion_location', 'visas', 'packages',
        'package_components', 'package_modules', 'service_catalog_items', 'blocked_dates',
        'content_translations', 'service_connections', 'supplier_connections', 'supplier_imported_items',
        'custom_field_values', 'package_homepage_features', 'import_sessions', 'import_staging_rows',
        'import_chunk_checkpoints', 'webhook_subscriptions', 'webhook_deliveries',
        // User activity
        'visa_applications', 'saved_travelers', 'travel_documents', 'approvals', 'support_tickets',
        'support_messages', 'cases', 'case_replies', 'request_messages', 'crm_deals', 'crm_activities',
        'crm_leads', 'crm_segments', 'crm_segment_members', 'customer_partners', 'chat_conversations',
        'chat_participants', 'chat_messages', 'notifications', 'device_tokens', 'reviews',
        'user_favorites', 'saved_items', 'saved_searches', 'search_query_log', 'audit_logs',
        'data_export_requests', 'account_deletion_requests',
        // Content
        'pages', 'page_translations', 'widgets', 'widget_contents', 'widget_content_translations',
        'banners', 'faqs', 'newsletter_subscriptions',
        // Files (rows only; bytes handled by --wipe-files)
        'file_assets',
    ];

    /** Global config tables that MUST still have all their rows after the wipe. */
    private const CONFIG_MUST_SURVIVE = [
        'roles', 'permissions', 'role_permissions', 'statuses', 'supported_languages',
        'ui_translations', 'countries', 'regions', 'cities', 'platform_settings',
        'exchange_rates', 'pricing_rules', 'commission_rules', 'notification_templates',
        'footer_columns', 'footer_links', 'header_menu_items',
    ];

    /** Company-scoped config that CASCADE-dies with deleted companies (reported, not asserted). */
    private const COMPANY_SCOPED_CONFIG = [
        'subscription_plans', 'insurance_products', 'custom_field_definitions',
        'fx_partnership_premiums', 'operator_agent_commission',
    ];

    public function handle(): int
    {
        $email = (string) $this->option('keep-email');
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && app()->isProduction() && ! $this->option('force')) {
            $this->error('Refusing to wipe production without --force.');

            return self::FAILURE;
        }

        // ── Resolve the load-bearing super-admin rows (NEVER hardcode the company) ──
        $userId = DB::table('users')->where('email', $email)->value('id');
        $roleId = DB::table('roles')->where('name', 'super_admin')->where('scope', 'platform')->value('id');
        $companyId = $userId && $roleId
            ? DB::table('user_company')->where('user_id', $userId)->where('role_id', $roleId)->value('company_id')
            : null;

        if (! $userId || ! $roleId || ! $companyId) {
            $this->error('Cannot resolve the super-admin keep-set — aborting (nothing touched).');
            $this->line("  keep-email   = {$email}");
            $this->line('  user_id      = '.var_export($userId, true));
            $this->line('  super_role_id= '.var_export($roleId, true));
            $this->line('  company_id   = '.var_export($companyId, true));

            return self::FAILURE;
        }

        $companyName = DB::table('companies')->where('id', $companyId)->value('name');
        $this->info("Keep super-admin: {$email} (user #{$userId}), role #{$roleId}, company #{$companyId} \"{$companyName}\".");

        if ($dryRun) {
            return $this->reportDryRun($userId, $companyId);
        }

        $preCounts = $this->counts(self::CONFIG_MUST_SURVIVE);

        DB::transaction(function () use ($userId, $roleId, $companyId, $preCounts): void {
            $this->wipeDataTables();

            // Partial-keep tables: id-filtered hard deletes (children → parents).
            DB::table('personal_access_tokens')
                ->where(fn ($q) => $q->where('tokenable_type', 'not like', '%User')->orWhere('tokenable_id', '<>', $userId))
                ->delete();
            DB::table('user_permission_overrides')->where('user_id', '<>', $userId)->delete();
            DB::table('user_notification_preferences')->where('user_id', '<>', $userId)->delete();
            DB::table('user_two_factor')->where('user_id', '<>', $userId)->delete();
            DB::table('user_company')
                ->where(fn ($q) => $q->where('user_id', '<>', $userId)->orWhere('company_id', '<>', $companyId)->orWhere('role_id', '<>', $roleId))
                ->delete();
            DB::table('companies')->where('id', '<>', $companyId)->delete();
            DB::table('users')->where('id', '<>', $userId)->delete();

            // Idempotent re-anchor of the super-admin pivot (a re-run can't orphan him).
            $exists = DB::table('user_company')
                ->where('user_id', $userId)->where('company_id', $companyId)->where('role_id', $roleId)->exists();
            if (! $exists) {
                DB::table('user_company')->insert([
                    'user_id' => $userId, 'company_id' => $companyId, 'role_id' => $roleId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            // ── Post-conditions (any failure rolls back the WHOLE wipe) ──
            $stillSuper = (bool) optional(User::find($userId))->is_super_admin;
            if (! $stillSuper) {
                throw new \RuntimeException('Post-check failed: kept user is no longer super admin — rolling back.');
            }
            if (DB::table('users')->count() !== 1) {
                throw new \RuntimeException('Post-check failed: expected exactly 1 user — rolling back.');
            }
            if (DB::table('companies')->count() !== 1) {
                throw new \RuntimeException('Post-check failed: expected exactly 1 company — rolling back.');
            }
            foreach ($preCounts as $table => $before) {
                $after = (int) DB::table($table)->count();
                if ($after !== $before) {
                    throw new \RuntimeException("Post-check failed: config table '{$table}' changed {$before}→{$after} (errant cascade) — rolling back.");
                }
            }
        });

        $this->info('✔ Data wiped. Super admin preserved, all global config intact.');

        if ($this->option('wipe-files')) {
            $this->wipeFiles();
        } else {
            $this->warn('Uploaded file BYTES left on disk (re-run with --wipe-files to clear). Google-Drive files need manual cleanup.');
        }

        $this->reportRowCounts();

        return self::SUCCESS;
    }

    private function wipeDataTables(): void
    {
        $tables = self::TRUNCATE_TABLES;

        if (DB::getDriverName() === 'pgsql') {
            $quoted = implode(', ', array_map(fn ($t) => '"'.$t.'"', $tables));
            DB::statement("TRUNCATE TABLE {$quoted} RESTART IDENTITY CASCADE");

            return;
        }

        // sqlite / others (CI tests): disable FK checks and DELETE each table.
        Schema::disableForeignKeyConstraints();
        try {
            foreach ($tables as $t) {
                if (Schema::hasTable($t)) {
                    DB::table($t)->delete();
                }
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    private function reportDryRun(int $userId, int $companyId): int
    {
        $this->line('');
        $this->info('── DRY RUN — nothing will be deleted ──');

        $rows = [];
        foreach (self::TRUNCATE_TABLES as $t) {
            if (Schema::hasTable($t)) {
                $rows[] = [$t, (int) DB::table($t)->count()];
            }
        }
        $this->line('Tables to WIPE (row counts):');
        $this->table(['table', 'rows'], array_filter($rows, fn ($r) => $r[1] > 0) ?: [['(all empty)', 0]]);

        $this->line('');
        $this->line('Partial-keep (delete all rows EXCEPT super admin):');
        $this->table(['table', 'total', 'kept'], [
            ['users', DB::table('users')->count(), 1],
            ['companies', DB::table('companies')->count(), 1],
            ['user_company', DB::table('user_company')->count(), DB::table('user_company')->where('user_id', $userId)->where('company_id', $companyId)->count()],
        ]);

        $this->line('');
        $this->warn('Company-scoped CONFIG that CASCADE-dies with deleted companies (rows NOT owned by the kept company):');
        $scoped = [];
        foreach (self::COMPANY_SCOPED_CONFIG as $t) {
            if (! Schema::hasTable($t)) {
                continue;
            }
            $col = $this->companyColumnFor($t);
            $total = (int) DB::table($t)->count();
            $lost = $col ? (int) DB::table($t)->where($col, '<>', $companyId)->count() : 0;
            $scoped[] = [$t, $col ?? '(n/a)', $total, $lost];
        }
        $this->table(['table', 'company_col', 'total', 'will_cascade_delete'], $scoped);

        $this->line('');
        $this->info('Config that STAYS (global — must be unchanged after a real run):');
        $this->table(['table', 'rows'], $this->countsTable(self::CONFIG_MUST_SURVIVE));

        return self::SUCCESS;
    }

    private function companyColumnFor(string $table): ?string
    {
        foreach (['company_id', 'operator_id', 'operator_company_id'] as $c) {
            if (Schema::hasColumn($table, $c)) {
                return $c;
            }
        }

        return null;
    }

    private function wipeFiles(): void
    {
        $local = ['contracts', 'vouchers', 'visas', 'imports', 'exports'];
        foreach ($local as $dir) {
            Storage::disk('local')->deleteDirectory($dir);
        }
        foreach (['visas/applications', 'invoices'] as $dir) {
            Storage::disk('public')->deleteDirectory($dir);
        }
        $this->info('✔ Uploaded file directories cleared (Google-Drive-hosted assets still need manual Drive cleanup).');
    }

    /** @param string[] $tables @return array<string,int> */
    private function counts(array $tables): array
    {
        $out = [];
        foreach ($tables as $t) {
            if (Schema::hasTable($t)) {
                $out[$t] = (int) DB::table($t)->count();
            }
        }

        return $out;
    }

    /** @param string[] $tables @return array<int,array{0:string,1:int}> */
    private function countsTable(array $tables): array
    {
        $rows = [];
        foreach ($this->counts($tables) as $t => $n) {
            $rows[] = [$t, $n];
        }

        return $rows;
    }

    private function reportRowCounts(): void
    {
        $this->table(['', 'rows'], [
            ['users', DB::table('users')->count()],
            ['companies', DB::table('companies')->count()],
            ['orders', Schema::hasTable('orders') ? DB::table('orders')->count() : '-'],
            ['offers', Schema::hasTable('offers') ? DB::table('offers')->count() : '-'],
        ]);
    }
}
