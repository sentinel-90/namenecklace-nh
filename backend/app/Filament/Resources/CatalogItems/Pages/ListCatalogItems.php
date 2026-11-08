<?php

namespace App\Filament\Resources\CatalogItems\Pages;

use App\Filament\Resources\CatalogItems\CatalogItemResource;
use App\Models\CatalogItem;
use App\Services\SaveCatalogItem;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCatalogItems extends ListRecords
{
    protected static string $resource = CatalogItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Добавить компонент')
                ->modalHeading('Новый компонент')
                ->using(fn (array $data, SaveCatalogItem $saveItem): CatalogItem => CatalogItemResource::saveItem($this, $saveItem, $data))
                ->createAnother(false),
        ];
    }
}
