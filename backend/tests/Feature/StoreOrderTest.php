<?php

namespace Tests\Feature;

use App\Enums\DeliveryMethod;
use App\Enums\PaymentMethod;
use App\Models\CatalogItem;
use App\Models\CatalogSection;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class StoreOrderTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/orders';

    private const ORIGIN = 'https://namenecklace.ru';

    private const IMAGE = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/a0kAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_storefront_preflight_allows_json_post_without_credentials(): void
    {
        $response = $this->options(self::ENDPOINT, [], [
            'Origin' => self::ORIGIN,
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type',
        ]);

        $response->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', self::ORIGIN)
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
        $this->assertStringContainsString('POST', $response->headers->get('Access-Control-Allow-Methods'));
        $this->assertStringContainsString('content-type', strtolower($response->headers->get('Access-Control-Allow-Headers')));
    }

    public function test_a_different_origin_is_not_allowed_by_cors(): void
    {
        $otherOrigin = 'https://other.example.test';

        $response = $this->options(self::ENDPOINT, [], [
            'Origin' => $otherOrigin,
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type',
        ]);

        $this->assertNotSame($otherOrigin, $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_the_storefront_can_create_an_order_with_an_image_and_stone_positions(): void
    {
        $payload = $this->payload();

        $response = $this->postJson(self::ENDPOINT, $payload, ['Origin' => self::ORIGIN]);

        $order = Order::query()->sole();
        $response->assertCreated()
            ->assertHeader('Access-Control-Allow-Origin', self::ORIGIN)
            ->assertExactJson(['id' => $order->id, 'status' => 'new']);
        $this->assertSame(2140000, $order->amount_kopecks);
        $this->assertSame(PaymentMethod::Card, $order->payment_method);
        $this->assertSame(DeliveryMethod::MoscowWithinRingRoad, $order->delivery_method);
        $this->assertSame($payload['address'], $order->delivery_address);
        $this->assertNull($order->promo_code);
        $this->assertSame('Julia ', $order->product_configuration['text']);
        $this->assertSame($payload['metaltype'], $order->product_configuration['metaltype']);
        $this->assertFalse($order->product_configuration['sparking']);
        $this->assertSame($payload['rightimg'], $order->product_configuration['rightimg']);
        $this->assertSame($payload['stones'], $order->product_configuration['stones']);
        Storage::disk('local')->assertExists($order->product_image_path);
        $this->assertSame(base64_decode(self::IMAGE), Storage::disk('local')->get($order->product_image_path));
    }

    public function test_pickup_without_a_chain_and_payment_in_installments_are_supported(): void
    {
        $this->postJson(self::ENDPOINT, array_replace($this->payload(), [
            'delivery' => 'Самовывоз',
            'payment' => 'isDolyame',
            'price' => 125.05,
            'noCircuit' => true,
        ]))->assertCreated();

        $order = Order::query()->sole();
        $this->assertSame(PaymentMethod::Dolyame, $order->payment_method);
        $this->assertSame(12505, $order->amount_kopecks);
        $this->assertNull($order->delivery_address);
        $this->assertTrue($order->product_configuration['noCircuit']);
        $this->assertNull($order->product_configuration['circuit']);
        $this->assertSame('40 см', $order->product_configuration['circuitLength']);
    }

    #[TestWith([''])]
    #[TestWith(['invalid!'])]
    #[TestWith(['data:image/png;base64,'.self::IMAGE])]
    #[TestWith(['bm90IGFuIGltYWdl'])]
    public function test_missing_or_invalid_base64_images_are_rejected(string $attachment): void
    {
        $this->postJson(self::ENDPOINT, array_replace($this->payload(), ['attachment' => $attachment]), ['Origin' => self::ORIGIN])
            ->assertUnprocessable()
            ->assertHeader('Access-Control-Allow-Origin', self::ORIGIN)
            ->assertJsonValidationErrors('attachment');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_the_api_rejects_invalid_delivery_data_and_external_image_links(): void
    {
        $payload = $this->payload();
        unset($payload['stones'][1]['color']);
        $payload['address'] = '';
        $payload['payment'] = 'unknown';
        $payload['charmimg'] = 'https://namenecklace.ru.example.test/charm.png';

        $this->postJson(self::ENDPOINT, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['address', 'payment', 'charmimg', 'stones.1.color']);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_the_api_rejects_requests_that_are_not_json(): void
    {
        $this->post(self::ENDPOINT, $this->payload())->assertStatus(415);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_an_order_saves_catalog_details_and_the_total_from_the_request(): void
    {
        $figure = $this->catalogItem('silver', 'right_figure', ['top' => true]);
        $chain = $this->catalogItem('silver', 'chain', ['price_kopecks' => 125075]);

        $this->postJson(self::ENDPOINT, array_replace($this->payload(), [
            'composition' => ['section' => 'silver', 'right_figure_id' => $figure->id, 'bottom_figure_id' => null, 'chain_id' => $chain->id],
            'metaltype' => 'gold',
            'rightimg' => 'https://example.test/legacy.png',
            'noCircuit' => true,
        ]))->assertCreated();

        $chain->delete();
        $order = Order::query()->sole();
        $this->assertSame(2140000, $order->amount_kopecks);
        $product = $order->product_configuration;
        $this->assertSame('silver', $product['metaltype']);
        $this->assertFalse($product['noCircuit']);
        $this->assertSame('40 см', $product['circuitLength']);
        $this->assertArrayNotHasKey('rightimg', $product);
        $this->assertArrayNotHasKey('circuit', $product);
        $snapshot = $product['composition'];
        $this->assertSame('silver', $snapshot['section']);
        $this->assertSame('Серебро', $snapshot['section_name']);
        $this->assertCount(2, $snapshot['items']);
        $this->assertSame($figure->id, $snapshot['items']['right_figure']['id']);
        $this->assertSame('right_figure', $snapshot['items']['right_figure']['type']);
        $this->assertSame('Деталь каталога', $snapshot['items']['right_figure']['name']);
        $this->assertSame(0, $snapshot['items']['right_figure']['price_kopecks']);
        $this->assertTrue($snapshot['items']['right_figure']['top']);
        $this->assertSame($chain->id, $snapshot['items']['chain']['id']);
        $this->assertSame(125075, $snapshot['items']['chain']['price_kopecks']);
        $this->assertArrayNotHasKey('top', $snapshot['items']['chain']);
    }

    public function test_invalid_catalog_details_are_reported_at_each_position_without_creating_an_order(): void
    {
        $foreignChain = $this->catalogItem('gold', 'chain');
        $charm = $this->catalogItem('silver', 'charm');

        $this->postJson(self::ENDPOINT, $this->payload() + [
            'composition' => ['section' => 'silver', 'chain_id' => $foreignChain->id, 'right_figure_id' => $charm->id, 'bottom_figure_id' => 999999],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'composition.chain_id', 'composition.right_figure_id', 'composition.bottom_figure_id',
        ]);

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_composition_can_omit_the_chain_and_other_optional_positions(): void
    {
        $this->postJson(self::ENDPOINT, $this->payload() + [
            'composition' => ['section' => 'silver', 'chain_id' => null],
        ])->assertCreated();

        $product = Order::query()->sole()->product_configuration;
        $this->assertSame([], $product['composition']['items']);
        $this->assertTrue($product['noCircuit']);
        $this->assertSame('40 см', $product['circuitLength']);
    }

    public function test_an_unknown_section_is_reported_at_the_section_field(): void
    {
        $this->postJson(self::ENDPOINT, $this->payload() + ['composition' => ['section' => 'unknown']])
            ->assertUnprocessable()->assertJsonValidationErrors('composition.section');

        $this->assertDatabaseCount('orders', 0);
    }

    private function catalogItem(string $section, string $type, array $attributes = []): CatalogItem
    {
        return CatalogItem::query()->create($attributes + [
            'section_id' => CatalogSection::query()->where('slug', $section)->value('id'),
            'type' => $type,
            'name' => 'Деталь каталога',
            'price_kopecks' => 0,
            'image_path' => 'constructor-catalog/image.png',
            'image_width' => 2,
            'image_height' => 3,
            'preview_path' => 'constructor-catalog/preview.png',
            'preview_width' => 1,
            'preview_height' => 1,
        ]);
    }

    private function payload(): array
    {
        $diamond = ['type' => 'diamonds', 'size' => '2.5', 'image' => '/design/app/assets/diamond.png', 'x' => 0, 'y' => 135, 'width' => 12, 'height' => 12];
        $swarovski = array_replace($diamond, ['type' => 'swarovski', 'image' => '/design/app/assets/crystal.png', 'color' => 'Голубой']);

        return [
            'name' => 'Тестовый покупатель',
            'email' => 'buyer@example.test',
            'phone' => '+79000000000',
            'promocode' => '',
            'address' => 'Тестовый адрес',
            'comment' => 'Перезвоните',
            'delivery' => 'Доставка по Москве в пределах МКАД',
            'textFont' => 'esenin_script_two',
            'text' => 'Julia ',
            'metaltype' => 'silver',
            'coating' => 'Родий',
            'sparking' => false,
            'rightimg' => 'https://namenecklace.ru/upload/figure-right.png',
            'bottomimg' => 'https://namenecklace.ru/upload/figure-bottom.png',
            'charmimg' => 'https://namenecklace.ru/upload/charm.png',
            'circuit' => 'box Italy',
            'circuitLength' => '40 см',
            'noCircuit' => false,
            'stones' => [$diamond, $swarovski, $swarovski],
            'price' => 21400,
            'attachment' => self::IMAGE,
            'payment' => 'isCash',
        ];
    }
}
