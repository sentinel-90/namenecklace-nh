<?php

namespace App\Filament\Resources\Orders;

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Order;
use App\Services\SaveOrder;
use App\Support\JsonPreview;
use App\Support\RubleAmount;
use App\Support\StorefrontImage;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View as ViewComponent;
use Filament\Schemas\Schema;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $modelLabel = 'заказ';

    protected static ?string $pluralModelLabel = 'Заказы';

    public static function form(Schema $schema): Schema
    {
        $sections = [
            'order' => Section::make('Данные заказа')
                ->schema([
                    Select::make('delivery_method')
                        ->label('Способ доставки')
                        ->options(DeliveryMethod::class)
                        ->required()
                        ->live(),
                    Select::make('payment_method')
                        ->label('Способ оплаты')
                        ->options(PaymentMethod::class)
                        ->required(),
                    TextInput::make('delivery_address')
                        ->label('Адрес доставки')
                        ->visible(fn (Get $get): bool => match ($get('delivery_method')) {
                            null, '', DeliveryMethod::Pickup, DeliveryMethod::Pickup->value => false,
                            default => true,
                        })
                        ->required()
                        ->maxLength(500)
                        ->columnSpanFull(),
                    TextInput::make('amount_kopecks')
                        ->label('Стоимость')
                        ->suffix('₽')
                        ->inputMode('decimal')
                        ->required()
                        ->regex(RubleAmount::INPUT_PATTERN)
                        ->validationMessages(['regex' => 'Введите сумму в рублях, например 1500,50.'])
                        ->formatStateUsing(fn (?int $state): ?string => $state === null
                            ? null
                            : sprintf('%d,%02d', intdiv($state, 100), $state % 100))
                        ->dehydrateStateUsing(fn (string $state): int => RubleAmount::toKopecks($state)),
                    TextInput::make('promo_code')
                        ->label('Промокод')
                        ->maxLength(255),
                    Textarea::make('comment')
                        ->label('Комментарий к заказу')
                        ->maxLength(10000)
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->columnSpanFull(),
            'product' => Section::make('Данные изделия')
                ->schema([
                    Group::make([
                        TextInput::make('text')->label('Текст')->maxLength(255),
                        TextInput::make('textFont')->label('Шрифт')->maxLength(255),
                        TextInput::make('metaltype')
                            ->label('Металл')
                            ->maxLength(255)
                            ->hidden(fn (?Order $record): bool => isset($record?->product_configuration['composition'])),
                        TextInput::make('coating')->label('Покрытия')->maxLength(255),
                        self::productImageLink('rightimg', 'Фигурка справа'),
                        self::productImageLink('bottomimg', 'Фигурка снизу'),
                        self::productImageLink('charmimg', 'Шарм'),
                        Toggle::make('noCircuit')
                            ->label('Без цепочки')
                            ->default(false)
                            ->live()
                            ->hidden(fn (?Order $record): bool => isset($record?->product_configuration['composition'])),
                        TextInput::make('circuit')
                            ->label('Цепочка')
                            ->maxLength(255)
                            ->hidden(fn (Get $get, ?Order $record): bool => isset($record?->product_configuration['composition']) || (bool) $get('noCircuit')),
                        TextInput::make('circuitLength')
                            ->label('Длина цепочки')
                            ->maxLength(64),
                        Toggle::make('sparking')->label('Сияющее покрытие')->default(false),
                        Repeater::make('stones')
                            ->label('Камни')
                            ->defaultItems(0)
                            ->addActionLabel('Добавить камень')
                            ->schema([
                                Select::make('type')
                                    ->label('Тип')
                                    ->options(['diamonds' => 'Бриллиант', 'swarovski' => 'Swarovski'])
                                    ->required()
                                    ->live(),
                                TextInput::make('size')->label('Размер')->required()->maxLength(64),
                                TextInput::make('color')
                                    ->label('Цвет')
                                    ->visible(fn (Get $get): bool => $get('type') === 'swarovski')
                                    ->required()
                                    ->maxLength(64),
                                ...array_map(
                                    fn (string $key): Hidden => Hidden::make($key)
                                        ->rules($key === 'image' ? ['nullable', 'string', 'max:2048'] : ['nullable', 'numeric'])
                                        ->saved(fn (mixed $state): bool => filled($state)),
                                    ['image', 'x', 'y', 'width', 'height'],
                                ),
                            ])
                            ->columns(3)
                            ->columnSpanFull(),
                    ])
                        ->statePath('product_configuration')
                        ->columns(2),
                    ViewComponent::make('filament.orders.composition')
                        ->key('order-composition')
                        ->visible(fn (?Order $record): bool => isset($record?->product_configuration['composition']))
                        ->viewData(fn (?Order $record): array => ['composition' => $record?->product_configuration['composition'] ?? null])
                        ->columnSpanFull(),
                    FileUpload::make('product_image_path')
                        ->label('Изображение изделия')
                        ->hintAction(
                            Action::make('preview_product_image')
                                ->label('Увеличить')
                                ->icon(Heroicon::MagnifyingGlassPlus)
                                ->visible(fn (?Order $record): bool => filled($record?->product_image_path))
                                ->modalHeading('Изображение изделия')
                                ->modalWidth(Width::SevenExtraLarge)
                                ->modalContent(function (Order $record): View {
                                    $disk = Storage::disk('local');
                                    $path = $record->product_image_path;

                                    return view('filament.orders.part-image-preview', [
                                        'imageUrl' => filled($path) && $disk->exists($path)
                                            ? $disk->temporaryUrl($path, now()->addMinutes(5))
                                            : null,
                                        'label' => 'Изображение изделия',
                                        'enlarged' => true,
                                    ]);
                                })
                                ->modalSubmitAction(false)
                                ->modalCancelActionLabel('Закрыть'),
                        )
                        ->image()
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(5120)
                        ->disk('local')
                        ->visibility('private')
                        ->preventFilePathTampering()
                        ->storeFiles(false)
                        ->validationMessages(['tampered' => 'Выберите изображение для этого заказа.'])
                        ->required(fn (Get $get, ?Order $record): bool => $record === null
                            || filled($record->product_image_path)
                            || collect($get('product_configuration'))->contains(
                                fn (mixed $value): bool => $value !== false && filled($value),
                            )),
                ])
                ->columnSpanFull(),
            'customer' => Section::make('Данные клиента')
                ->schema([
                    TextInput::make('customer_name')
                        ->label('Имя')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                    TextInput::make('customer_email')
                        ->label('Адрес электронной почты')
                        ->email()
                        ->required()
                        ->maxLength(255),
                    TextInput::make('customer_phone')
                        ->label('Телефон')
                        ->tel()
                        ->telRegex(Order::PHONE_PATTERN)
                        ->required()
                        ->maxLength(32),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ];

        return $schema->components($schema->getOperation() === 'edit'
            ? [$sections['product'], $sections['order'], $sections['customer']]
            : array_values($sections));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('Номер')->sortable(),
                TextColumn::make('customer_name')
                    ->label('Клиент')
                    ->searchable(['customer_name', 'customer_email', 'customer_phone']),
                TextColumn::make('delivery_method')->label('Доставка'),
                TextColumn::make('payment_method')->label('Оплата'),
                TextColumn::make('amount_kopecks')
                    ->label('Стоимость')
                    ->money('RUB', divideBy: 100)
                    ->placeholder('Не указана')
                    ->sortable(),
                ViewColumn::make('status')
                    ->label('Статус')
                    ->view('filament.orders.status-column')
                    ->disabledClick()
                    ->action(
                        Action::make('changeStatus')
                            ->authorize(fn (?Order $record): bool => $record !== null && static::canEdit($record))
                            ->visible(fn (?Order $record): bool => $record !== null && $record->status->nextStatuses($record->delivery_method) !== [])
                            ->successNotificationTitle('Статус заказа обновлён')
                            ->failureNotificationTitle('Этот статус сейчас недоступен для заказа.')
                            ->action(function (Order $record, array $arguments, Action $action): void {
                                $value = $arguments['status'] ?? null;
                                $status = is_string($value) ? OrderStatus::tryFrom($value) : null;

                                if ($status !== null && $record->changeStatus($status)) {
                                    $action->success();

                                    return;
                                }

                                $action->failure();
                            }),
                    ),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->label('Редактировать')
                        ->modalHeading('Редактирование заказа')
                        ->using(fn (Order $record, array $data, SaveOrder $saveOrder): Order => $saveOrder->handle($data, $record)),
                    Action::make('log')
                        ->label('Лог')
                        ->icon(Heroicon::DocumentText)
                        ->authorize(fn (?Order $record): bool => $record !== null && static::canView($record))
                        ->modalHeading(fn (Order $record): string => "Лог заказа № {$record->getKey()}")
                        ->modalContent(function (Order $record): View {
                            $json = Storage::disk('local')->get($record->logPath());

                            return view('filament.orders.log', [
                                'json' => $json === null ? null : JsonPreview::format($json),
                            ]);
                        })
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Закрыть'),
                ])
                    ->button()
                    ->label('Действие')
                    ->color('gray')
                    ->icon(Heroicon::ChevronDown)
                    ->iconPosition(IconPosition::After)
                    ->dropdownTeleport(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
        ];
    }

    private static function productImageLink(string $field, string $label): TextInput
    {
        return TextInput::make($field)
            ->label($label)
            ->hidden(fn (?Order $record): bool => isset($record?->product_configuration['composition']))
            ->maxLength(2048)
            ->live(onBlur: true)
            ->suffixAction(
                Action::make('preview_'.$field)
                    ->label('Посмотреть')
                    ->icon(Heroicon::Eye)
                    ->button()
                    ->visible(fn (TextInput $schemaComponent): bool => StorefrontImage::isUrl($schemaComponent->getState()))
                    ->modalHeading($label)
                    ->modalContent(function (TextInput $schemaComponent) use ($label): View {
                        $url = $schemaComponent->getState();

                        return view('filament.orders.part-image-preview', [
                            'imageUrl' => StorefrontImage::isUrl($url) ? $url : null,
                            'label' => $label,
                        ]);
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Закрыть'),
            );
    }
}
