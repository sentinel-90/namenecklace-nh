<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DeliveryMethod: string implements HasLabel
{
    case Pickup = 'pickup';
    case MoscowWithinRingRoad = 'moscow_within_mkad';
    case SaintPetersburg = 'saint_petersburg';
    case Russia = 'russia';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pickup => 'Самовывоз',
            self::MoscowWithinRingRoad => 'Доставка по Москве в пределах МКАД',
            self::SaintPetersburg => 'Доставка в Санкт-Петербург',
            self::Russia => 'Доставка по России',
        };
    }
}
