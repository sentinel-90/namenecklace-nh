<?php

namespace Tests\Feature\Filament;

use App\Enums\CatalogItemType;
use App\Filament\Resources\CatalogItems\Pages\ListCatalogItems;
use App\Models\CatalogItem;
use App\Models\CatalogSection;
use App\Models\User;
use App\Services\DownloadCatalogImage;
use App\Services\SaveCatalogItem;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

class ListCatalogItemsTest extends TestCase
{
    use RefreshDatabase;

    private const PORTRAIT = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAADCAAAAACcgYFdAAAAC0lEQVR4nGNggAIAAAkAAftSuKkAAAAASUVORK5CYII=';

    private const LANDSCAPE = 'iVBORw0KGgoAAAANSUhEUgAAAAMAAAACCAAAAAC4HznGAAAAC0lEQVR4nGNggAAAAAgAAbdYc5UAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_the_admin_catalog_redirects_guests_to_sign_in(): void
    {
        $this->get('/admin/catalog-items')->assertRedirect('/admin/login');
    }

    public function test_an_employee_can_publish_a_free_right_figure_with_two_measured_images(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create());

        Livewire::test(ListCatalogItems::class)
            ->mountAction('create')
            ->fillForm([
                'section_id' => CatalogSection::query()->where('slug', 'silver')->value('id'),
                'type' => 'right_figure',
                'name' => 'Звезда',
                'price_kopecks' => '0',
                'top' => true,
            ])
            ->set('mountedActions.0.data.image_path', [$this->image(self::PORTRAIT)])
            ->set('mountedActions.0.data.preview_path', [$this->image(self::LANDSCAPE)])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $item = CatalogItem::query()->sole();
        Storage::disk('public')->assertExists([$item->image_path, $item->preview_path]);
        $this->assertNotSame($item->image_path, $item->preview_path);
        $this->assertDatabaseHas('catalog_items', [
            'id' => $item->id,
            'price_kopecks' => 0,
            'top' => true,
            'image_width' => 2,
            'image_height' => 3,
            'preview_width' => 3,
            'preview_height' => 2,
        ]);
        $this->get('/admin/catalog-items')->assertOk()->assertSeeText(['Компоненты конструктора', 'Звезда']);
    }

    public function test_editing_replaces_only_the_uploaded_file_and_clears_top_for_a_different_type(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create());
        $item = $this->rightFigure();
        $oldImage = $item->image_path;
        $preview = $item->preview_path;

        Livewire::test(ListCatalogItems::class)
            ->mountAction(TestAction::make('edit')->table($item))
            ->fillForm(['type' => 'charm', 'price_kopecks' => '30,15'])
            ->set('mountedActions.0.data.image_path', [])
            ->set('mountedActions.0.data.image_path', [$this->image(self::LANDSCAPE)])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $item->refresh();
        $this->assertSame(CatalogItemType::Charm, $item->type);
        $this->assertNull($item->top);
        $this->assertSame(3015, $item->price_kopecks);
        $this->assertSame(3, $item->image_width);
        $this->assertSame(2, $item->image_height);
        $this->assertSame($preview, $item->preview_path);
        Storage::disk('public')->assertExists([$item->image_path, $preview]);
        Storage::disk('public')->assertMissing($oldImage);
    }

    public function test_an_employee_can_import_both_images_from_links(): void
    {
        $disk = Storage::fake('public');
        $this->actingAs(User::factory()->create());
        $imageUrl = 'https://images.example.test/portrait';
        $previewUrl = 'https://images.example.test/landscape';
        $this->mock(DownloadCatalogImage::class, function (MockInterface $mock) use ($imageUrl, $previewUrl): void {
            $mock->expects('download')->with($imageUrl, \Mockery::any())
                ->andReturnUsing(fn (string $url, $file): bool => fwrite($file, base64_decode(self::PORTRAIT, true)) !== false);
            $mock->expects('download')->with($previewUrl, \Mockery::any())
                ->andReturnUsing(fn (string $url, $file): bool => fwrite($file, base64_decode(self::LANDSCAPE, true)) !== false);
        });

        Livewire::test(ListCatalogItems::class)
            ->callAction('create', data: [
                'section_id' => CatalogSection::query()->where('slug', 'silver')->value('id'),
                'type' => 'charm',
                'name' => 'Звезда',
                'price_kopecks' => '0',
                'image_url' => $imageUrl,
                'preview_url' => $previewUrl,
            ])
            ->assertHasNoFormErrors();

        $item = CatalogItem::query()->sole();
        $this->assertSame([2, 3], [$item->image_width, $item->image_height]);
        $this->assertSame([3, 2], [$item->preview_width, $item->preview_height]);
        $this->assertSame(base64_decode(self::PORTRAIT, true), $disk->get($item->image_path));
        $this->assertSame(base64_decode(self::LANDSCAPE, true), $disk->get($item->preview_path));
    }

    public function test_a_link_replaces_the_existing_preview_and_keeps_the_main_image(): void
    {
        $disk = Storage::fake('public');
        $this->actingAs(User::factory()->create());
        $item = $this->rightFigure();
        $previewUrl = 'https://images.example.test/replacement';
        $this->mock(DownloadCatalogImage::class, function (MockInterface $mock) use ($previewUrl): void {
            $mock->expects('download')->with($previewUrl, \Mockery::any())
                ->andReturnUsing(fn (string $url, $file): bool => fwrite($file, base64_decode(self::PORTRAIT, true)) !== false);
        });

        Livewire::test(ListCatalogItems::class)
            ->callAction(TestAction::make('edit')->table($item), data: ['preview_url' => $previewUrl])
            ->assertHasNoFormErrors();

        $updated = $item->fresh();
        $this->assertSame($item->image_path, $updated->image_path);
        $this->assertSame([2, 3], [$updated->preview_width, $updated->preview_height]);
        $disk->assertExists($updated->preview_path);
        $disk->assertMissing($item->preview_path);
    }

    public function test_a_private_image_link_is_rejected_without_replacing_the_existing_file(): void
    {
        $disk = Storage::fake('public');
        $this->actingAs(User::factory()->create());
        $item = $this->rightFigure();

        Livewire::test(ListCatalogItems::class)
            ->callAction(TestAction::make('edit')->table($item), data: ['image_url' => 'http://127.0.0.1/image.png'])
            ->assertHasFormErrors(['image_url']);

        $this->assertSame($item->image_path, $item->fresh()->image_path);
        $disk->assertExists($item->image_path);
    }

    public function test_a_tampered_preview_path_is_rejected_without_replacing_the_original_files(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create());
        $item = $this->rightFigure();

        Livewire::test(ListCatalogItems::class)
            ->mountAction(TestAction::make('edit')->table($item))
            ->set('mountedActions.0.data.preview_path', [$item->image_path])
            ->callMountedAction()
            ->assertHasFormErrors(['preview_path']);

        $this->assertSame($item->preview_path, $item->fresh()->preview_path);
        Storage::disk('public')->assertExists([$item->image_path, $item->preview_path]);
    }

    public function test_an_invalid_image_leaves_the_previous_file_in_place(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create());
        $item = $this->rightFigure();
        $invalidImage = UploadedFile::fake()->createWithContent('invalid.png', 'invalid image');

        Livewire::test(ListCatalogItems::class)
            ->mountAction(TestAction::make('edit')->table($item))
            ->set('mountedActions.0.data.image_path', [])
            ->set('mountedActions.0.data.image_path', [$invalidImage])
            ->callMountedAction()
            ->assertHasFormErrors(['image_path']);

        $this->assertSame($item->image_path, $item->fresh()->image_path);
        Storage::disk('public')->assertExists($item->image_path);
    }

    public function test_deleting_a_component_removes_both_public_files(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create());
        $item = $this->rightFigure();

        Livewire::test(ListCatalogItems::class)->callAction(TestAction::make('delete')->table($item));

        $this->assertModelMissing($item);
        Storage::disk('public')->assertMissing([$item->image_path, $item->preview_path]);
    }

    private function rightFigure(): CatalogItem
    {
        return app(SaveCatalogItem::class)->handle([
            'section_id' => CatalogSection::query()->where('slug', 'silver')->value('id'),
            'type' => 'right_figure',
            'name' => 'Звезда',
            'price_kopecks' => 0,
            'top' => true,
            'image_path' => $this->image(self::PORTRAIT),
            'preview_path' => $this->image(self::LANDSCAPE),
        ]);
    }

    private function image(string $base64): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('component.png', base64_decode($base64, true));
    }
}
