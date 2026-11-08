<?php

namespace App\Support;

final class RubleAmount
{
    public const INPUT_PATTERN = '/\A[0-9]{1,10}(?:[.,][0-9]{1,2})?\z/';

    public static function toKopecks(string $amount): int
    {
        [$rubles, $kopecks] = array_pad(explode('.', str_replace(',', '.', $amount), 2), 2, '');

        return (int) $rubles * 100 + (int) str_pad($kopecks, 2, '0');
    }
}
