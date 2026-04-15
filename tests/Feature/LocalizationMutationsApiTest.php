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

    public function test_read_endpoints_accept_region_language_variants(): void
    {
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();

        $this->getJson('/api/localization/ui-translations?lang=HY-AM')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.language_code', 'hy');

        $this->getJson('/api/localization/translations?entity_type=company&entity_id=1&lang=ru_RU')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.language_code', 'ru');

        $this->getJson(
            '/api/localization/ui-translations/admin?lang=RU_RU&page=1&per_page=10',
            $this->bearer($user)
        )
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_mutation_endpoints_canonicalize_region_language_variants(): void
    {
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();

        $this->postJson(
            '/api/localization/ui-translations',
            [
                'language_code' => 'RU-ru',
                'translations' => [
                    'phase8.step3.smoke_ui_key' => 'Smoke Value',
                ],
            ],
            $this->bearer($user)
        )
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.language_code', 'ru');

        $this->assertDatabaseHas('ui_translations', [
            'language_code' => 'ru',
            'key' => 'phase8.step3.smoke_ui_key',
            'value' => 'Smoke Value',
        ]);

        $this->patchJson(
            '/api/localization/templates/account.welcome',
            [
                'lang' => 'hy_AM',
                'channel' => 'in_app',
                'title_template' => 'Բարեւ {{user_name}}',
                'body_template' => 'Բարի գալուստ {{company_name}}:',
            ],
            $this->bearer($user)
        )
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.language_code', 'hy');

        $this->assertDatabaseHas('notification_templates', [
            'event_type' => 'account.welcome',
            'language_code' => 'hy',
            'channel' => 'in_app',
        ]);
    }

    public function test_mutation_endpoints_reject_unknown_language_codes(): void
    {
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();

        $this->postJson(
            '/api/localization/ui-translations',
            [
                'language_code' => 'zz-ZZ',
                'translations' => [
                    'phase8.step3.should_not_save' => 'Nope',
                ],
            ],
            $this->bearer($user)
        )
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->patchJson(
            '/api/localization/templates/account.welcome',
            [
                'lang' => 'zz_ZZ',
                'channel' => 'in_app',
                'title_template' => 'T',
                'body_template' => 'B',
            ],
            $this->bearer($user)
        )
            ->assertStatus(422)
            ->assertJsonPath('message', 'Validation failed')
            ->assertJsonPath('errors.lang.0', 'Invalid or unsupported language_code.');
    }
}
