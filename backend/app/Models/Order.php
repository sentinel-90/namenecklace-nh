<?php

namespace App\Models;

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'delivery_method',
    'delivery_address',
    'payment_method',
    'amount_kopecks',
    'comment',
    'promo_code',
    'customer_name',
    'customer_email',
    'customer_phone',
    'product_configuration',
    'product_image_path',
])]
class Order extends Model
{
    public const PHONE_PATTERN = '/\A\+?(?:[() .-]*[0-9]){7,15}\z/';

    public function logPath(): string
    {
        return "order-logs/{$this->getKey()}.json";
    }

    public function changeStatus(OrderStatus $status): bool
    {
        return DB::transaction(function () use ($status): bool {
            $order = self::query()->lockForUpdate()->findOrFail($this->getKey());

            if (! in_array($status, $order->status->nextStatuses($order->delivery_method), true)) {
                return false;
            }

            $order->status = $status;

            return $order->saveOrFail();
        });
    }

    protected static function booted(): void
    {
        static::saving(function (Order $order): void {
            if ($order->delivery_method === DeliveryMethod::Pickup) {
                $order->delivery_address = null;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'delivery_method' => DeliveryMethod::class,
            'payment_method' => PaymentMethod::class,
            'amount_kopecks' => 'integer',
            'product_configuration' => 'array',
            'status' => OrderStatus::class,
        ];
    }
}
