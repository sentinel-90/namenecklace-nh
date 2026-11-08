<?php

namespace App\Services;

use App\Enums\CatalogItemType;
use App\Models\CatalogSection;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class SnapshotOrderComposition
{
    public function capture(array $selection): array
    {
        $section = CatalogSection::query()->where('slug', $selection['section'])->first();

        if ($section?->slug !== $selection['section']) {
            throw ValidationException::withMessages(['composition.section' => 'Выберите существующий раздел каталога.']);
        }

        $ids = array_filter(Arr::except($selection, ['section']), fn (?int $id): bool => $id !== null);
        $items = $section->items()->whereKey(array_values($ids))->get()->keyBy('id');
        $snapshot = [];
        $errors = [];

        foreach (CatalogItemType::cases() as $type) {
            $field = $type->value.'_id';
            $id = $ids[$field] ?? null;

            if ($id === null) {
                continue;
            }

            $item = $items->get($id);

            if ($item?->type !== $type) {
                $errors['composition.'.$field] = 'Выбранная деталь недоступна для этой позиции и раздела.';

                continue;
            }

            $snapshot[$type->value] = [
                'id' => $item->id,
                'type' => $type->value,
                'name' => $item->name,
                'price_kopecks' => $item->price_kopecks,
            ];

            if ($type === CatalogItemType::RightFigure) {
                $snapshot[$type->value]['top'] = $item->top;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['section' => $section->slug, 'section_name' => $section->name, 'items' => $snapshot];
    }
}
