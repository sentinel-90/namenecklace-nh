<?php

namespace App\Models;

use App\Enums\CatalogItemType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'section_id',
    'type',
    'name',
    'price_kopecks',
    'top',
    'image_path',
    'image_width',
    'image_height',
    'preview_path',
    'preview_width',
    'preview_height',
])]
class CatalogItem extends Model
{
    public const IMAGE_DIRECTORY = 'constructor-catalog';

    public function section(): BelongsTo
    {
        return $this->belongsTo(CatalogSection::class);
    }

    protected static function booted(): void
    {
        static::saving(function (CatalogItem $item): void {
            $item->top = $item->type === CatalogItemType::RightFigure ? (bool) $item->top : null;
        });
    }

    protected function casts(): array
    {
        return [
            'type' => CatalogItemType::class,
            'price_kopecks' => 'integer',
            'top' => 'boolean',
            'image_width' => 'integer',
            'image_height' => 'integer',
            'preview_width' => 'integer',
            'preview_height' => 'integer',
        ];
    }
}
