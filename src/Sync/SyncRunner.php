<?php

namespace Opotopo\SellerApi\Sync;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Opotopo\SellerApi\Catalog\ItemBuilder;
use Opotopo\SellerApi\Catalog\ItemFailure;
use Opotopo\SellerApi\Catalog\PendingItem;
use Opotopo\SellerApi\Files\FileRegistry;
use Opotopo\SellerApi\Http\SellerApiClient;
use Opotopo\SellerApi\Models\SyncedItem;

final class SyncRunner
{
    public const FILES_UNAVAILABLE = 'FILES_UNAVAILABLE';

    private const FILE_IDS_DETAIL = 'fileIds';

    private const FILE_IDS_SEPARATOR = ',';

    public function __construct(
        private readonly SellerApiClient $client,
        private readonly ItemBuilder $builder,
        private readonly FileRegistry $files,
        private readonly array $config,
    ) {}

    public function run(?string $only = null, bool $full = false): SyncReport
    {
        $catalogs = $this->config['catalogs'] ?? [];
        if ($only !== null && ! isset($catalogs[$only])) {
            throw new InvalidArgumentException($only);
        }
        $report = new SyncReport;
        foreach ($catalogs as $name => $catalog) {
            if ($only === null || $only === $name) {
                $this->catalog((string) $name, $catalog, $full, $report);
            }
        }

        return $report;
    }

    private function catalog(string $name, array $catalog, bool $full, SyncReport $report): void
    {
        $known = SyncedItem::query()->where('catalog', $name)->get()->keyBy('model_key');
        $seen = [];
        $pending = [];
        $batch = max(1, (int) ($this->config['batch'] ?? 1));
        foreach ($this->query($catalog)->lazyById() as $model) {
            $item = $this->builder->build($model, $catalog);
            $seen[$item->modelKey] = true;
            $record = $known->get($item->modelKey);
            if ($item->failure !== null) {
                $this->failed($name, $item, $known, $report, $item->failure->error, $item->failure->details);

                continue;
            }
            if (! $full && $record !== null && $record->error === null && $record->fingerprint === $item->fingerprint) {
                $report->skipped++;

                continue;
            }
            $pending[] = $item;
            if (count($pending) >= $batch) {
                $this->send($name, $pending, $known, $report, true);
                $pending = [];
            }
        }
        if ($pending !== []) {
            $this->send($name, $pending, $known, $report, true);
        }
        foreach ($known as $modelKey => $record) {
            if (! isset($seen[$modelKey])) {
                if ($record->advertisement_id !== null) {
                    $this->client->archive($record->advertisement_id);
                }
                $record->delete();
                $report->archived++;
            }
        }
    }

    private function query(array $catalog): Builder
    {
        $query = $catalog['model']::query();
        if (! empty($catalog['scope'])) {
            $query->{$catalog['scope']}();
        }

        return $query;
    }

    private function send(string $catalog, array $items, Collection $known, SyncReport $report, bool $reupload): void
    {
        $ready = [];
        $payloads = [];
        foreach ($items as $item) {
            try {
                $payloads[] = $this->files->attach($item);
                $ready[] = $item;
            } catch (ItemFailure $failure) {
                $this->failed($catalog, $item, $known, $report, $failure->error, $failure->details);
            }
        }
        if ($ready === []) {
            return;
        }
        try {
            $response = $this->client->sync($payloads, (string) Str::uuid());
        } catch (RequestException $failure) {
            if (! SellerApiClient::rejected($failure->response)) {
                throw $failure;
            }
            if (count($ready) > 1) {
                foreach ($ready as $item) {
                    $this->send($catalog, [$item], $known, $report, $reupload);
                }

                return;
            }
            $rejection = ItemFailure::fromResponse($failure->response);
            $this->failed($catalog, $ready[0], $known, $report, $rejection->error, $rejection->details);

            return;
        }
        $retry = [];
        foreach ($response['results'] ?? [] as $index => $result) {
            $item = $ready[$index];
            $error = $result['error'] ?? null;
            if ($error === self::FILES_UNAVAILABLE && $reupload) {
                $this->files->forget(explode(
                    self::FILE_IDS_SEPARATOR,
                    (string) ($result['errorDetails'][self::FILE_IDS_DETAIL] ?? ''),
                ));
                $retry[] = $item;

                continue;
            }
            $this->applied($catalog, $item, $result, $known, $report);
        }
        if ($retry !== []) {
            $this->send($catalog, $retry, $known, $report, false);
        }
    }

    private function applied(string $catalog, PendingItem $item, array $result, Collection $known, SyncReport $report): void
    {
        $record = $this->record($catalog, $item, $known);
        $advertisementId = $result['advertisementId'] ?? null;
        if ($advertisementId !== null && $record->advertisement_id !== null && $record->advertisement_id !== $advertisementId) {
            $this->client->archive($record->advertisement_id);
        }
        $error = $result['error'] ?? null;
        $record->fill([
            'sku' => $item->sku,
            'advertisement_id' => $advertisementId ?? $record->advertisement_id,
            'fingerprint' => $item->fingerprint,
            'error' => $error,
            'error_details' => $error === null ? null : ($result['errorDetails'] ?? null),
            'synced_at' => $error === null ? now() : $record->synced_at,
        ])->save();
        if ($error !== null) {
            $report->fail($catalog, $item->sku, $error, $result['errorDetails'] ?? null);
        } elseif ($result['created'] ?? false) {
            $report->created++;
        } else {
            $report->updated++;
        }
    }

    private function failed(string $catalog, PendingItem $item, Collection $known, SyncReport $report, string $error, ?array $details): void
    {
        $this->record($catalog, $item, $known)->fill([
            'sku' => $item->sku,
            'error' => $error,
            'error_details' => $details ?: null,
        ])->save();
        $report->fail($catalog, $item->sku, $error, $details);
    }

    private function record(string $catalog, PendingItem $item, Collection $known): SyncedItem
    {
        $record = $known->get($item->modelKey);
        if ($record === null) {
            $record = new SyncedItem(['catalog' => $catalog, 'model_key' => $item->modelKey]);
            $known->put($item->modelKey, $record);
        }

        return $record;
    }
}
