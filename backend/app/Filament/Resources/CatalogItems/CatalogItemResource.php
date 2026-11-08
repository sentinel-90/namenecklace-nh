<?php

namespace App\Filament\Resources\CatalogItems;

use App\Enums\CatalogItemType;
use App\Filament\Resources\CatalogItems\Pages\ListCatalogItems;
use App\Models\CatalogItem;
use App\Services\SaveCatalogItem;
use App\Support\RubleAmount;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class CatalogItemResource extends Resource
{
    protected static ?string $model = CatalogItem::class;

    protected static ?string $modelLabel = 'компонент';

    protected static ?string $pluralModelLabel = 'Компоненты конструктора';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('section_id')->label('Раздел')->relationship('section', 'name')->required(),
            Select::make('type')->label('Тип')->options(CatalogItemType::class)->required()->live(),
            TextInput::make('name')->label('Название')->required()->maxLength(255),
            TextInput::make('price_kopecks')
                ->label('Цена')
                ->suffix('₽')
                ->inputMode('decimal')
                ->required()
                ->regex(RubleAmount::INPUT_PATTERN)
                ->validationMessages(['regex' => 'Введите сумму в рублях, например 0 или 1500,50.'])
                ->formatStateUsing(fn (?int $state): ?string => $state === null
                    ? null
                    : sprintf('%d,%02d', intdiv($state, 100), $state % 100))
                ->dehydrateStateUsing(fn (string $state): int => RubleAmount::toKopecks($state)),
            Toggle::make('top')
                ->label('Расположить сверху (top)')
                ->default(false)
                ->visible(fn (Get $get): bool => in_array($get('type'), [CatalogItemType::RightFigure, CatalogItemType::RightFigure->value], true))
                ->columnSpanFull(),
            self::imageFields('image', 'Изображение'),
            self::imageFields('preview', 'Превью'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Название')->searchable()->sortable(),
                TextColumn::make('section.name')->label('Раздел'),
                TextColumn::make('type')->label('Тип')->badge(),
                TextColumn::make('price_kopecks')->label('Цена')->money('RUB', divideBy: 100)->sortable(),
            ])
            ->filters([
                SelectFilter::make('section')->label('Раздел')->relationship('section', 'name'),
                SelectFilter::make('type')->label('Тип')->options(CatalogItemType::class),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(fn (ListCatalogItems $livewire, CatalogItem $record, array $data, SaveCatalogItem $saveItem): CatalogItem => self::saveItem($livewire, $saveItem, $data, $record)),
                DeleteAction::make()
                    ->using(fn (CatalogItem $record, SaveCatalogItem $saveItem): bool => $saveItem->delete($record)),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListCatalogItems::route('/')];
    }

    public static function saveItem(ListCatalogItems $livewire, SaveCatalogItem $saveItem, array $data, ?CatalogItem $record = null): CatalogItem
    {
        try {
            return $saveItem->handle($data, $record);
        } catch (ValidationException $exception) {
            $schema = $livewire->getSchema($livewire->getMountedActionSchemaName());

            throw ValidationException::withMessages(Arr::prependKeysWith($exception->errors(), $schema->getStatePath().'.'));
        }
    }

    private static function imageFields(string $image, string $label): Group
    {
        return Group::make([
            self::imageUpload($image, $label),
            TextInput::make($image.'_url')
                ->label($image === 'image' ? 'Ссылка на изображение' : 'Ссылка на превью')
                ->url()
                ->maxLength(2048)
                ->placeholder('https://example.com/image.png')
                ->helperText('Укажите прямую ссылку вместо загрузки файла.'),
        ]);
    }

    private static function imageUpload(string $image, string $label): FileUpload
    {
        return FileUpload::make($image.'_path')
            ->label($label)
            ->hint(fn (?CatalogItem $record): ?string => $record === null ? null : sprintf(
                '%d × %d px',
                $record->getAttribute($image.'_width'),
                $record->getAttribute($image.'_height'),
            ))
            ->disk('public')
            ->visibility('public')
            ->image()
            ->acceptedFileTypes(array_keys(SaveCatalogItem::IMAGE_EXTENSIONS))
            ->rule('dimensions:min_width=1,min_height=1')
            ->maxSize(5120)
            ->required(fn (Get $get): bool => blank($get($image.'_url')))
            ->openable()
            ->preventFilePathTampering()
            ->storeFiles(false);
    }
}
