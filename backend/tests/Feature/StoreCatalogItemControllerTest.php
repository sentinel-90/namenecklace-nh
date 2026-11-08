<?php

namespace Tests\Feature;

use App\Models\CatalogItem;
use App\Services\DownloadCatalogImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class StoreCatalogItemControllerTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/constructor/catalog';

    public function test_catalog_writes_require_the_configured_token(): void
    {
        config(['services.catalog.write_token' => 'catalog-test-token']);

        $this->withToken('incorrect')->postJson(self::ENDPOINT, [])
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer');

        $this->assertDatabaseCount('catalog_items', 0);
    }

    public function test_an_authenticated_client_can_create_a_chain_from_image_urls(): void
    {
        config(['services.catalog.write_token' => 'catalog-test-token']);
        $disk = Storage::fake('public');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAADCAAAAACcgYFdAAAAC0lEQVR4nGNggAIAAAkAAftSuKkAAAAASUVORK5CYII=', true);
        $image = 'https://example.test/chain.png';
        $preview = 'https://example.test/preview.png';
        $this->mock(DownloadCatalogImage::class, function (MockInterface $mock) use ($image, $preview, $png): void {
            foreach ([$image, $preview] as $url) {
                $mock->expects('download')->with($url, Mockery::any())
                    ->andReturnUsing(fn (string $url, $file): bool => fwrite($file, $png) !== false);
            }
        });

        $this->withToken('catalog-test-token')->postJson(self::ENDPOINT, [
            'section' => 'white_gold',
            'type' => 'chain',
            'name' => 'Якорная цепочка',
            'price' => '2600',
            'image_url' => $image,
            'preview_url' => $preview,
        ])->assertCreated()
            ->assertJsonPath('data.type', 'chain')
            ->assertJsonPath('data.price', 2600)
            ->assertJsonPath('data.image.width', 2)
            ->assertJsonPath('data.preview.height', 3)
            ->assertJsonMissingPath('data.top');

        $item = CatalogItem::query()->sole();
        $this->assertSame('white_gold', $item->section->slug);
        $this->assertSame(260000, $item->price_kopecks);
        $disk->assertExists([$item->image_path, $item->preview_path]);
    }
}
