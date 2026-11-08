<?php

namespace App\Http\Controllers;

use App\Enums\CatalogItemType;
use App\Http\Resources\CatalogItemResource;
use App\Models\CatalogSection;
use App\Services\SaveCatalogItem;
use App\Support\RubleAmount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StoreCatalogItemController
{
    public function __invoke(Request $request, SaveCatalogItem $saveItem): JsonResponse
    {
        $token = config('services.catalog.write_token');
        $provided = $request->bearerToken();

        abort_unless(is_string($token) && $token !== '' && is_string($provided) && hash_equals($token, $provided),
            401, 'Требуется токен записи каталога.', ['WWW-Authenticate' => 'Bearer']);
        abort_unless($request->isJson(), 415, 'Отправьте компонент в формате JSON.');

        $data = $request->validate([
            'section' => ['required', 'string', 'max:32'],
            'type' => ['required', Rule::enum(CatalogItemType::class)],
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'regex:'.RubleAmount::INPUT_PATTERN],
            'image_url' => ['required', 'url:http,https', 'max:2048'],
            'preview_url' => ['required', 'url:http,https', 'max:2048'],
            'top' => ['exclude_unless:type,right_figure', 'sometimes', 'boolean'],
        ]);

        $section = CatalogSection::query()->where('slug', $data['section'])->first();

        if ($section?->slug !== $data['section']) {
            throw ValidationException::withMessages(['section' => 'Выберите существующий раздел каталога.']);
        }

        $data['section_id'] = $section->id;
        $data['price_kopecks'] = RubleAmount::toKopecks((string) $data['price']);
        $item = $saveItem->handle($data);

        return (new CatalogItemResource($item))->response()->setStatusCode(201);
    }
}
