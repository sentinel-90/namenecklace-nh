<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use Carbon\CarbonInterface;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

class OrderStats extends StatsOverviewWidget
{
    protected static ?bool $isLazyLoaded = false;

    protected ?string $pollingInterval = null;

    protected ?string $description = 'По московскому времени';

    protected function getStats(): array
    {
        $now = now('Europe/Moscow')->toImmutable();
        $periodStarts = [
            'today' => $now->startOfDay()->utc(),
            'week' => $now->startOfWeek(CarbonInterface::MONDAY)->utc(),
            'month' => $now->startOfMonth()->utc(),
        ];

        $query = Order::query()->toBase()
            ->where('created_at', '>=', min($periodStarts))
            ->where('created_at', '<=', $now->utc());

        foreach ($periodStarts as $period => $start) {
            $query->selectRaw(
                "COUNT(CASE WHEN created_at >= ? THEN 1 END) AS {$period}_count, "
                ."COALESCE(SUM(CASE WHEN created_at >= ? THEN amount_kopecks END), 0) AS {$period}_amount",
                [$start, $start],
            );
        }

        $totals = $query->first();

        return [
            $this->makeStat('За сегодня', (int) $totals->today_count, (int) $totals->today_amount),
            $this->makeStat('За эту неделю', (int) $totals->week_count, (int) $totals->week_amount),
            $this->makeStat('За этот месяц', (int) $totals->month_count, (int) $totals->month_amount),
        ];
    }

    private function makeStat(string $label, int $count, int $amountKopecks): Stat
    {
        return Stat::make($label, Number::currency($amountKopecks / 100, 'RUB', locale: 'ru'))
            ->description(trans_choice('orders.count', $count, locale: 'ru'));
    }
}
