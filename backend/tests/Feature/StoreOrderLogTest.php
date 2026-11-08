<?php

namespace Tests\Feature;

use App\Enums\DeliveryMethod;
use App\Enums\PaymentMethod;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StoreOrderLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_log_decodes_unicode_and_replaces_it_on_repeat_requests(): void
    {
        $disk = Storage::fake('local');
        $order = Order::create([
            'customer_name' => 'Тестовый клиент',
            'customer_email' => 'customer@example.test',
            'customer_phone' => '+79001234567',
            'delivery_method' => DeliveryMethod::Pickup,
            'payment_method' => PaymentMethod::Card,
        ]);
        $url = "/api/v1/log/{$order->id}";
        $path = "order-logs/{$order->id}.json";
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://namenecklace.ru'];
        $body = <<<'JSON'
        {
          "\u043c\u0435\u0442\u0430\u043b\u043b": "\u0420\u043e\u0434\u0438\u0439\uD83D\uDE00",
          "literal": "\\u0420", "escaped": "\u0022\u005c\u000a",
          "id": 123456789012345678901, "amount": 1.0
        }
        JSON;
        $saved = <<<'JSON'
        {
          "металл": "Родий😀",
          "literal": "\\u0420", "escaped": "\"\\\n",
          "id": 123456789012345678901, "amount": 1.0
        }
        JSON;

        $this->call('POST', $url, server: $headers, content: $body)
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://namenecklace.ru');
        $this->assertSame($saved, $disk->get($path));

        $this->call('POST', $url, server: $headers, content: '{"event":}')
            ->assertBadRequest();
        $this->assertSame($saved, $disk->get($path));

        $replacement = '[{"event":"paid"}]';
        $this->call('POST', $url, server: $headers, content: $replacement)
            ->assertNoContent();
        $this->assertSame($replacement, $disk->get($path));
        $this->assertSame([$path], $disk->allFiles());
    }

    public function test_a_log_cannot_be_saved_for_an_unknown_order(): void
    {
        $disk = Storage::fake('local');

        $this->postJson('/api/v1/log/999', ['event' => 'paid'])->assertNotFound();

        $this->assertSame([], $disk->allFiles());
    }
}
