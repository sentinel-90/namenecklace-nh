<?php

namespace Tests\Feature\Filament;

use App\Enums\DeliveryMethod;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OrderStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_order_totals_for_moscow_calendar_periods(): void
    {
        $this->travelTo(Carbon::parse('2026-07-02 10:00:00', 'Europe/Moscow'));
        $this->actingAs(User::factory()->create());

        foreach ([
            '2026-06-28 20:59:59' => 100000,
            '2026-06-28 21:00:00' => 10000,
            '2026-06-30 21:00:00' => 20000,
            '2026-07-01 21:00:00' => 30050,
            '2026-07-02 07:00:00' => null,
            '2026-07-02 07:00:01' => 100000,
        ] as $createdAt => $amountKopecks) {
            $order = new Order([
                'delivery_method' => DeliveryMethod::Pickup,
                'payment_method' => PaymentMethod::Card,
                'amount_kopecks' => $amountKopecks,
                'customer_name' => 'Тестовый клиент',
                'customer_email' => 'order@example.test',
                'customer_phone' => '+7 (900) 000-00-00',
            ]);
            $order->created_at = $createdAt;
            $order->save();
        }

        $this->get('/admin')->assertOk()->assertSeeTextInOrder([
            'За сегодня', '300,50', '2 заказа',
            'За эту неделю', '600,50', '4 заказа',
            'За этот месяц', '500,50', '3 заказа',
        ]);
    }

    public function test_dashboard_shows_zero_totals_when_there_are_no_orders(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin')->assertOk()->assertSeeTextInOrder([
            'За сегодня', '0,00', '0 заказов',
            'За эту неделю', '0,00', '0 заказов',
            'За этот месяц', '0,00', '0 заказов',
        ]);
    }
}
