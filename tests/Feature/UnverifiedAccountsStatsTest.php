<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/platform-admin/unverified-accounts now carries full-dataset stat
 * cards in meta.stats, computed over the SAME "unverified" set the list uses
 * (status=pending OR no verified email). Super-only, like the list itself.
 *
 * The endpoint counts the WHOLE table and the migration suite seeds a couple of
 * automation users, so we assert on the DELTA our fixtures introduce rather than
 * on absolute totals.
 */
class UnverifiedAccountsStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['super_admin', 'company_admin'] as $r) {
            Role::query()->firstOrCreate(['name' => $r]);
        }
    }

    /**
     * Pin created_at deterministically via a raw update — passing created_at to
     * factory()->create() is unreliable (Eloquent re-stamps timestamps on the
     * insert), so we set it after the row exists.
     */
    private function pinCreatedAt(User $user, Carbon $when): User
    {
        DB::table('users')->where('id', $user->id)->update(['created_at' => $when]);

        return $user->fresh();
    }

    private function fetchStats(): array
    {
        $company = Company::query()->create(['name' => 'Unv Co', 'type' => 'operator']);
        $super = User::factory()->create();
        $super->companies()->attach($company->id, ['role_id' => Role::query()->where('name', 'super_admin')->value('id')]);
        Sanctum::actingAs($super->fresh());

        return $this->getJson('/api/platform-admin/unverified-accounts')->assertOk()->json('meta.stats');
    }

    public function test_stats_counts_over_full_unverified_set(): void
    {
        $before = $this->fetchStats();

        // Stale unverified (no email verified, created >30d ago).
        $this->pinCreatedAt(User::factory()->unverified()->create(), now()->subDays(40));
        // New unverified (created <7d ago).
        $this->pinCreatedAt(User::factory()->unverified()->create(), now()->subDays(2));
        // Intended-staff unverified (came in via partner-register), also new.
        $this->pinCreatedAt(
            User::factory()->unverified()->create(['intended_role' => 'operator']),
            now()->subDays(2)
        );
        // Pending-status but email IS verified — still in the unverified set
        // (status=pending), created >30d ago → also counts as stale.
        $this->pinCreatedAt(User::factory()->create(['status' => 'pending']), now()->subDays(50));
        // A fully verified active user — must NOT count anywhere.
        $this->pinCreatedAt(User::factory()->create(['status' => 'active']), now()->subDays(40));

        $after = $this->fetchStats();

        // 2 stale added (40d unverified + 50d pending).
        $this->assertSame(2, $after['stale_30d'] - $before['stale_30d']);
        // 2 new added (the two created 2d ago). The verified-active and the
        // stale rows don't fall in the <7d window.
        $this->assertSame(2, $after['new_7d'] - $before['new_7d']);
        // 1 intended-staff added.
        $this->assertSame(1, $after['intended_staff'] - $before['intended_staff']);
    }
}
