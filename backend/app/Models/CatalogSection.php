<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogSection extends Model
{
    public $timestamps = false;

    public function items(): HasMany
    {
        return $this->hasMany(CatalogItem::class, 'section_id');
    }
}
