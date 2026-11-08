<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StoreOrderLogController
{
    private const MAX_BYTES = 8 * 1024 * 1024;

    public function __invoke(Request $request, Order $order): Response
    {
        abort_unless($request->isJson(), 415, 'Отправьте лог в формате JSON.');

        $body = $request->getContent();
        abort_if(strlen($body) > self::MAX_BYTES, 413, 'Размер лога не должен превышать 8 МБ.');
        abort_unless(json_validate($body), 400, 'Тело запроса содержит некорректный JSON.');

        $escapes = <<<'REGEX'
        ~\\(?:u[dD][89aAbB][0-9a-fA-F]{2}\\u[dD][c-fC-F][0-9a-fA-F]{2}|u[0-9a-fA-F]{4}|.)~
        REGEX;
        $body = preg_replace_callback($escapes, static function (array $matches): string {
            $escape = $matches[0];

            if (! str_starts_with($escape, '\u')) {
                return $escape;
            }

            // Encode the decoded string again to keep quotes, backslashes and control characters escaped.
            $value = json_decode('"'.$escape.'"', flags: JSON_THROW_ON_ERROR);

            return substr(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 1, -1);
        }, $body);
        abort_if($body === null, 500, 'Не удалось обработать Unicode в логе заказа.');

        $disk = Storage::disk('local');
        $path = $order->logPath();
        $temporaryPath = 'order-logs/'.Str::uuid().'.tmp';

        try {
            abort_unless(
                $disk->put($temporaryPath, $body, 'private') && $disk->move($temporaryPath, $path),
                500,
                'Не удалось сохранить лог заказа.',
            );
        } finally {
            $disk->delete($temporaryPath);
        }

        return response()->noContent();
    }
}
