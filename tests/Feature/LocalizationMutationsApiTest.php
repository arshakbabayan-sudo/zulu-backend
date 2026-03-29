<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\SupportedLanguage;
use App\Models\User;
use App\Models\UserCompany;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalizationMutationsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacBootstrapSeeder::class);
    }

    /**
     * @return array<string, string>
     */
    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function agentUser(): User
    {
        $company = Company::query()->firstOrFail();
        $agentRole = Role::query()->where('name', 'agent')->firstOrFail();
        $user = User::query()->create([
            'name' => 'Agent',
            'email' => 'loc-agent@tdd.local',
            'password' => bcrypt('password'),
            'status' => User::STATUS_ACTIVE,
        ]);
        UserCompany::query()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role_id' => $agentRole->id,
        ]);

        return $user;
    }

    public function test_super_admin_can_toggle_language_via_api(): void
    {
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $lang = SupportedLanguage::query()->where('code', 'ru')->firstOrFail();
        $before = $lang->is_enabled;

        $this->patchJson(
            "/api/localization/languages/{$lang->id}/toggle",
            [],
            $this->bearer($user)
        )
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_enabled', ! $before);

        $this->patchJson(
            "/api/localization/languages/{$lang->id}/toggle",
            [],
            $this->bearer($user)
        )->assertOk();
    }

    public function test_agent_cannot_toggle_language_via_api(): void
    {
        $lang = SupportedLanguage::query()->where('code', 'ru')->firstOrFail();

        $this->patchJson(
            "/api/localization/languages/{$lang->id}/toggle",
            [],
            $this->bearer($this->agentUser())
        )->assertForbidden();
    }

    public function test_super_admin_can_patch_notification_template(): void
    {
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();

        $this->patchJson(
            '/api/localization/templates/account.welcome',
            [
                'lang' => 'en',
                'channel' => 'in_app',
                'title_template' => 'Hi {{user_name}}',
                'body_template' => 'Welcome to {{company_name}}.',
            ],
            $this->bearer($user)
        )
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.event_type', 'account.welcome')
            ->assertJsonPath('data.language_code', 'en');

        $this->assertDatabaseHas('notification_templates', [
            'event_type' => 'account.welcome',
            'language_code' => 'en',
            'channel' => 'in_app',
        ]);
    }

    public function test_agent_cannot_patch_notification_template(): void
    {
        $this->patchJson(
            '/api/localization/templates/account.welcome',
            [
                'lang' => 'en',
                'channel' => 'in_app',
                'title_template' => 'X',
                'body_template' => 'Y',
            ],
            $this->bearer($this->agentUser())
        )->assertForbidden();
    }

    public function test_invalid_event_type_returns_404(): void
    {
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();

        $this->patchJson(
            '/api/localization/templates/not_an_event',
            [
                'lang' => 'en',
                'channel' => 'in_app',
                'title_template' => 'T',
                'body_template' => 'B',
            ],
            $this->bearer($user)
        )->assertNotFound();
    }
}
