<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 1.2 (GDPR Critical) — registration consent capture.
 *
 * Verifies POST /api/register:
 *   - Rejects payloads without `terms_accepted: true`.
 *   - On successful registration, records terms_accepted_at + consent_ip +
 *     consent_version on the user row.
 */
class RegistrationConsentTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_without_consent_is_rejected(): void
    {
        $res = $this->postJson('/api/register', [
            'name' => 'No Consent',
            'email' => 'noc-'.str()->uuid().'@example.test',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
            // No terms_accepted field
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors('terms_accepted');
    }

    public function test_registration_with_consent_records_timestamp_ip_version(): void
    {
        $email = 'cons-'.str()->uuid().'@example.test';

        $res = $this->postJson('/api/register', [
            'name' => 'Consenting User',
            'email' => $email,
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
            'terms_accepted' => true,
        ]);

        $res->assertStatus(201);

        $user = User::query()->where('email', $email)->firstOrFail();
        $this->assertNotNull($user->terms_accepted_at, 'consent timestamp not recorded');
        $this->assertNotEmpty($user->consent_ip, 'consent IP not recorded');
        $this->assertNotEmpty($user->consent_version, 'consent version not recorded');
        $this->assertStringStartsWith('v', $user->consent_version);
    }

    public function test_registration_with_consent_explicitly_false_is_rejected(): void
    {
        $res = $this->postJson('/api/register', [
            'name' => 'Declined Consent',
            'email' => 'decl-'.str()->uuid().'@example.test',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
            'terms_accepted' => false,
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors('terms_accepted');
    }
}
