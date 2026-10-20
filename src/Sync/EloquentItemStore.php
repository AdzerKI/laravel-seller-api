<?php

namespace Opotopo\SellerApi\Laravel\Sync;

use Opotopo\SellerApi\Laravel\Models\SyncedItem;
use Opotopo\SellerApi\Sync\ItemState;
use Opotopo\SellerApi\Sync\ItemStore;

final class EloquentItemStore implements ItemStore
{
    private const UNIQUE = ['catalog', 'model_key'];

    public function load(string $catalog): array
    {
        $states = [];
        foreach (SyncedItem::query()->where('catalog', $catalog)->lazyById() as $record) {
            $states[$record->model_key] = new ItemState(
                $record->model_key,
                $record->sku,
                $record->advertisement_id,
                $record->fingerprint,
                $record->error,
                $record->error_details,
                $record->synced_at?->toDateTimeImmutable(),
            );
        }

        return $states;
    }

    public function save(string $catalog, ItemState $state): void
    {
        $values = [
            'sku' => $state->sku,
            'advertisement_id' => $state->advertisementId,
            'fingerprint' => $state->fingerprint,
            'error' => $state->error,
            'error_details' => $state->errorDetails === null ? null : json_encode($state->errorDetails, JSON_THROW_ON_ERROR),
            'synced_at' => $state->syncedAt,
        ];
        SyncedItem::query()->upsert(
            ['catalog' => $catalog, 'model_key' => $state->key, ...$values],
            self::UNIQUE,
            array_keys($values),
        );
    }

    public function delete(string $catalog, array $keys): void
    {
        SyncedItem::query()->where('catalog', $catalog)->whereIn('model_key', $keys)->delete();
    }
}
