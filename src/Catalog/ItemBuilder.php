<?php

namespace Opotopo\SellerApi\Catalog;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Str;
use SplFileInfo;
use Stringable;
use Traversable;

final class ItemBuilder
{
    private const URL_SCHEMES = ['http://', 'https://'];

    private const NUMERIC_FIELDS = ['price', 'quantity'];

    private const ORIENTATION_AXES = ['rollDeg', 'pitchDeg', 'yawDeg'];

    private const YES = 'да';

    private const NO = 'нет';

    private const REQUIRED = 'REQUIRED';

    private const HASH = 'sha256';

    public function __construct(private readonly FilesystemManager $filesystems) {}

    public function build(Model $model, array $catalog): PendingItem
    {
        $modelKey = (string) $model->getKey();
        $payload = [];
        foreach ($catalog['fields'] ?? [] as $field => $path) {
            $value = self::field($field, self::value($model, $path));
            if ($value !== null) {
                $payload[$field] = $value;
            }
        }
        $sku = (string) ($payload['sku'] ?? '');

        try {
            return $this->item($model, $catalog, $modelKey, $sku, $payload);
        } catch (ItemFailure $failure) {
            return PendingItem::failed($modelKey, $sku, $failure);
        }
    }

    private function item(Model $model, array $catalog, string $modelKey, string $sku, array $payload): PendingItem
    {
        $disk = $catalog['disk'] ?? null;
        $categoryId = self::text(self::value($model, $catalog['category'] ?? null));
        if ($categoryId !== null) {
            $payload['categoryId'] = $categoryId;
        }

        $attributes = [];
        foreach ($catalog['attributes'] ?? [] as $name => $path) {
            $value = self::text(self::value($model, $path));
            if ($value !== null) {
                $attributes[$name] = $value;
            }
        }
        if ($attributes !== []) {
            $payload['attributes'] = $attributes;
        }

        $roles = $catalog['images'] ?? [];
        $cover = array_slice($this->media($model, $roles['cover'] ?? null, $disk), 0, 1);
        $second = $this->media($model, $roles['second'] ?? null, $disk)[0] ?? null;
        $images = self::unique(array_merge(
            $cover,
            $this->media($model, $roles['gallery'] ?? null, $disk),
            $second === null ? [] : [$second],
        ));
        $imageFiles = array_values(array_filter($images, fn ($image) => $image instanceof FileRef));
        $imageUrls = array_values(array_filter($images, 'is_string'));
        if ($imageFiles !== [] && $cover !== [] && is_string($cover[0])) {
            throw new ItemFailure(ItemFailure::COVER_URL_AFTER_FILES, ['cover' => $cover[0]]);
        }
        if ($imageUrls !== []) {
            $payload['imageUrls'] = $imageUrls;
        }
        if (is_string($second)) {
            $payload['secondImageUrl'] = $second;
        }

        $model3d = $catalog['model3d'] ?? [];
        $model3dUrl = self::text(self::value($model, $model3d['url'] ?? null));
        if ($model3dUrl !== null) {
            $payload['model3dUrl'] = $model3dUrl;
            $orientation = self::orientation(self::value($model, $model3d['orientation'] ?? null));
            if ($orientation !== null) {
                $payload['model3dOrientation'] = $orientation;
            }
        }

        $variants = [];
        $variantFiles = [];
        foreach ($catalog['variants'] ?? [] as $variant) {
            $price = self::field('price', self::value($model, $variant['price'] ?? null));
            if ($price === null) {
                continue;
            }
            $files = $this->media($model, $variant['files'] ?? null, $disk);
            $fileUrls = array_values(array_filter($files, 'is_string'));
            $variants[] = array_filter([
                'name' => (string) $variant['name'],
                'price' => $price,
                'includes3dModel' => (bool) ($variant['includes_3d_model'] ?? false),
                'fileUrls' => $fileUrls ?: null,
            ], fn ($value) => $value !== null);
            $variantFiles[] = array_values(array_filter($files, fn ($file) => $file instanceof FileRef));
        }
        if ($variants !== []) {
            $payload['variants'] = $variants;
        }

        $files = array_merge($imageFiles, ...$variantFiles);
        foreach ($files as $file) {
            if (! $file->exists()) {
                throw new ItemFailure(ItemFailure::FILE_NOT_FOUND, ['file' => $file->source]);
            }
        }
        if ($files !== [] && $categoryId === null) {
            throw new ItemFailure(ItemFailure::VALIDATION_ERROR, ['category' => self::REQUIRED]);
        }

        $fingerprint = hash(self::HASH, json_encode([
            $payload,
            array_map(fn (FileRef $file) => $file->fingerprint(), $imageFiles),
            $second instanceof FileRef ? $second->source : null,
            array_map(fn (array $group) => array_map(fn (FileRef $file) => $file->fingerprint(), $group), $variantFiles),
        ], JSON_THROW_ON_ERROR));

        return new PendingItem(
            $modelKey,
            $sku,
            $categoryId,
            $payload,
            $imageFiles,
            $second instanceof FileRef ? $second : null,
            $variantFiles,
            $fingerprint,
        );
    }

