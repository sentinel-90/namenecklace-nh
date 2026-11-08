<?php

namespace App\Services;

use App\Enums\CatalogItemType;
use App\Models\Order;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class SaveOrder
{
    public function __construct(private SnapshotOrderComposition $snapshotComposition) {}

    public function handle(array $data, ?Order $order = null, ?array $composition = null): Order
    {
        $upload = $data['product_image_path'] ?? null;
        $newPath = $upload instanceof UploadedFile ? $upload->store('order-images', 'local') : null;

        if ($newPath === false) {
            throw new RuntimeException('Не удалось сохранить изображение изделия.');
        }

        try {
            [$savedOrder, $previousPath] = DB::transaction(function () use ($data, $order, $newPath, $composition): array {
                $record = $order === null ? new Order : Order::query()->lockForUpdate()->findOrFail($order->getKey());
                $previousPath = $record->product_image_path;
                $data['product_image_path'] = $newPath ?? $previousPath;

                if ($composition !== null || array_key_exists('product_configuration', $data)) {
                    $snapshot = $composition === null
                        ? ($record->product_configuration['composition'] ?? null)
                        : $this->snapshotComposition->capture($composition);

                    if ($snapshot !== null) {
                        $data['product_configuration']['composition'] = $snapshot;
                        $data['product_configuration']['metaltype'] = $snapshot['section'];
                        $data['product_configuration']['noCircuit'] = ! isset($snapshot['items'][CatalogItemType::Chain->value]);
                    }
                }

                if ($data['product_image_path'] === null) {
                    $data['product_configuration'] = null;
                } elseif ($data['product_configuration']['noCircuit'] ?? false) {
                    $data['product_configuration']['circuit'] = null;
                }

                $record->fill($data)->saveOrFail();

                return [$record, $previousPath];
            });
        } catch (Throwable $exception) {
            if ($newPath !== null) {
                Storage::disk('local')->delete($newPath);
            }

            throw $exception;
        }

        if ($newPath !== null && $previousPath !== null) {
            DB::afterCommit(fn () => Storage::disk('local')->delete($previousPath));
        }

        return $savedOrder;
    }
}
