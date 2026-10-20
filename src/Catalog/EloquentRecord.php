<?php

namespace Opotopo\SellerApi\Laravel\Catalog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\FilesystemManager;
use Opotopo\SellerApi\Catalog\ItemRecord;
use Opotopo\SellerApi\Files\FileSource;
use Opotopo\SellerApi\Laravel\Files\DiskFile;
use SplFileInfo;

final class EloquentRecord implements ItemRecord
{
    public function __construct(
        private readonly Model $model,
        private readonly FilesystemManager $filesystems,
        private readonly ?string $disk,
    ) {}

    public function key(): string
    {
        return (string) $this->model->getKey();
    }

    public function value(string $path): mixed
    {
        return data_get($this->model, $path);
    }

    public function file(mixed $entry): FileSource
    {
        if ($entry instanceof SplFileInfo) {
            $path = $entry->getRealPath() ?: $entry->getPathname();

            return new DiskFile(
                'file:'.$path,
                $this->filesystems->build(['driver' => 'local', 'root' => dirname($path)]),
                basename($path),
            );
        }
        $path = (string) $entry;
        $name = $this->disk ?? $this->filesystems->getDefaultDriver();

        return new DiskFile('disk:'.$name.':'.$path, $this->filesystems->disk($name), $path);
    }
}
