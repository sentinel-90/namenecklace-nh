<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PaymentMethod: string implements HasLabel
{
    case Dolyame = 'dolyame';
    case Card = 'card';

    public function getLabel(): string
    {
        return match ($this) {
            self::Dolyame => 'Долями',
            self::Card => 'Картой',
        };
    }
}
