<?php

namespace Opotopo\SellerApi\Catalog;

use Illuminate\Http\Client\Response;
use RuntimeException;

final class ItemFailure extends RuntimeException
{
    public const FILE_NOT_FOUND = 'FILE_NOT_FOUND';

    public const COVER_URL_AFTER_FILES = 'COVER_URL_AFTER_FILES';

    public const VALIDATION_ERROR = 'VALIDATION_ERROR';

    public const FILE_DETAIL = 'file';

    public function __construct(
        public readonly string $error,
        public readonly array $details = [],
    ) {
        parent::__construct($error);
    }

    public static function fromResponse(Response $response, array $details = []): self
    {
        $problem = $response->json() ?? [];

        return new self(
            (string) ($problem['title'] ?? $response->status()),
            array_merge($problem['errors'] ?? [], $problem['details'] ?? [], $details),
        );
    }
}
