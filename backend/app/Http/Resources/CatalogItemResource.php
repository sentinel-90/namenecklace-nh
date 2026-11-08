<?php

namespace App\Http\Resources;

use App\Enums\CatalogItemType;
use App\Models\CatalogItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin CatalogItem */
class CatalogItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $disk = Storage::disk('public');

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'name' => $this->name,
            'price' => $this->price_kopecks / 100,
            'image' => [
                'height' => $this->image_height,
                'path' => $disk->url($this->image_path),
                'width' => $this->image_width,
            ],
            'preview' => [
                'height' => $this->preview_height,
                'path' => $disk->url($this->preview_path),
                'width' => $this->preview_width,
            ],
            'top' => $this->when($this->type === CatalogItemType::RightFigure, $this->top),
        ];
    }
}