    private function media(Model $model, ?string $path, ?string $disk): array
    {
        $value = self::value($model, $path);
        $entries = match (true) {
            $value instanceof Traversable => iterator_to_array($value, false),
            is_array($value) => array_values($value),
            default => [$value],
        };
        $media = [];
        foreach ($entries as $entry) {
            if ($entry !== null && $entry !== '') {
                $media[] = $this->resolve($entry, $disk);
            }
        }

        return $media;
    }

    private function resolve(mixed $entry, ?string $disk): string|FileRef
    {
        if ($entry instanceof SplFileInfo) {
            $path = $entry->getRealPath() ?: $entry->getPathname();

            return new FileRef(
                'file:'.$path,
                $this->filesystems->build(['driver' => 'local', 'root' => dirname($path)]),
                basename($path),
            );
        }
        $text = (string) $entry;
        if (Str::startsWith($text, self::URL_SCHEMES)) {
            return $text;
        }
        $name = $disk ?? $this->filesystems->getDefaultDriver();

        return new FileRef('disk:'.$name.':'.$text, $this->filesystems->disk($name), $text);
    }

    private static function unique(array $media): array
    {
        $seen = [];
        $unique = [];
        foreach ($media as $entry) {
            $key = $entry instanceof FileRef ? $entry->source : $entry;
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $entry;
            }
        }

        return $unique;
    }

    private static function value(Model $model, ?string $path): mixed
    {
        return $path === null ? null : data_get($model, $path);
    }

    private static function field(string $field, mixed $value): mixed
    {
        $value = $value instanceof BackedEnum ? $value->value : $value;
        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return self::number($value);
        }
        $text = (string) $value;
        if (in_array($field, self::NUMERIC_FIELDS, true) && is_numeric($text)) {
            return self::number($text + 0);
        }

        return $text;
    }

    private static function number(int|float $value): int|float
    {
        return is_float($value) && $value == (int) $value ? (int) $value : $value;
    }

    private static function text(mixed $value): ?string
    {
        return match (true) {
            $value === null, $value === '' => null,
            is_bool($value) => $value ? self::YES : self::NO,
            $value instanceof BackedEnum => (string) $value->value,
            is_scalar($value), $value instanceof Stringable => (string) $value,
            default => null,
        };
    }

    private static function orientation(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (! is_array($value)) {
            return null;
        }
        $orientation = [];
        foreach (self::ORIENTATION_AXES as $axis) {
            if (! is_numeric($value[$axis] ?? null)) {
                return null;
            }
            $orientation[$axis] = (int) $value[$axis];
        }

        return $orientation;
    }
}
