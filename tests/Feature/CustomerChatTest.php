<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\Company;
use App\Models\Notification;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompany;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Roadmap §4 — customer ↔ platform-support chat.
 */
class CustomerChatTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function authHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function resetAuthGuards(): void
    {
        $this->app['auth']->forgetGuards();
    }

    private function makeCustomer(string $email = 'chat-customer@tdd.local'): User
    {
        return User::query()->create([
            'name' => 'Chat Customer',
            'email' => $email,
            'password' => bcrypt('password'),
            'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function makeOperatorWithChatView(Company $company): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'tdd_chat_operator']);
        $ids = Permission::query()->whereIn('name', ['chat.view'])->pluck('id')->all();
        $role->permissions()->sync($ids);

        $user = User::query()->create([
            'name' => 'Chat Operator',
            'email' => 'chat-operator@tdd.local',
            'password' => bcrypt('password'),
            'status' => User::STATUS_ACTIVE,
        ]);

        UserCompany::query()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role_id' => $role->id,
        ]);

        return $user;
    }

    public function test_customer_chat_endpoints_require_auth(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $this->getJson('/api/customer/chat')->assertUnauthorized();
        $this->getJson('/api/customer/chat/messages')->assertUnauthorized();
        $this->postJson('/api/customer/chat/messages', ['body' => 'hi'])->assertUnauthorized();
    }

    public function test_first_message_creates_single_thread_and_notifies_staff_once(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $customer = $this->makeCustomer();

        // Before the first message there is no thread.
        $this->getJson('/api/customer/chat', $this->authHeaders($customer))
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->resetAuthGuards();
        $this->postJson('/api/customer/chat/messages', ['body' => 'Բարև, հարց ունեմ'], $this->authHeaders($customer))
            ->assertStatus(201)
            ->assertJsonPath('data.mine', true);

        $this->resetAuthGuards();
        $this->postJson('/api/customer/chat/messages', ['body' => 'Երկրորդ հաղորդագրություն'], $this->authHeaders($customer))
            ->assertStatus(201);

        $threads = ChatConversation::query()
            ->where('type', 'customer')
            ->where('customer_user_id', $customer->id)
            ->get();
        $this->assertCount(1, $threads);
        $this->assertNull($threads->first()->company_id);

        // Staff notified once (thread creation), not per message.
        $this->assertSame(
            1,
            Notification::query()->where('user_id', $admin->id)->where('type', 'chat')->count()
        );

        $this->resetAuthGuards();
        $this->getJson('/api/customer/chat', $this->authHeaders($customer))
            ->assertOk()
            ->assertJsonPath('data.id', $threads->first()->id)
            ->assertJsonPath('data.unread', 0);
    }

    public function test_platform_staff_sees_thread_and_full_reply_round_trip(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $customer = $this->makeCustomer();

        $this->postJson('/api/customer/chat/messages', ['body' => 'Օգնեք խնդրում եմ'], $this->authHeaders($customer))
            ->assertStatus(201);

        // Timestamps have second precision — step past the customer's
        // last_read_at so the staff reply lands strictly later.
        $this->travel(2)->seconds();

        $this->resetAuthGuards();

        // Staff conversation list contains the customer thread with badge data.
        $convs = $this->getJson('/api/chat/conversations', $this->authHeaders($admin))
            ->assertOk()
            ->json('data');
        $thread = collect($convs)->firstWhere('is_customer', true);
        $this->assertNotNull($thread);
        $this->assertSame('Chat Customer', $thread['title']);
        $this->assertSame(1, $thread['unread']);
        $this->assertSame('Chat Customer', $thread['customer']['name']);

        // Staff opens the thread (marks read) and replies.
        $this->resetAuthGuards();
        $this->getJson('/api/chat/conversations/'.$thread['id'].'/messages', $this->authHeaders($admin))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->resetAuthGuards();
        $this->postJson(
            '/api/chat/conversations/'.$thread['id'].'/messages',
            ['body' => 'Բարև, լսում եմ Ձեզ'],
            $this->authHeaders($admin)
        )->assertStatus(201);

        // Unread cleared for staff after reading/replying.
        $this->resetAuthGuards();
        $convsAfter = $this->getJson('/api/chat/conversations', $this->authHeaders($admin))->json('data');
        $this->assertSame(0, collect($convsAfter)->firstWhere('is_customer', true)['unread']);

        // Customer sees the staff reply; unread shows then clears after poll.
        $this->resetAuthGuards();
        $this->getJson('/api/customer/chat', $this->authHeaders($customer))
            ->assertOk()
            ->assertJsonPath('data.unread', 1);

        $this->resetAuthGuards();
        $messages = $this->getJson('/api/customer/chat/messages', $this->authHeaders($customer))
            ->assertOk()
            ->json('data');
        $this->assertCount(2, $messages);
        $this->assertFalse($messages[1]['mine']);
        $this->assertSame('Բարև, լսում եմ Ձեզ', $messages[1]['body']);

        $this->resetAuthGuards();
        $this->getJson('/api/customer/chat', $this->authHeaders($customer))
            ->assertOk()
            ->assertJsonPath('data.unread', 0);
    }

    public function test_tenant_operator_cannot_see_or_touch_customer_threads(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $operator = $this->makeOperatorWithChatView($company);
        $customer = $this->makeCustomer();

        $this->postJson('/api/customer/chat/messages', ['body' => 'Գաղտնի հարց'], $this->authHeaders($customer))
            ->assertStatus(201);
        $threadId = ChatConversation::query()->where('type', 'customer')->value('id');

        $this->resetAuthGuards();

        $convs = $this->getJson('/api/chat/conversations', $this->authHeaders($operator))
            ->assertOk()
            ->json('data');
        $this->assertNull(collect($convs)->firstWhere('is_customer', true));

        $this->resetAuthGuards();
        $this->getJson('/api/chat/conversations/'.$threadId.'/messages', $this->authHeaders($operator))
            ->assertStatus(403);

        $this->resetAuthGuards();
        $this->postJson('/api/chat/conversations/'.$threadId.'/messages', ['body' => 'intrude'], $this->authHeaders($operator))
            ->assertStatus(403);
    }

    public function test_plain_customer_cannot_reach_staff_chat_routes(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $customer = $this->makeCustomer();

        $this->getJson('/api/chat/conversations', $this->authHeaders($customer))
            ->assertStatus(403);
    }
}
