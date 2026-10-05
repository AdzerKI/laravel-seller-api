<?php

namespace Opotopo\SellerApi\Catalog;

final class PendingItem
{
    public function __construct(
        public readonly string $modelKey,
        public readonly string $sku,
        public readonly ?string $categoryId,
        public readonly array $payload,
        public readonly array $imageFiles,
        public readonly ?FileRef $secondImageFile,
        public readonly array $variantFiles,
        public readonly string $fingerprint,
        public readonly ?ItemFailure $failure = null,
    ) {}

    public static function failed(string $modelKey, string $sku, ItemFailure $failure): self
    {
        return new self($modelKey, $sku, null, [], [], null, [], '', $failure);
    }
}
