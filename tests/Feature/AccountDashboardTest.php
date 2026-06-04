<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Account #0 Dashboard overview endpoint. */
class AccountDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_returns_overview_shape(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->getJson('/api/account/dashboard')
            ->assertOk()
            ->assertJsonPath('data.bookings.total', 0)
            ->assertJsonPath('data.partners_count', 0)
            ->assertJsonStructure([
                'data' => [
                    'bookings' => ['total', 'upcoming'],
                    'travelers_count',
                    'documents_count',
                    'partners_count',
                    'saved_items_count',
                    'recent_orders',
                ],
            ]);
    }
}
