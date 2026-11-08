<?php

namespace Tests\Feature\Filament;

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Order;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ListOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Storage::fake('local');
    }

    public function test_orders_are_visible_only_after_signing_in(): void
    {
        $this->get('/admin/orders')->assertRedirect('/admin/login');
    }

    public function test_an_employee_can_create_an_order_with_delivery(): void
    {
        $this->actingAs(User::factory()->create());
        $data = array_replace($this->orderData(), [
            'delivery_method' => DeliveryMethod::MoscowWithinRingRoad->value,
            'delivery_address' => 'Москва, ул. Примерная, д. 10',
            'payment_method' => PaymentMethod::Dolyame->value,
            'comment' => 'Позвонить перед доставкой',
            'promo_code' => 'WELCOME',
            'product_configuration' => [
                'text' => 'Анна',
                'textFont' => 'script-1',
                'metaltype' => 'silver',
                'coating' => 'gold',
                'sparking' => true,
                'rightimg' => 'heart',
                'bottomimg' => 'star',
                'charmimg' => 'moon',
                'circuit' => 'anchor',
                'circuitLength' => '45 см',
                'stones' => [
                    ['type' => 'diamonds', 'size' => '2 мм', 'color' => 'Лишний цвет'],
                    ['type' => 'swarovski', 'size' => '3 мм', 'color' => 'Синий'],
                ],
            ],
        ]);

        $form = Livewire::test(ListOrders::class)->mountAction('create');
        $this->assertSame(
            ['Данные заказа', 'Данные изделия', 'Данные клиента'],
            $this->sectionHeadings($form->instance()),
        );

        $form->fillForm($data)
            ->set('mountedActions.0.data.product_image_path', [$this->productImage()])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('orders', array_replace(Arr::except($data, 'product_configuration'), [
            'amount_kopecks' => 149050,
            'status' => OrderStatus::Created->value,
        ]));
        $order = Order::query()->sole();
        $product = $data['product_configuration'];
        unset($product['stones'][0]['color']);
        foreach ($product as $key => $value) {
            $this->assertSame($value, $order->product_configuration[$key], $key);
        }
        Storage::disk('local')->assertExists($order->product_image_path);
        $this->assertStringStartsWith('order-images/', $order->product_image_path);
        $this->get('/admin/orders')->assertOk()->assertSeeText([
            'Заказы',
            'Анна',
            'Доставка по Москве в пределах МКАД',
            'Долями',
            'Стоимость',
            '490,50',
            '₽',
            'Новый',
        ]);
    }

    public function test_pickup_hides_the_address_and_saves_without_it(): void
    {
        $this->actingAs(User::factory()->create());
        $data = $this->orderData();

        Livewire::test(ListOrders::class)
            ->mountAction('create')
            ->fillForm($data)
            ->assertFormFieldIsHidden('delivery_address')
            ->set('mountedActions.0.data.product_image_path', [$this->productImage()])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('orders', array_replace($data, [
            'amount_kopecks' => 149050,
            'delivery_address' => null,
            'comment' => null,
            'promo_code' => null,
        ]));
    }

    public function test_delivery_requires_an_address(): void
    {
        $this->actingAs(User::factory()->create());
        $data = array_replace($this->orderData(), ['delivery_method' => DeliveryMethod::Russia->value]);

        Livewire::test(ListOrders::class)
            ->mountAction('create')
            ->fillForm($data)
            ->assertFormFieldIsVisible('delivery_address')
            ->callMountedAction()
            ->assertHasFormErrors(['delivery_address' => 'required']);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_unlisted_delivery_and_payment_methods_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        $data = array_replace($this->orderData(), [
            'delivery_method' => 'other_delivery',
            'delivery_address' => 'Тестовый адрес',
            'payment_method' => 'other_payment',
        ]);

        Livewire::test(ListOrders::class)->callAction('create', data: $data)
            ->assertHasFormErrors(['delivery_method', 'payment_method']);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_changing_delivery_to_pickup_clears_the_previous_address(): void
    {
        $this->actingAs(User::factory()->create());
        $order = Order::create(array_replace($this->orderData(), [
            'delivery_method' => DeliveryMethod::SaintPetersburg->value,
            'delivery_address' => 'Санкт-Петербург, ул. Примерная, д. 5',
            'amount_kopecks' => 149050,
        ]));

        Livewire::test(ListOrders::class)
            ->callAction(TestAction::make('edit')->table($order), data: [
                'delivery_method' => DeliveryMethod::Pickup->value,
            ])
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'delivery_method' => DeliveryMethod::Pickup->value,
            'delivery_address' => null,
            'amount_kopecks' => 149050,
        ]);
    }

    #[TestWith([DeliveryMethod::Pickup, [OrderStatus::Issued]])]
    #[TestWith([DeliveryMethod::Russia, [OrderStatus::Shipped, OrderStatus::Delivered]])]
    public function test_an_employee_can_advance_an_order_to_completion(DeliveryMethod $deliveryMethod, array $finalSteps): void
    {
        $this->actingAs(User::factory()->create());
        $order = Order::create(array_replace($this->orderData(), [
            'delivery_method' => $deliveryMethod->value,
            'delivery_address' => 'Тестовый адрес',
            'amount_kopecks' => 149050,
        ]));
        $context = ['table' => true, 'recordKey' => (string) $order->id];
        $table = Livewire::test(ListOrders::class)
            ->assertSeeHtml("mountAction('changeStatus', JSON.parse(");

        foreach ([OrderStatus::Confirmed, OrderStatus::InProduction, OrderStatus::Ready, ...$finalSteps] as $status) {
            $table->call('mountAction', 'changeStatus', ['status' => $status->value], $context)
                ->assertSet('mountedActions', [])
                ->assertSee($status->getLabel());

            $this->assertSame($status, $order->refresh()->status);
        }

        $table->assertDontSeeHtml("mountAction('changeStatus',");
    }

    public function test_an_employee_can_cancel_an_order(): void
    {
        $this->actingAs(User::factory()->create());
        $order = Order::create(array_replace($this->orderData(), ['amount_kopecks' => 149050]));
        Livewire::test(ListOrders::class)
            ->call('mountAction', 'changeStatus', ['status' => OrderStatus::Cancelled->value], [
                'table' => true,
                'recordKey' => (string) $order->id,
            ])
            ->assertDontSeeHtml("mountAction('changeStatus',");

        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
    }

    #[TestWith([OrderStatus::Created, OrderStatus::Ready])]
    #[TestWith([OrderStatus::Ready, OrderStatus::Shipped])]
    #[TestWith([OrderStatus::Issued, OrderStatus::Cancelled])]
    public function test_an_employee_cannot_submit_an_unavailable_status_for_pickup(OrderStatus $current, OrderStatus $requested): void
    {
        $this->actingAs(User::factory()->create());
        $order = Order::create(array_replace($this->orderData(), ['amount_kopecks' => 149050]));
        $order->status = $current;
        $order->save();

        Livewire::test(ListOrders::class)
            ->call('mountAction', 'changeStatus', ['status' => $requested->value], [
                'table' => true,
                'recordKey' => (string) $order->id,
            ]);

        $this->assertSame($current, $order->refresh()->status);
    }

    public function test_a_price_with_more_than_two_fraction_digits_is_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        $data = array_replace($this->orderData(), ['amount_kopecks' => '1490,501']);

        Livewire::test(ListOrders::class)->callAction('create', data: $data)
            ->assertHasFormErrors(['amount_kopecks' => 'regex']);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_an_employee_can_add_a_price_to_an_existing_order(): void
    {
        $this->actingAs(User::factory()->create());
        $order = Order::create(array_replace($this->orderData(), ['amount_kopecks' => null]));

        $this->get('/admin/orders')->assertOk()->assertSeeText('Не указана');

        Livewire::test(ListOrders::class)
            ->callAction(TestAction::make('edit')->table($order), data: ['amount_kopecks' => '980.5'])
            ->assertHasNoFormErrors();

        $this->assertSame(98050, $order->refresh()->amount_kopecks);
        $this->assertNull($order->product_configuration);
    }

    public function test_a_new_order_requires_a_product_image(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ListOrders::class)->callAction('create', data: $this->orderData())
            ->assertHasFormErrors(['product_image_path' => 'required']);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_swarovski_stone_requires_a_color(): void
    {
        $this->actingAs(User::factory()->create());
        $data = $this->orderData() + [
            'product_configuration' => ['stones' => [['type' => 'swarovski', 'size' => '3 мм']]],
        ];

        Livewire::test(ListOrders::class)
            ->mountAction('create')
            ->fillForm($data)
            ->set('mountedActions.0.data.product_image_path', [$this->productImage()])
            ->callMountedAction()
            ->assertHasFormErrors(['product_configuration.stones.*.color']);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_an_image_is_required_when_adding_product_details_to_an_existing_order(): void
    {
        $this->actingAs(User::factory()->create());
        $order = Order::create(array_replace($this->orderData(), ['amount_kopecks' => 149050]));

        Livewire::test(ListOrders::class)
            ->callAction(TestAction::make('edit')->table($order), data: [
                'product_configuration' => ['text' => 'Анна'],
            ])
            ->assertHasFormErrors(['product_image_path' => 'required']);

        $this->assertNull($order->refresh()->product_configuration);
    }

    public function test_replacing_an_image_updates_the_order_and_removes_the_previous_file(): void
    {
        $this->actingAs(User::factory()->create());
        $previousPath = $this->productImage()->store('order-images', 'local');
        $order = Order::create(array_replace($this->orderData(), [
            'amount_kopecks' => 149050,
            'product_configuration' => ['text' => 'Анна'],
            'product_image_path' => $previousPath,
        ]));

        $form = Livewire::test(ListOrders::class)->mountAction(TestAction::make('edit')->table($order));
        $this->assertSame(
            ['Данные изделия', 'Данные заказа', 'Данные клиента'],
            $this->sectionHeadings($form->instance()),
        );

        $form->set('mountedActions.0.data.product_image_path', [])
            ->set('mountedActions.0.data.product_image_path', [$this->productImage()])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $this->assertNotSame($previousPath, $order->refresh()->product_image_path);
        Storage::disk('local')->assertExists($order->product_image_path);
        Storage::disk('local')->assertMissing($previousPath);
    }

    public function test_an_employee_can_read_the_selected_orders_log_as_formatted_text(): void
    {
        $this->actingAs(User::factory()->create());
        $order = Order::create(array_replace($this->orderData(), ['amount_kopecks' => 149050]));
        Storage::disk('local')->put($order->logPath(), '{"id":123456789012345678901,"value":"</textarea><script>alert(1)</script>","items":[]}');

        $action = Livewire::test(ListOrders::class)
            ->mountAction(TestAction::make('log')->table($order))
            ->instance()->getMountedAction();

        $this->assertSame("Лог заказа № {$order->id}", $action?->getModalHeading());
        $html = $action->getModalContent()->render();
        $this->assertStringContainsString('<textarea readonly', $html);
        $this->assertStringContainsString("{\n    &quot;id&quot;: 123456789012345678901,", $html);
        $this->assertStringContainsString('&lt;/textarea&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_an_employee_sees_when_the_selected_order_has_no_log(): void
    {
        $this->actingAs(User::factory()->create());
        $order = Order::create(array_replace($this->orderData(), ['amount_kopecks' => 149050]));

        $action = Livewire::test(ListOrders::class)
            ->mountAction(TestAction::make('log')->table($order))
            ->instance()->getMountedAction();

        $this->assertStringContainsString('Лог для этого заказа ещё не сохранён.', $action->getModalContent()->render());
    }

    public function test_an_employee_can_preview_each_storefront_figure_in_a_modal(): void
    {
        $this->actingAs(User::factory()->create());
        $links = [
            'rightimg' => 'https://namenecklace.ru/upload/right.png',
            'bottomimg' => 'https://namenecklace.ru/upload/bottom.png',
            'charmimg' => 'https://namenecklace.ru/upload/charm.png',
        ];
        $order = Order::create(array_replace($this->orderData(), [
            'amount_kopecks' => 149050,
            'product_configuration' => $links,
            'product_image_path' => $this->productImage()->store('order-images', 'local'),
        ]));

        foreach ($links as $field => $url) {
            $form = Livewire::test(ListOrders::class)->mountAction([
                TestAction::make('edit')->table($order),
                TestAction::make('preview_'.$field)->schemaComponent('product_configuration.'.$field, schema: 'mountedActionSchema0'),
            ]);
            $preview = $form->instance()->getMountedAction();

            $this->assertSame('preview_'.$field, $preview?->getName());
            $this->assertStringContainsString('src="'.$url.'"', $preview->getModalContent()->render());
        }
    }

    public function test_editing_an_order_preserves_constructor_stone_positions(): void
    {
        $this->actingAs(User::factory()->create());
        $stone = [
            'type' => 'swarovski',
            'size' => '2.5',
            'color' => 'Голубой',
            'image' => '/design/app/assets/crystal.png',
            'x' => 0,
            'y' => 135,
            'width' => 12,
            'height' => 12,
        ];
        $order = Order::create(array_replace($this->orderData(), [
            'amount_kopecks' => 149050,
            'product_configuration' => ['noCircuit' => true, 'text' => 'Julia ', 'stones' => [$stone, $stone]],
            'product_image_path' => $this->productImage()->store('order-images', 'local'),
        ]));

        Livewire::test(ListOrders::class)
            ->callAction(TestAction::make('edit')->table($order), data: ['comment' => 'Обновлённый комментарий'])
            ->assertHasNoFormErrors();

        $product = $order->refresh()->product_configuration;
        $this->assertCount(2, $product['stones']);
        foreach ($product['stones'] as $savedStone) {
            foreach ($stone as $key => $value) {
                $this->assertSame($value, $savedStone[$key]);
            }
        }
        $this->assertTrue($product['noCircuit']);
        $this->assertSame('Julia ', $product['text']);
    }

    public function test_the_order_editor_displays_and_preserves_the_saved_composition(): void
    {
        $this->actingAs(User::factory()->create());
        $snapshot = [
            'section' => 'silver',
            'section_name' => 'Серебро',
            'items' => [
                'right_figure' => ['id' => 1, 'type' => 'right_figure', 'name' => 'Звезда', 'price_kopecks' => 0, 'top' => true],
                'bottom_figure' => ['id' => 2, 'type' => 'bottom_figure', 'name' => 'Луна', 'price_kopecks' => 0],
                'charm' => ['id' => 3, 'type' => 'charm', 'name' => 'Сердце', 'price_kopecks' => 0],
                'chain' => ['id' => 8, 'type' => 'chain', 'name' => 'Архивная цепочка', 'price_kopecks' => 125075],
            ],
        ];
        $order = Order::create(array_replace($this->orderData(), [
            'amount_kopecks' => 149050,
            'product_configuration' => ['composition' => $snapshot, 'metaltype' => 'gold', 'noCircuit' => false, 'circuitLength' => '40 см'],
            'product_image_path' => $this->productImage()->store('order-images', 'local'),
        ]));

        $form = Livewire::test(ListOrders::class)
            ->mountAction(TestAction::make('edit')->table($order))
            ->assertFormFieldIsHidden('product_configuration.metaltype');
        $page = $form->instance();
        $component = $page->getSchema($page->getMountedActionSchemaName())->getComponent('order-composition');
        $this->assertNotNull($component);
        $html = $component->toHtml();
        $this->assertStringContainsString('Состав изделия на момент оформления', $html);
        $this->assertStringContainsString('Металл', $html);
        $this->assertStringContainsString('Серебро', $html);
        $this->assertStringContainsString('Фигурка справа', $html);
        $this->assertStringContainsString('Звезда', $html);
        $this->assertStringContainsString('Фигурка снизу', $html);
        $this->assertStringContainsString('Луна', $html);
        $this->assertStringContainsString('Шарм', $html);
        $this->assertStringContainsString('Сердце', $html);
        $this->assertStringContainsString('Архивная цепочка', $html);

        $form->fillForm(['comment' => 'Обновлённый комментарий'])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $order->refresh();
        $this->assertSame($snapshot, $order->product_configuration['composition']);
        $this->assertSame('silver', $order->product_configuration['metaltype']);
        $this->assertSame('40 см', $order->product_configuration['circuitLength']);
        $this->assertSame('Обновлённый комментарий', $order->comment);
    }

    public function test_the_order_editor_can_change_chain_length_without_a_selected_chain(): void
    {
        $this->actingAs(User::factory()->create());
        $order = Order::create(array_replace($this->orderData(), [
            'amount_kopecks' => 149050,
            'product_configuration' => [
                'composition' => ['section' => 'silver', 'section_name' => 'Серебро', 'items' => []],
                'noCircuit' => true,
            ],
            'product_image_path' => $this->productImage()->store('order-images', 'local'),
        ]));

        Livewire::test(ListOrders::class)
            ->mountAction(TestAction::make('edit')->table($order))
            ->assertFormFieldIsVisible('product_configuration.circuitLength')
            ->fillForm(['product_configuration' => ['circuitLength' => '45 см']])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $product = $order->refresh()->product_configuration;
        $this->assertTrue($product['noCircuit']);
        $this->assertSame('45 см', $product['circuitLength']);
    }

    private function sectionHeadings(ListOrders $page): array
    {
        $schema = $page->getSchema($page->getMountedActionSchemaName());

        return array_map(fn (Section $section): string => $section->getHeading(), $schema->getComponents());
    }

    private function productImage(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'product.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/a0kAAAAASUVORK5CYII=', true),
        );
    }

    private function orderData(): array
    {
        return [
            'delivery_method' => DeliveryMethod::Pickup->value,
            'payment_method' => PaymentMethod::Card->value,
            'amount_kopecks' => '1490,50',
            'customer_name' => 'Анна',
            'customer_email' => 'anna@example.test',
            'customer_phone' => '+7 (900) 123-45-67',
        ];
    }
}
