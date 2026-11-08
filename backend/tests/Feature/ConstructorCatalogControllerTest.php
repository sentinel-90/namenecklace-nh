<?php

namespace Tests\Feature;

use App\Models\CatalogItem;
use App\Models\CatalogSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ConstructorCatalogControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_public_catalog_groups_components_and_exposes_image_urls_and_pixel_sizes(): void
    {
        config(['filesystems.disks.public.url' => 'https://catalog.example.test/storage']);
        $this->createItem('silver', ['type' => 'right_figure', 'name' => 'Звезда', 'top' => false]);
        $this->createItem('silver', ['type' => 'charm', 'name' => 'Луна']);
        $this->createItem('gold', ['type' => 'chain', 'name' => 'Якорная', 'price_kopecks' => 125075]);

        $response = $this->getJson('/api/v1/constructor/catalog', ['Origin' => 'https://namenecklace.ru']);

        $response->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'https://namenecklace.ru')
            ->assertJsonCount(5)
            ->assertJsonPath('gold_plated', [])
            ->assertJsonPath('white_gold', [])
            ->assertJsonPath('red_gold', [])
            ->assertJsonPath('silver.*.type', ['right_figure', 'charm'])
            ->assertJsonPath('silver.0.name', 'Звезда')
            ->assertJsonPath('silver.0.price', 0)
            ->assertJsonPath('silver.0.top', false)
            ->assertJsonPath('silver.0.image', [
                'height' => 320,
                'path' => 'https://catalog.example.test/storage/constructor-catalog/image.png',
                'width' => 240,
            ])
            ->assertJsonPath('silver.0.preview', [
                'height' => 60,
                'path' => 'https://catalog.example.test/storage/constructor-catalog/preview.webp',
                'width' => 80,
            ])
            ->assertJsonPath('gold.0.price', 1250.75)
            ->assertJsonMissingPath('gold.0.top');
    }

    public function test_the_type_filter_keeps_all_sections_and_only_returns_matching_components(): void
    {
        $silverChain = $this->createItem('silver', ['type' => 'chain', 'name' => 'Серебряная цепочка']);
        $goldChain = $this->createItem('gold', ['type' => 'chain', 'name' => 'Золотая цепочка']);
        $this->createItem('silver', ['type' => 'charm', 'name' => 'Серебряный шарм']);

        $this->getJson('/api/v1/constructor/catalog?type=chain')
            ->assertOk()
            ->assertJsonCount(5)
            ->assertJsonPath('silver.*.id', [$silverChain->id])
            ->assertJsonPath('silver.0.type', 'chain')
            ->assertJsonPath('gold.*.id', [$goldChain->id])
            ->assertJsonPath('gold_plated', [])
            ->assertJsonPath('white_gold', [])
            ->assertJsonPath('red_gold', []);
    }

    #[TestWith(['type=unknown'])]
    #[TestWith(['type='])]
    #[TestWith(['type%5B%5D=chain'])]
    public function test_an_invalid_type_filter_returns_422(string $query): void
    {
        $this->getJson('/api/v1/constructor/catalog?'.$query)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');
    }

    private function createItem(string $slug, array $attributes): CatalogItem
    {
        return CatalogItem::query()->create($attributes + [
            'section_id' => CatalogSection::query()->where('slug', $slug)->value('id'),
            'price_kopecks' => 0,
            'image_path' => 'constructor-catalog/image.png',
            'image_width' => 240,
            'image_height' => 320,
            'preview_path' => 'constructor-catalog/preview.webp',
            'preview_width' => 80,
            'preview_height' => 60,
        ]);
    }
}
