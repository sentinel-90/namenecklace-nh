<?php

namespace App\Services;

use App\Models\CatalogItem;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class SaveCatalogItem
{
    public const IMAGE_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(private DownloadCatalogImage $downloadImage) {}

    public function handle(array $data, ?CatalogItem $item = null): CatalogItem
    {
        $attributes = Arr::only($data, ['section_id', 'type', 'name', 'price_kopecks', 'top']);
        $newPaths = [];

        try {
            foreach (['image', 'preview'] as $image) {
                $field = $image.'_path';
                $urlField = $image.'_url';
                $upload = $data[$field] ?? null;
                $url = $data[$urlField] ?? null;

                if (filled($url)) {
                    if ($upload instanceof UploadedFile) {
                        throw ValidationException::withMessages([$urlField => 'Выберите один источник: файл или ссылку.']);
                    }

                    [$path, $width, $height] = $this->importImage($url, $urlField);
                } elseif ($upload instanceof UploadedFile) {
                    [$path, $width, $height] = $this->storeImage($upload, $field);
                } elseif ($item === null) {
                    throw ValidationException::withMessages([$field => 'Загрузите изображение или укажите ссылку.']);
                } else {
                    continue;
                }

                $newPaths[$field] = $path;
                $attributes[$field] = $path;
                $attributes[$image.'_width'] = $width;
                $attributes[$image.'_height'] = $height;
            }

            [$savedItem, $oldPaths] = DB::transaction(function () use ($attributes, $item, $newPaths): array {
                $record = $item === null ? new CatalogItem : CatalogItem::query()->lockForUpdate()->findOrFail($item->getKey());
                $oldPaths = Arr::only($record->getAttributes(), array_keys($newPaths));

                $record->fill($attributes)->saveOrFail();

                return [$record, array_values($oldPaths)];
            });
        } catch (Throwable $exception) {
            Storage::disk('public')->delete(array_values($newPaths));

            throw $exception;
        }

        if ($oldPaths !== []) {
            DB::afterCommit(fn () => Storage::disk('public')->delete($oldPaths));
        }

        return $savedItem;
    }

    public function delete(CatalogItem $item): bool
    {
        $paths = DB::transaction(function () use ($item): array {
            $record = CatalogItem::query()->lockForUpdate()->findOrFail($item->getKey());

            return $record->delete() ? [$record->image_path, $record->preview_path] : [];
        });

        if ($paths === []) {
            return false;
        }

        DB::afterCommit(fn () => Storage::disk('public')->delete($paths));

        return true;
    }

    /** @return array{string, int, int} */
    private function importImage(string $url, string $field): array
    {
        $file = tmpfile();

        if ($file === false) {
            throw new RuntimeException('Не удалось подготовить файл изображения.');
        }

        try {
            if (! $this->downloadImage->download($url, $file)) {
                throw ValidationException::withMessages([$field => 'Не удалось скачать изображение. Проверьте публичную ссылку и размер файла (до 5 МБ).']);
            }

            return $this->storeImage(new File(stream_get_meta_data($file)['uri']), $field);
        } finally {
            fclose($file);
        }
    }

    /** @return array{string, int, int} */
    private function storeImage(File|UploadedFile $file, string $field): array
    {
        $size = @getimagesize($file->getPathname());
        $mime = $file->getMimeType();
        $extension = self::IMAGE_EXTENSIONS[$mime] ?? null;

        if ($size === false || $size[0] < 1 || $size[1] < 1 || $extension === null || $size['mime'] !== $mime) {
            throw ValidationException::withMessages([$field => 'Не удалось прочитать изображение JPEG, PNG или WebP.']);
        }

        $path = Storage::disk('public')->putFileAs(
            CatalogItem::IMAGE_DIRECTORY,
            $file,
            Str::uuid().'.'.$extension,
            'public',
        );

        if ($path === false) {
            throw new RuntimeException('Не удалось сохранить изображение компонента.');
        }

        return [$path, $size[0], $size[1]];
    }
}
