<?php

namespace Opotopo\SellerApi\Laravel\Files;

use Opotopo\SellerApi\Files\FileStore;
use Opotopo\SellerApi\Files\FileTarget;
use Opotopo\SellerApi\Files\StoredFile;
use Opotopo\SellerApi\Laravel\Models\UploadedFile;

final class EloquentFileStore implements FileStore
{
    public function find(FileTarget $target): ?StoredFile
    {
        $record = UploadedFile::query()->where(self::key($target))->first();

        return $record === null
            ? null
            : new StoredFile($record->source, $record->size, $record->modified_at, $record->checksum, $record->file_id);
    }

    public function save(FileTarget $target, StoredFile $file): void
    {
        UploadedFile::query()->updateOrCreate(self::key($target), [
            'source' => $file->source,
            'size' => $file->size,
            'modified_at' => $file->modifiedAt,
            'checksum' => $file->checksum,
            'file_id' => $file->fileId,
        ]);
    }

    public function forget(array $fileIds): void
    {
        UploadedFile::query()->whereIn('file_id', $fileIds)->delete();
    }

    private static function key(FileTarget $target): array
    {
        return ['source_hash' => $target->sourceHash, 'gallery' => $target->gallery, 'category_id' => $target->categoryId];
    }
}
