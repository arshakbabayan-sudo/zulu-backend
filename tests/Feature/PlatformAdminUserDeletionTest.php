<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\Passenger;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Deletion-pipeline regression tests (2026-07-06).
 *
 * Background: AccountDeletionService::anonymiseRetainedRows used to run
 * `UPDATE passengers ... WHERE user_id = ?` but the passengers table has NO
 * user_id column (migration 2026_03_25_000004) — passengers attach to a user
 * via booking_passengers.booking_id → bookings.user_id. On Postgres that threw
 * 42703 and 500'd EVERY admin anonymize / hard-delete / bulk-delete / purge.
 *
 * Also covered here:
 *  - bulkDeleteUsers must skip super-admins and users with a role-bound
 *    company membership (live staff logins), reporting them in
 *    data.skipped = [{id, email, reason}].
 *  - listUnverifiedAccounts must exclude users with a role-bound membership
 *    from BOTH the list and the meta.stats KPI counts.
 */
class PlatformAdminUserDeletionTest extends TestCase
{
    use RefreshDatabase;

    // ── Fixtures ─────────────────────────────────────────────────

    private function makeCompany(): Company
    {
        return Company::query()->create([
            'name' => 'Del Co '.Str::uuid(),
            'type' => 'operator',
        ]);
    }

    private function makeUser(?string $email = null, ?string $status = null): User
    {
        return User::query()->create([
            'name' => 'Deletion Test User',
            'email' => $email ?? 'del-'.Str::uuid().'@example.test',
            'password' => bcrypt('password'),
            'status' => $status ?? User::STATUS_ACTIVE,
        ]);
    }

    private function makeSuperAdmin(): User
    {
        $user = $this->makeUser();
        $role = Role::query()->firstOrCreate(['name' => 'super_admin']);
        $user->companies()->attach($this->makeCompany()->id, ['role_id' => $role->id]);

        return $user->fresh();
    }

    private function makeStaff(?string $email = null): User
    {
        $user = $this->makeUser($email);
        $role = Role::query()->firstOrCreate(['name' => 'company_admin']);
        $user->companies()->attach($this->makeCompany()->id, ['role_id' => $role->id]);

        return $user->fresh();
    }

