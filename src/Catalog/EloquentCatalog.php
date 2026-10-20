<?php

namespace Opotopo\SellerApi\Laravel\Catalog;

use Illuminate\Filesystem\FilesystemManager;
use Opotopo\SellerApi\Catalog\CatalogSource;

final class EloquentCatalog implements CatalogSource
{
    public function __construct(
        private readonly array $catalog,
        private readonly FilesystemManager $filesystems,
    ) {}

    public function mapping(): array
    {
        return $this->catalog;
    }

    public function records(): iterable
    {
        $query = $this->catalog['model']::query();
        if (! empty($this->catalog['scope'])) {
            $query->{$this->catalog['scope']}();
        }
        foreach ($query->lazyById() as $model) {
            yield new EloquentRecord($model, $this->filesystems, $this->catalog['disk'] ?? null);
        }
    }
}
