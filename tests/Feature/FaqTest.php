<?php

namespace Tests\Feature;

use App\Models\Faq;
use App\Models\User;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FaqTest extends TestCase
{
    use RefreshDatabase;

    private function makeFaq(array $attrs = []): Faq
    {
        return Faq::query()->create(array_merge([
            'category' => 'general',
            'question_hy' => 'Հարց', 'question_ru' => 'Вопрос', 'question_en' => 'Question',
            'answer_hy' => 'Պատասխան', 'answer_ru' => 'Ответ', 'answer_en' => 'Answer',
            'sort_order' => 0,
            'is_active' => true,
        ], $attrs));
    }

    public function test_public_endpoint_returns_active_localized_faqs(): void
    {
        $this->makeFaq(['question_en' => 'How to book?', 'sort_order' => 1]);
        $this->makeFaq(['is_active' => false, 'question_en' => 'Hidden']);

        $res = $this->getJson('/api/faqs?lang=en');

        $res->assertOk()->assertJsonPath('success', true);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('How to book?', $data[0]['question']);
        $this->assertSame('Answer', $data[0]['answer']);
    }

    public function test_public_endpoint_localizes_to_requested_language(): void
    {
        $this->makeFaq();

        $hy = $this->getJson('/api/faqs?lang=hy')->json('data.0.question');
        $ru = $this->getJson('/api/faqs?lang=ru')->json('data.0.question');

        $this->assertSame('Հարց', $hy);
        $this->assertSame('Вопрос', $ru);
    }

    public function test_admin_crud_requires_platform_admin(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/platform-admin/faqs')->assertStatus(403);
    }

    public function test_admin_can_create_update_delete_faq(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        Sanctum::actingAs($admin);

        $created = $this->postJson('/api/platform-admin/faqs', [
            'category' => 'booking',
            'question_hy' => 'Ինչպե՞ս ամրագրել', 'question_ru' => 'Как забронировать', 'question_en' => 'How to book',
            'answer_hy' => 'Պարզ', 'answer_ru' => 'Просто', 'answer_en' => 'Easily',
            'sort_order' => 2,
        ])->assertStatus(201)->json('data');

        $id = $created['id'];
        $this->assertDatabaseHas('faqs', ['id' => $id, 'category' => 'booking']);

        $this->getJson('/api/platform-admin/faqs')->assertOk()->assertJsonPath('data.0.question_en', 'How to book');

        $this->patchJson("/api/platform-admin/faqs/{$id}", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);

        $this->deleteJson("/api/platform-admin/faqs/{$id}")->assertOk();
        $this->assertDatabaseMissing('faqs', ['id' => $id]);
    }

    public function test_admin_store_validates_required_languages(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        Sanctum::actingAs(User::query()->where('email', 'admin@zulu.local')->firstOrFail());

        $this->postJson('/api/platform-admin/faqs', ['category' => 'general', 'question_en' => 'Only EN'])
            ->assertStatus(422);
    }
}
