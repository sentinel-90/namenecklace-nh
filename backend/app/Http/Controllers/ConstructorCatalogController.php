<?php

namespace App\Http\Controllers;

use App\Enums\CatalogItemType;
use App\Http\Resources\CatalogItemResource;
use App\Models\CatalogSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ConstructorCatalogController
{
    public function __invoke(Request $request): JsonResponse
    {
        $filters = Validator::make($request->query(), [
            'type' => ['sometimes', 'required', Rule::enum(CatalogItemType::class)],
        ])->validate();
        $type = $filters['type'] ?? null;

        $sections = CatalogSection::query()
            ->with(['items' => fn ($query) => $query
                ->when($type !== null, fn ($query) => $query->where('type', $type))
                ->orderBy('id')])
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (CatalogSection $section): array => [
                $section->slug => CatalogItemResource::collection($section->items),
            ]);

        return response()->json((object) $sections->all());
    }
}
