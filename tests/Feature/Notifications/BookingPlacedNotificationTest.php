<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Company;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Roadmap "Ամրագրման ծանուցում վաճառող-ընկերությանը" (2026-06-07) — when a B2C
 * customer places a booking, every staff member of the company the booking is
 * attributed to (the partner the customer picked = agent_company_id, else the
 * supplying operator = company_id) gets an in-app `booking.created` notification
 * on the admin top-bar bell.
 *
 * Arshak's product decision (2026-06-08): notify ALL staff of that company.
 */
class BookingPlacedNotificationTest extends TestCase
{
    use RefreshDatabase;

    private int $roleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->roleId = (int) Role::query()->firstOrCreate(['name' => 'company_admin'])->id;
    }

    private function company(string $type): Company
    {
        return Company::query()->create(['name' => $type.'-'.uniqid(), 'type' => $type]);
    }

    private function staff(Company $company, ?string $lang = null): User
    {
        $user = User::factory()->create(['preferred_language' => $lang]);
        $user->companies()->attach($company->id, ['role_id' => $this->roleId]);

        return $user->fresh();
    }

    private function customer(string $name = 'Պողոս Պողոսյան'): User
    {
        return User::factory()->create(['name' => $name]);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function order(array $attrs, ?int $soldByUserId = null): Order
    {
        $order = Order::query()->create(array_merge([
            'order_number' => 'BKG-'.strtoupper(substr((string) str()->uuid(), 0, 8)),
            'buyer_type' => 'client',
            'status' => 'pending_payment',
            'currency' => 'USD',
            'subtotal' => 100,
            'tax' => 0,
            'total' => 100,
        ], $attrs));

        if ($soldByUserId !== null) {
            $order->forceFill(['sold_by_user_id' => $soldByUserId])->save();
        }

        return $order->fresh();
    }

    private function bookingNotifications(int $userId): int
    {
        return Notification::query()
            ->where('user_id', $userId)
            ->where('event_type', 'booking.created')
            ->count();
    }

    public function test_all_staff_of_the_chosen_agent_company_are_notified(): void
    {
        $operator = $this->company('operator');
        $agent = $this->company('agency');
        $customer = $this->customer();

        $agentStaffA = $this->staff($agent, 'hy');
        $agentStaffB = $this->staff($agent, 'en');
        $operatorStaff = $this->staff($operator);
        $unrelatedStaff = $this->staff($this->company('operator'));

        $order = $this->order([
            'user_id' => $customer->id,
            'company_id' => $operator->id,
            'agent_company_id' => $agent->id, // customer picked this agent partner
        ]);

        app(NotificationService::class)->notifyCompanyStaffOfBooking($order);

        // Both agent staff notified; the operator and an unrelated company are not.
        $this->assertSame(1, $this->bookingNotifications($agentStaffA->id));
        $this->assertSame(1, $this->bookingNotifications($agentStaffB->id));
        $this->assertSame(0, $this->bookingNotifications($operatorStaff->id));
        $this->assertSame(0, $this->bookingNotifications($unrelatedStaff->id));

        $notif = Notification::query()
            ->where('user_id', $agentStaffA->id)
            ->where('event_type', 'booking.created')
            ->firstOrFail();
        $this->assertSame((int) $agent->id, (int) $notif->related_company_id);
        $this->assertSame('unread', $notif->status);
        $this->assertSame('high', $notif->priority);
        // hy recipient → Armenian copy, with the customer name + order number.
        $this->assertSame('Նոր ամրագրում', $notif->title);
        $this->assertStringContainsString('Պողոս Պողոսյան', (string) $notif->message);
        $this->assertStringContainsString((string) $order->order_number, (string) $notif->message);
    }

    public function test_no_notification_when_a_seller_employee_placed_the_booking(): void
    {
        $operator = $this->company('operator');
        $agent = $this->company('agency');
        $customer = $this->customer();
        $agentStaff = $this->staff($agent);

        // sold_by_user_id set → a company employee made the sale; they already know.
        $order = $this->order([
            'user_id' => $customer->id,
            'company_id' => $operator->id,
            'agent_company_id' => $agent->id,
        ], soldByUserId: $agentStaff->id);

        app(NotificationService::class)->notifyCompanyStaffOfBooking($order);

        $this->assertSame(0, $this->bookingNotifications($agentStaff->id));
    }

    public function test_operator_staff_notified_when_no_agent_attribution(): void
    {
        $operator = $this->company('operator');
        $agent = $this->company('agency');
        $customer = $this->customer();
        $operatorStaff = $this->staff($operator);
        $agentStaff = $this->staff($agent);

        // Direct B2C purchase from the operator (no chosen agent partner).
        $order = $this->order([
            'user_id' => $customer->id,
            'company_id' => $operator->id,
            'agent_company_id' => null,
        ]);

        app(NotificationService::class)->notifyCompanyStaffOfBooking($order);

        $this->assertSame(1, $this->bookingNotifications($operatorStaff->id));
        $this->assertSame(0, $this->bookingNotifications($agentStaff->id));
    }

    public function test_no_notification_without_any_attributed_company(): void
    {
        $customer = $this->customer();
        $loneStaff = $this->staff($this->company('operator'));

        $order = $this->order([
            'user_id' => $customer->id,
            'company_id' => null,
            'agent_company_id' => null,
        ]);

        app(NotificationService::class)->notifyCompanyStaffOfBooking($order);

        $this->assertSame(0, $this->bookingNotifications($loneStaff->id));
    }
}
