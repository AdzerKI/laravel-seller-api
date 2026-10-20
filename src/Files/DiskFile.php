<?php

namespace Opotopo\SellerApi\Laravel\Files;

use Illuminate\Contracts\Filesystem\Filesystem;
use Opotopo\SellerApi\Files\FileSource;

final class DiskFile implements FileSource
{
    private const OCTET_STREAM = 'application/octet-stream';

    public function __construct(
        private readonly string $source,
        private readonly Filesystem $filesystem,
        private readonly string $path,
    ) {}

    public function source(): string
    {
        return $this->source;
    }

    public function exists(): bool
    {
        return $this->filesystem->exists($this->path);
    }

    public function name(): string
    {
        return basename($this->path);
    }

    public function size(): int
    {
        return $this->filesystem->size($this->path);
    }

    public function modifiedAt(): int
    {
        return $this->filesystem->lastModified($this->path);
    }

    public function mimeType(): string
    {
        return $this->filesystem->mimeType($this->path) ?: self::OCTET_STREAM;
    }

    public function stream()
    {
        return $this->filesystem->readStream($this->path);
    }
}