    /**
     * Create a booking owned by $owner with one fully-PII'd passenger attached
     * through the booking_passengers pivot (the REAL relation chain — the
     * passengers table has no user_id column).
     *
     * @return array{0:int,1:Passenger} [bookingId, passenger]
     */
    private function makeBookingWithPassenger(User $owner): array
    {
        $company = $this->makeCompany();

        $bookingId = (int) DB::table('bookings')->insertGetId([
            'user_id' => $owner->id,
            'company_id' => $company->id,
            'status' => 'confirmed',
            'total_price' => 250.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $passenger = Passenger::query()->create([
            'first_name' => 'Poghos',
            'last_name' => 'Poghosyan',
            'passport_number' => 'AB1234567',
            'passport_expiry' => '2030-01-01',
            'nationality' => 'Armenian',
            'date_of_birth' => '1990-05-05',
            'gender' => 'male',
            'passenger_type' => 'adult',
            'email' => 'poghos@example.test',
            'phone' => '+37491000000',
        ]);

        DB::table('booking_passengers')->insert([
            'booking_id' => $bookingId,
            'passenger_id' => $passenger->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$bookingId, $passenger];
    }

    /** Raw passengers row (bypasses encrypted casts) for column assertions. */
    private function rawPassenger(int $id): object
    {
        return DB::table('passengers')->where('id', $id)->first();
    }

    // ── (1) bulk-delete a booking-owning B2C user ────────────────

    public function test_bulk_delete_anonymizes_b2c_user_and_attached_passengers(): void
    {
        $super = $this->makeSuperAdmin();
        $customer = $this->makeUser('bulk-victim@example.test');
        [, $passenger] = $this->makeBookingWithPassenger($customer);

        // Order + order_items.passenger_data JSON snapshot for the same user —
        // must be blanked by the same pipeline.
        $order = Order::query()->create([
            'user_id' => $customer->id,
            'buyer_type' => 'client',
            'status' => 'paid',
            'currency' => 'AMD',
            'total' => 250,
        ]);
        DB::table('order_items')->insert([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'item_type' => 'flight',
            'quantity' => 1,
            'unit_price' => 250,
            'total' => 250,
            'currency' => 'AMD',
            'status' => 'confirmed',
            'passenger_data' => json_encode([['first_name' => 'Poghos', 'last_name' => 'Poghosyan']]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($super);

        $response = $this->postJson('/api/platform-admin/users/bulk-delete', [
            'ids' => [$customer->id],
            'reason' => 'Regression: passengers crash',
        ]);

        $response->assertOk();
        $this->assertSame(1, $response->json('data.deleted_count'));
        // Legacy key kept for existing consumers.
        $this->assertSame(1, $response->json('data.processed'));
        $this->assertSame([], $response->json('data.skipped'));

        // User is soft-deleted + PII anonymized.
        $this->assertSoftDeleted('users', ['id' => $customer->id]);
        $trashed = User::withTrashed()->findOrFail($customer->id);
        $this->assertSame('anon-'.$customer->id.'@deleted.zulu.am', $trashed->email);

        // Passenger PII is gone; NOT NULL name columns hold the placeholder.
        $raw = $this->rawPassenger($passenger->id);
        $this->assertSame('Anonymized', $raw->first_name);
        $this->assertSame('Anonymized', $raw->last_name);
        $this->assertNull($raw->passport_number);
        $this->assertNull($raw->passport_expiry);
        $this->assertNull($raw->nationality);
        $this->assertNull($raw->date_of_birth);
        $this->assertNull($raw->gender);
        $this->assertNull($raw->email);
        $this->assertNull($raw->phone);
        // passenger_type is non-identifying and must survive.
        $this->assertSame('adult', $raw->passenger_type);

        // JSON manifest snapshot blanked too.
        $this->assertNull(
            DB::table('order_items')->where('order_id', $order->id)->value('passenger_data')
        );
    }

    // ── (2) bulk-delete skips staff + super-admins ───────────────

    public function test_bulk_delete_skips_users_with_company_membership_and_super_admins(): void
    {
        $super = $this->makeSuperAdmin();
        $staff = $this->makeStaff('live-operator@example.test');
        $otherSuper = $this->makeSuperAdmin();
        $plain = $this->makeUser('plain-junk@example.test');

        Sanctum::actingAs($super);

        $response = $this->postJson('/api/platform-admin/users/bulk-delete', [
            'ids' => [$staff->id, $otherSuper->id, $plain->id],
        ]);

        $response->assertOk();
        $this->assertSame(1, $response->json('data.deleted_count'));
        $this->assertSame(2, $response->json('data.skipped_count'));

        $skipped = collect($response->json('data.skipped'));
        $staffEntry = $skipped->firstWhere('id', $staff->id);
        $this->assertNotNull($staffEntry, 'staff user must be reported in skipped[]');
        $this->assertSame('live-operator@example.test', $staffEntry['email']);
        $this->assertSame('has_company_membership', $staffEntry['reason']);

        $superEntry = $skipped->firstWhere('id', $otherSuper->id);
        $this->assertNotNull($superEntry, 'super-admin must be reported in skipped[]');
        $this->assertSame('super_admin', $superEntry['reason']);

        // Staff login untouched: not trashed, email intact.
        $this->assertDatabaseHas('users', [
            'id' => $staff->id,
            'email' => 'live-operator@example.test',
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $otherSuper->id,
            'deleted_at' => null,
        ]);

        // The plain B2C user WAS anonymized.
        $this->assertSoftDeleted('users', ['id' => $plain->id]);
    }

    // ── (3) unverified list + stats exclude staff logins ─────────

    public function test_unverified_accounts_list_and_stats_exclude_users_with_membership(): void
    {
        $super = $this->makeSuperAdmin();
        Sanctum::actingAs($super);

        // Baseline BEFORE fixtures (migration suite may seed automation users,
        // so all stats assertions are deltas).
        $before = $this->getJson('/api/platform-admin/unverified-accounts')
            ->assertOk()
            ->json('meta.stats');

        // Live staff login that never verified email — must NOT appear.
        $staff = $this->makeStaff('unverified-operator@example.test');
        // Plain unverified B2C signup — must appear.
        $this->makeUser('unverified-b2c@example.test');

        $response = $this->getJson('/api/platform-admin/unverified-accounts');
        $response->assertOk();

        $emails = collect($response->json('data'))->pluck('email')->all();
        $this->assertContains('unverified-b2c@example.test', $emails);
        $this->assertNotContains('unverified-operator@example.test', $emails);

        // KPI cards use the SAME exclusion: both fixtures were created "now",
        // but only the plain B2C row may move new_7d.
        $after = $response->json('meta.stats');
        $this->assertSame(1, $after['new_7d'] - $before['new_7d']);
        $this->assertSame(0, $after['stale_30d'] - $before['stale_30d']);

        // Sanity: the staff row really is in the raw unverified set — only the
        // membership filter keeps it out.
        $this->assertNull($staff->fresh()->email_verified_at);
    }

    // ── (4) single anonymize endpoint — passengers crash regression ──

    public function test_anonymize_endpoint_succeeds_for_booking_owning_user(): void
    {
        $super = $this->makeSuperAdmin();
        $customer = $this->makeUser('anon-victim@example.test');
        [, $passenger] = $this->makeBookingWithPassenger($customer);

        Sanctum::actingAs($super);

        // Before the fix this 500'd: UPDATE passengers ... WHERE user_id = ?
        // (column does not exist).
        $response = $this->postJson('/api/platform-admin/users/'.$customer->id.'/anonymize', [
            'reason' => 'GDPR request',
        ]);

        $response->assertOk();
        $this->assertSame($customer->id, $response->json('data.id'));

        $this->assertSoftDeleted('users', ['id' => $customer->id]);

        $raw = $this->rawPassenger($passenger->id);
        $this->assertSame('Anonymized', $raw->first_name);
        $this->assertSame('Anonymized', $raw->last_name);
        $this->assertNull($raw->passport_number);
        $this->assertNull($raw->email);
        $this->assertNull($raw->phone);
    }
}
