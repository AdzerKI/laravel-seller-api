<?php

namespace Opotopo\SellerApi\Catalog;

use Illuminate\Contracts\Filesystem\Filesystem;

final class FileRef
{
    private const HASH = 'sha256';

    private const OCTET_STREAM = 'application/octet-stream';

    public function __construct(
        public readonly string $source,
        private readonly Filesystem $filesystem,
        private readonly string $path,
    ) {}

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

    public function checksum(): string
    {
        $stream = $this->stream();
        try {
            $context = hash_init(self::HASH);
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    public function fingerprint(): array
    {
        return [$this->source, $this->size(), $this->modifiedAt()];
    }
}
