<?php

namespace Tests\Feature\Webhooks;

use App\Models\Company;
use App\Services\Webhooks\WebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class WebhookServiceTest extends TestCase
{
    use RefreshDatabase;

    private WebhookService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(WebhookService::class);
    }

    public function test_subscribe_creates_subscription_with_secret(): void
    {
        $company = $this->makeCompany();

        $sub = $this->service->subscribe($company, [
            'target_url' => 'https://example.com/webhook',
            'events' => ['order.paid', 'voucher.issued'],
            'description' => 'Test',
        ]);

        $this->assertSame('https://example.com/webhook', $sub->target_url);
        $this->assertCount(2, $sub->events);
        $this->assertNotNull($sub->secret);
        $this->assertSame(64, strlen($sub->secret));
        $this->assertTrue($sub->active);
    }

    public function test_subscribe_rejects_invalid_url(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->subscribe($this->makeCompany(), ['target_url' => 'not-a-url', 'events' => ['order.paid']]);
    }

    public function test_subscribe_rejects_unsupported_event(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->subscribe($this->makeCompany(), [
            'target_url' => 'https://x.com', 'events' => ['bogus.event'],
        ]);
    }

    public function test_subscribe_rejects_empty_events(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->subscribe($this->makeCompany(), [
            'target_url' => 'https://x.com', 'events' => [],
        ]);
    }

    public function test_dispatch_sends_to_subscribed_only(): void
    {
        Http::fake(fn () => Http::response('ok', 200));

        $coA = $this->makeCompany();
        $coB = $this->makeCompany();
        $this->service->subscribe($coA, ['target_url' => 'https://a.example.com/hook', 'events' => ['order.paid']]);
        $this->service->subscribe($coB, ['target_url' => 'https://b.example.com/hook', 'events' => ['voucher.issued']]); // not order.paid

        $count = $this->service->dispatch('order.paid', ['order_id' => 'abc']);

        $this->assertSame(1, $count);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://a.example.com/hook');
    }

    public function test_dispatch_marks_success_on_2xx(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $sub = $this->service->subscribe($this->makeCompany(), [
            'target_url' => 'https://example.com/hook', 'events' => ['order.paid'],
        ]);

        $delivery = $this->service->deliver($sub, 'order.paid', ['order_id' => 'x']);

        $this->assertSame('success', $delivery->status);
        $this->assertSame(200, $delivery->http_status);
        $this->assertNotNull($delivery->succeeded_at);
        $this->assertSame(1, $delivery->attempt_count);
    }

    public function test_dispatch_marks_pending_on_5xx_until_max_attempts(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);
        $sub = $this->service->subscribe($this->makeCompany(), [
            'target_url' => 'https://example.com/hook', 'events' => ['order.paid'],
        ]);

        $delivery = $this->service->deliver($sub, 'order.paid', ['x' => 1]);
        $this->assertSame('pending', $delivery->status);
        $this->assertSame(500, $delivery->http_status);

        // Attempt 4 more times to reach MAX_ATTEMPTS=5
        for ($i = 0; $i < 4; $i++) {
            $delivery = $this->service->attempt($delivery->fresh());
        }

        $this->assertSame('failed', $delivery->status);
        $this->assertSame(5, $delivery->attempt_count);
    }

    public function test_signature_header_is_hmac_sha256_of_body(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $sub = $this->service->subscribe($this->makeCompany(), [
            'target_url' => 'https://example.com/hook', 'events' => ['order.paid'],
        ]);

        $this->service->deliver($sub, 'order.paid', ['x' => 1]);

        Http::assertSent(function (Request $req) use ($sub) {
            $sigHeader = $req->header('X-Zulu-Signature')[0] ?? '';
            $body = $req->body();
            $expected = 'sha256='.hash_hmac('sha256', $body, $sub->secret);

            return $sigHeader === $expected;
        });
    }

    public function test_verify_signature_succeeds_with_correct_secret(): void
    {
        $payload = '{"hello":"world"}';
        $secret = 'mysecret';
        $sig = 'sha256='.hash_hmac('sha256', $payload, $secret);

        $this->assertTrue($this->service->verifySignature($payload, $secret, $sig));
        $this->assertFalse($this->service->verifySignature($payload, $secret, 'sha256=wrong'));
        $this->assertFalse($this->service->verifySignature($payload, $secret, 'no-prefix'));
    }

    public function test_unsubscribe_only_owners(): void
    {
        $coA = $this->makeCompany();
        $coB = $this->makeCompany();
        $sub = $this->service->subscribe($coA, ['target_url' => 'https://x.com', 'events' => ['order.paid']]);

        $this->assertFalse($this->service->unsubscribe($coB, $sub->id));
        $this->assertTrue($this->service->unsubscribe($coA, $sub->id));
    }

    private function makeCompany(): Company
    {
        return Company::query()->create([
            'name' => 'WH '.str()->random(4),
            'type' => 'operator',
        ]);
    }
}
