<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrderRequest;
use App\Services\SaveOrder;
use App\Support\RubleAmount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class StoreOrderController
{
    public function __invoke(StoreOrderRequest $request, SaveOrder $saveOrder): JsonResponse
    {
        $data = $request->validated();
        $image = base64_decode($data['attachment'], strict: true);
        $imageInfo = $image !== false && strlen($image) <= StoreOrderRequest::MAX_IMAGE_BYTES
            ? @getimagesizefromstring($image)
            : false;
        $mime = $imageInfo['mime'] ?? null;
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => null,
        };

        if ($extension === null) {
            throw ValidationException::withMessages([
                'attachment' => 'Передайте изображение JPEG, PNG или WebP размером до 5 МБ в чистом Base64.',
            ]);
        }

        $file = tmpfile();
        if ($file === false) {
            throw new RuntimeException('Не удалось подготовить изображение заказа.');
        }

        try {
            if (fwrite($file, $image) !== strlen($image)) {
                throw new RuntimeException('Не удалось записать изображение заказа.');
            }

            $product = Arr::only($data, [
                'text', 'textFont', 'metaltype', 'coating', 'rightimg', 'bottomimg',
                'charmimg', 'circuit', 'circuitLength',
            ]);
            $product['sparking'] = (bool) ($data['sparking'] ?? false);
            $product['noCircuit'] = (bool) ($data['noCircuit'] ?? false);
            $product['stones'] = $data['stones'] ?? [];

            $order = $saveOrder->handle([
                'customer_name' => $data['name'],
                'customer_email' => $data['email'],
                'customer_phone' => $data['phone'],
                'promo_code' => $data['promocode'] ?? null,
                'comment' => $data['comment'] ?? null,
                'delivery_method' => StoreOrderRequest::deliveryMethods()[$data['delivery']],
                'delivery_address' => $data['address'] ?? null,
                'payment_method' => StoreOrderRequest::PAYMENT_METHODS[$data['payment']],
                'amount_kopecks' => RubleAmount::toKopecks((string) $data['price']),
                'product_configuration' => $product,
                'product_image_path' => new UploadedFile(
                    stream_get_meta_data($file)['uri'],
                    "product.{$extension}",
                    $mime,
                    UPLOAD_ERR_OK,
                    test: true,
                ),
            ], composition: $data['composition'] ?? null)->refresh();
        } finally {
            fclose($file);
        }

        return response()->json(['id' => $order->getKey(), 'status' => $order->status->value], 201);
    }
}
