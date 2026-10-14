<?php

namespace Opotopo\SellerApi\Files;

use Closure;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\RequestException;
use Opotopo\SellerApi\Catalog\FileRef;
use Opotopo\SellerApi\Catalog\ItemFailure;
use Opotopo\SellerApi\Catalog\PendingItem;
use Opotopo\SellerApi\Http\SellerApiClient;
use Opotopo\SellerApi\Models\UploadedFile;

final class FileRegistry
{
    public const MAIN = 'MAIN';

    public const PROJECT = 'PROJECT';

    private const ETAG_HEADER = 'ETag';

    private const SOURCE_HASH = 'sha256';

    public function __construct(private readonly SellerApiClient $client) {}

    public function attach(PendingItem $item): array
    {
        $payload = $item->payload;
        if ($item->imageFiles !== []) {
            $payload['imageFileIds'] = array_map(
                fn (FileRef $file) => $this->resolve($file, self::MAIN, $item->categoryId),
                $item->imageFiles,
            );
        }
        if ($item->secondImageFile !== null) {
            $payload['secondImageFileId'] = $this->resolve($item->secondImageFile, self::MAIN, $item->categoryId);
        }
        foreach ($item->variantFiles as $index => $files) {
            if ($files !== []) {
                $payload['variants'][$index]['fileIds'] = array_map(
                    fn (FileRef $file) => $this->resolve($file, self::PROJECT, $item->categoryId),
                    $files,
                );
            }
        }

        return $payload;
    }

    public function forget(array $fileIds): void
    {
        UploadedFile::query()->whereIn('file_id', $fileIds)->delete();
    }

    private function resolve(FileRef $file, string $gallery, string $categoryId): string
    {
        $target = ['source_hash' => hash(self::SOURCE_HASH, $file->source), 'gallery' => $gallery, 'category_id' => $categoryId];
        $known = UploadedFile::query()->where($target)->first();
        $size = $file->size();
        $modifiedAt = $file->modifiedAt();
        if ($known !== null && $known->size === $size && $known->modified_at === $modifiedAt) {
            return $known->file_id;
        }
        $checksum = $file->checksum();
        if ($known !== null && $known->checksum === $checksum) {
            $known->update(['size' => $size, 'modified_at' => $modifiedAt]);

            return $known->file_id;
        }
        $fileId = $this->upload($file, $gallery, $categoryId, $size);
        UploadedFile::query()->updateOrCreate($target, [
            'source' => $file->source,
            'size' => $size,
            'modified_at' => $modifiedAt,
            'checksum' => $checksum,
            'file_id' => $fileId,
        ]);

        return $fileId;
    }

    private function upload(FileRef $file, string $gallery, string $categoryId, int $size): string
    {
        $contentType = $file->mimeType();
        $initiated = $this->accepted($file, fn () => $this->client->initiateUpload([
            'gallery' => $gallery,
            'categoryId' => $categoryId,
            'filename' => $file->name(),
            'contentType' => $contentType,
            'size' => $size,
        ]));
        $headers = [SellerApiClient::CONTENT_TYPE_HEADER => $contentType, ...($initiated['requiredHeaders'] ?? [])];
        $stream = $file->stream();
        $parts = [];
        try {
            if (! empty($initiated['singleUrl'])) {
                $this->client->put($initiated['singleUrl'], $headers, Utils::streamFor($stream, ['size' => $size]));
            } else {
                $urls = $initiated['partUrls'] ?? [];
                usort($urls, fn (array $left, array $right) => $left['partNumber'] <=> $right['partNumber']);
                foreach ($urls as $part) {
                    $response = $this->client->put($part['url'], $headers, self::read($stream, (int) $initiated['partSize']));
                    $parts[] = ['partNumber' => $part['partNumber'], 'etag' => $response->header(self::ETAG_HEADER)];
                }
            }
        } finally {
            fclose($stream);
        }
        $this->accepted($file, fn () => $this->client->completeUpload($initiated['fileId'], $parts));

        return $initiated['fileId'];
    }

    private function accepted(FileRef $file, Closure $call): mixed
    {
        try {
            return $call();
        } catch (RequestException $failure) {
            if (! SellerApiClient::rejected($failure->response)) {
                throw $failure;
            }

            throw ItemFailure::fromResponse($failure->response, [ItemFailure::FILE_DETAIL => $file->source]);
        }
    }

    private static function read($stream, int $length): string
    {
        $chunk = '';
        while (strlen($chunk) < $length && ! feof($stream)) {
            $chunk .= fread($stream, $length - strlen($chunk));
        }

        return $chunk;
    }
}
