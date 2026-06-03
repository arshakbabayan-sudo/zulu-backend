<?php

namespace Tests\Unit\Services\Vouchers;

use App\Models\Company;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Vouchers\VoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoucherServiceTest extends TestCase
{
    use RefreshDatabase;

    private VoucherService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new VoucherService;
    }

    public function test_issue_for_order_creates_one_voucher_per_top_level_item(): void
    {
        $order = $this->createOrderWithItems([
            ['item_type' => 'hotel', 'parent_index' => null],
            ['item_type' => 'transfer', 'parent_index' => null],
        ]);

        $vouchers = $this->service->issueForOrder($order);

        $this->assertCount(2, $vouchers);
        $this->assertSame(2, Voucher::query()->where('order_id', $order->id)->count());
        $this->assertSame(['hotel', 'transfer'], $vouchers->pluck('service_type')->all());
    }

    public function test_issue_for_order_skips_child_items(): void
    {
        $order = $this->createOrderWithItems([
            ['item_type' => 'package', 'parent_index' => null],
            ['item_type' => 'hotel', 'parent_index' => 0],
            ['item_type' => 'transfer', 'parent_index' => 0],
        ]);

        $vouchers = $this->service->issueForOrder($order);

        $this->assertCount(1, $vouchers, 'Only the top-level package item should have a voucher.');
        $this->assertSame('package', $vouchers->first()->service_type);
    }

    public function test_issue_for_order_is_idempotent(): void
    {
        $order = $this->createOrderWithItems([
            ['item_type' => 'hotel', 'parent_index' => null],
        ]);

        $first = $this->service->issueForOrder($order);
        $second = $this->service->issueForOrder($order);

        $this->assertSame(1, Voucher::query()->where('order_id', $order->id)->count());
        $this->assertSame($first->first()->id, $second->first()->id);
    }

    public function test_voucher_number_format_uses_order_short_and_sequence(): void
    {
        $order = $this->createOrderWithItems([
            ['item_type' => 'hotel', 'parent_index' => null],
            ['item_type' => 'flight', 'parent_index' => null],
        ]);

        $vouchers = $this->service->issueForOrder($order);

        $expectedShort = strtoupper(substr(str_replace('-', '', $order->id), 0, 8));
        $this->assertSame("V-{$expectedShort}-001", $vouchers[0]->voucher_number);
        $this->assertSame("V-{$expectedShort}-002", $vouchers[1]->voucher_number);
    }

    public function test_qr_token_is_unique_64_char_hex(): void
    {
        $order = $this->createOrderWithItems([
            ['item_type' => 'hotel', 'parent_index' => null],
            ['item_type' => 'flight', 'parent_index' => null],
        ]);

        $vouchers = $this->service->issueForOrder($order);

        foreach ($vouchers as $v) {
            $this->assertSame(64, strlen($v->qr_token));
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $v->qr_token);
        }
        $this->assertNotSame($vouchers[0]->qr_token, $vouchers[1]->qr_token);
    }

    public function test_language_falls_back_to_en_when_metadata_missing_or_invalid(): void
    {
        $order = $this->createOrderWithItems(
            [['item_type' => 'hotel', 'parent_index' => null]],
            ['metadata' => ['language' => 'fr']] // not in HY/EN/RU
        );

        $vouchers = $this->service->issueForOrder($order);

        $this->assertSame('en', $vouchers->first()->language);
    }

    public function test_language_uses_order_metadata_when_valid(): void
    {
        $order = $this->createOrderWithItems(
            [['item_type' => 'hotel', 'parent_index' => null]],
            ['metadata' => ['language' => 'hy']]
        );

        $vouchers = $this->service->issueForOrder($order);

        $this->assertSame('hy', $vouchers->first()->language);
    }

    public function test_holder_name_resolved_from_first_passenger(): void
    {
        $order = $this->createOrderWithItems([[
            'item_type' => 'hotel',
            'parent_index' => null,
            'passenger_data' => [
                ['name' => 'Hovhannes Tumanyan', 'passport' => 'AM999'],
            ],
        ]]);

        $vouchers = $this->service->issueForOrder($order);

        $this->assertSame('Hovhannes Tumanyan', $vouchers->first()->holder_name);
        $this->assertSame('AM999', $vouchers->first()->holder_passport);
    }

    public function test_holder_name_falls_back_to_order_user_name(): void
    {
        $user = User::factory()->create(['name' => 'Sayat Nova']);
        $order = $this->createOrderWithItems(
            [['item_type' => 'hotel', 'parent_index' => null]],
            ['user_id' => $user->id]
        );

        $vouchers = $this->service->issueForOrder($order);

        $this->assertSame('Sayat Nova', $vouchers->first()->holder_name);
        $this->assertNull($vouchers->first()->holder_passport);
    }

    public function test_reissue_marks_original_and_creates_new_voucher(): void
    {
        $order = $this->createOrderWithItems([['item_type' => 'hotel', 'parent_index' => null]]);
        $original = $this->service->issueForOrder($order)->first();

        $reissued = $this->service->reissue($original, ['holder_name' => 'Corrected Name']);

        $this->assertSame('reissued', $original->fresh()->status);
        $this->assertSame('issued', $reissued->status);
        $this->assertSame($original->id, $reissued->reissued_from_id);
        $this->assertSame('Corrected Name', $reissued->holder_name);
        $this->assertNotSame($original->qr_token, $reissued->qr_token);
        $this->assertStringContainsString('-R', $reissued->voucher_number);
    }

    public function test_issue_creates_in_app_notification_for_order_user(): void
    {
        $user = User::factory()->create();
        $order = $this->createOrderWithItems(
            [['item_type' => 'hotel', 'parent_index' => null]],
            ['user_id' => $user->id]
        );

        (new VoucherService)->issueForOrder($order);

        $notification = Notification::query()
            ->where('user_id', $user->id)
            ->where('event_type', 'voucher.issued')
            ->first();

        $this->assertNotNull($notification, 'voucher.issued notification should be created.');
        $this->assertSame('unread', $notification->status);
        $this->assertStringContainsString('voucher', strtolower($notification->message));
    }

    public function test_issue_skips_notification_when_user_id_null(): void
    {
        $order = $this->createOrderWithItems(
            [['item_type' => 'hotel', 'parent_index' => null]],
            ['user_id' => null, 'buyer_type' => 'guest']
        );

        (new VoucherService)->issueForOrder($order);

        $this->assertSame(0, Notification::query()->count());
    }

    public function test_void_changes_status_to_void(): void
    {
        $order = $this->createOrderWithItems([['item_type' => 'hotel', 'parent_index' => null]]);
        $voucher = $this->service->issueForOrder($order)->first();

        $voided = $this->service->void($voucher);

        $this->assertSame('void', $voided->status);
        $this->assertSame('void', $voucher->fresh()->status);
        $this->assertFalse($voucher->fresh()->isUsable());
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $orderOverrides
     */
    private function createOrderWithItems(array $items, array $orderOverrides = []): Order
    {
        $company = Company::query()->create([
            'name' => 'VS Test Co '.str()->random(6),
            'type' => 'operator',
        ]);

        $user = User::factory()->create();

        $order = Order::query()->create(array_merge([
            'order_number' => 'ZULU-VS-'.str()->random(4),
            'user_id' => $user->id,
            'company_id' => $company->id,
            'buyer_type' => 'client',
            'status' => 'paid',
            'currency' => 'EUR',
            'subtotal' => 100.00,
            'tax' => 0.00,
            'total' => 100.00,
            'metadata' => ['source' => 'voucher-service-test'],
        ], $orderOverrides));

        $createdIds = [];
        foreach ($items as $idx => $item) {
            $parentId = null;
            if (isset($item['parent_index']) && $item['parent_index'] !== null) {
                $parentId = $createdIds[$item['parent_index']] ?? null;
            }

            $created = OrderItem::query()->create([
                'order_id' => $order->id,
                'item_type' => $item['item_type'],
                'item_id' => $item['item_id'] ?? null,
                'parent_item_id' => $parentId,
                'currency' => 'EUR',
                'unit_price' => 50.00,
                'total' => 50.00,
                'status' => 'confirmed',
                'passenger_data' => $item['passenger_data'] ?? null,
                'service_snapshot' => $item['service_snapshot'] ?? ['note' => 'test'],
                'date_from' => $item['date_from'] ?? null,
                'date_to' => $item['date_to'] ?? null,
            ]);

            $createdIds[$idx] = $created->id;
        }

        return $order->fresh(['items', 'user']);
    }
}
