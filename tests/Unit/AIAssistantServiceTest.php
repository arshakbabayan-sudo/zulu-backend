<?php

namespace Tests\Unit;

use App\Services\AI\AIChatbotService;
use App\Services\AI\AIRecommendationService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AIAssistantServiceTest extends TestCase
{
    public function test_chatbot_reports_unconfigured_when_api_key_missing(): void
    {
        config()->set('services.anthropic.api_key', '');
        putenv('ANTHROPIC_API_KEY=');

        $bot = new AIChatbotService;
        $this->assertFalse($bot->isConfigured());
    }

    public function test_chatbot_returns_fallback_reply_without_key(): void
    {
        config()->set('services.anthropic.api_key', '');
        putenv('ANTHROPIC_API_KEY=');

        $bot = new AIChatbotService;
        $result = $bot->send('Hello', [], null, 'en');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('temporarily unavailable', $result['reply']);
    }

    public function test_chatbot_armenian_fallback_uses_armenian_message(): void
    {
        config()->set('services.anthropic.api_key', '');
        putenv('ANTHROPIC_API_KEY=');

        $bot = new AIChatbotService;
        $result = $bot->send('Բարև', [], null, 'hy');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Օգնականը', $result['reply']);
    }

    public function test_chatbot_rejects_empty_query(): void
    {
        $bot = new AIChatbotService;
        $result = $bot->send('   ', [], null, 'en');

        $this->assertFalse($result['success']);
        $this->assertSame('Empty query', $result['error']);
    }

    public function test_chatbot_appends_assistant_reply_to_history_on_success(): void
    {
        config()->set('services.anthropic.api_key', 'test-key');

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Hi! How can I help?']],
            ], 200),
        ]);

        $bot = new AIChatbotService;
        $result = $bot->send('Hello', [], null, 'en');

        $this->assertTrue($result['success']);
        $this->assertSame('Hi! How can I help?', $result['reply']);
        $this->assertCount(2, $result['history']);
        $this->assertSame('user', $result['history'][0]['role']);
        $this->assertSame('assistant', $result['history'][1]['role']);
    }

    public function test_chatbot_handles_upstream_500_with_fallback(): void
    {
        config()->set('services.anthropic.api_key', 'test-key');

        Http::fake([
            'api.anthropic.com/*' => Http::response('boom', 500),
        ]);

        $bot = new AIChatbotService;
        $result = $bot->send('Hello', [], null, 'en');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('temporarily unavailable', $result['reply']);
    }

    public function test_recommendation_service_reports_ai_availability_from_key(): void
    {
        config()->set('services.anthropic.api_key', '');
        putenv('ANTHROPIC_API_KEY=');

        $svc = new AIRecommendationService;
        $this->assertFalse($svc->isAiAvailable());

        config()->set('services.anthropic.api_key', 'present');
        $svc2 = new AIRecommendationService;
        $this->assertTrue($svc2->isAiAvailable());
    }
}
