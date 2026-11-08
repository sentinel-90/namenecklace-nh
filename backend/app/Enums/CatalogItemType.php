<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CatalogItemType: string implements HasLabel
{
    case Charm = 'charm';
    case RightFigure = 'right_figure';
    case BottomFigure = 'bottom_figure';
    case Chain = 'chain';

    public function getLabel(): string
    {
        return match ($this) {
            self::Charm => 'Шарм',
            self::RightFigure => 'Фигурка справа',
            self::BottomFigure => 'Фигурка снизу',
            self::Chain => 'Цепочка',
        };
    }
}
