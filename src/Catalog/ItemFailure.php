<?php

namespace Opotopo\SellerApi\Catalog;

use RuntimeException;

final class ItemFailure extends RuntimeException
{
    public const FILE_NOT_FOUND = 'FILE_NOT_FOUND';

    public const COVER_URL_AFTER_FILES = 'COVER_URL_AFTER_FILES';

    public const VALIDATION_ERROR = 'VALIDATION_ERROR';

    public function __construct(
        public readonly string $error,
        public readonly array $details = [],
    ) {
        parent::__construct($error);
    }
}
