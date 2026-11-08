<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Services\SaveOrder;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Создать заказ')
                ->modalHeading('Новый заказ')
                ->using(fn (array $data, SaveOrder $saveOrder): Order => $saveOrder->handle($data))
                ->createAnother(false),
        ];
    }
}
