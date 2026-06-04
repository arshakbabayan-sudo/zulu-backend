<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\UserAccount\UserAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Account #1 Profile + #14 Preferences extra fields (surname/gender/currency/
 *  timezone/emergency contact/travel preferences/marketing) round-trip. */
class AccountProfileFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_extra_profile_fields_update_and_view(): void
    {
        $user = User::factory()->create();
        $service = app(UserAccountService::class);

        $updated = $service->updateProfile($user, [
            'surname' => 'Babayan',
            'gender' => 'female',
            'preferred_currency' => 'AMD',
            'timezone' => 'Asia/Yerevan',
            'emergency_contact_name' => 'Aram',
            'emergency_contact_phone' => '+37499000000',
            'emergency_contact_relationship' => 'spouse',
            'travel_preferences' => ['seat' => 'window', 'meal' => 'vegetarian', 'room_type' => 'double'],
            'marketing_opt_in' => true,
        ]);

        $this->assertSame('Babayan', $updated->surname);
        $this->assertSame('AMD', $updated->preferred_currency);
        $this->assertTrue($updated->marketing_opt_in);
        $this->assertSame('window', $updated->travel_preferences['seat']);

        $profile = $service->getProfile($updated);
        $this->assertSame('Babayan', $profile['surname']);
        $this->assertSame('vegetarian', $profile['travel_preferences']['meal']);
        $this->assertSame('Aram', $profile['emergency_contact_name']);
        $this->assertTrue($profile['marketing_opt_in']);
    }

    public function test_get_account_profile_endpoint_returns_full_profile(): void
    {
        $user = User::factory()->create(['name' => 'John']);
        app(UserAccountService::class)->updateProfile($user, [
            'surname' => 'Smith',
            'gender' => 'male',
            'preferred_currency' => 'EUR',
            'emergency_contact_name' => 'Jane',
            'travel_preferences' => ['seat' => 'aisle'],
            'marketing_opt_in' => true,
        ]);

        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/account/profile')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.surname', 'Smith')
            ->assertJsonPath('data.gender', 'male')
            ->assertJsonPath('data.preferred_currency', 'EUR')
            ->assertJsonPath('data.emergency_contact_name', 'Jane')
            ->assertJsonPath('data.travel_preferences.seat', 'aisle')
            ->assertJsonPath('data.marketing_opt_in', true);
    }
}
