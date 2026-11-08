<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum OrderStatus: string implements HasLabel
{
    case Created = 'new';
    case Confirmed = 'confirmed';
    case InProduction = 'in_production';
    case Ready = 'ready';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Issued = 'issued';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Created => 'Новый',
            self::Confirmed => 'Подтверждён',
            self::InProduction => 'В производстве',
            self::Ready => 'Готов',
            self::Shipped => 'Отправлен',
            self::Delivered => 'Доставлен',
            self::Issued => 'Выдан',
            self::Cancelled => 'Отменён',
        };
    }

    /** @return list<self> */
    public function nextStatuses(DeliveryMethod $deliveryMethod): array
    {
        return match ($this) {
            self::Created => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::InProduction, self::Cancelled],
            self::InProduction => [self::Ready, self::Cancelled],
            self::Ready => [$deliveryMethod === DeliveryMethod::Pickup ? self::Issued : self::Shipped, self::Cancelled],
            self::Shipped => [self::Delivered, self::Cancelled],
            self::Delivered, self::Issued, self::Cancelled => [],
        };
    }
}
