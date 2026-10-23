<?php

namespace Opotopo\SellerApi\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemManager;
use InvalidArgumentException;
use Opotopo\SellerApi\Laravel\Catalog\EloquentCatalog;
use Opotopo\SellerApi\Sync\Synchronizer;

class SyncCommand extends Command
{
    protected $signature = 'opotopo:sync
        {--catalog= : Каталог конфигурации; без ключа — все каталоги}
        {--full : Отправить все позиции, включая неизменные}';

    protected $description = 'Синхронизация каталога сайта с площадкой ОПОТОПО';

    public function handle(Synchronizer $synchronizer, FilesystemManager $filesystems): int
    {
        $catalog = $this->option('catalog');
        $catalogs = array_map(
            fn (array $definition) => new EloquentCatalog($definition, $filesystems),
            config('opotopo.catalogs'),
        );
        try {
            $report = $synchronizer->run($catalogs, $catalog === null ? null : (string) $catalog, (bool) $this->option('full'));
        } catch (InvalidArgumentException) {
            $this->error('Каталога «'.$catalog.'» нет в config/opotopo.php');

            return self::INVALID;
        }
        $this->info(sprintf(
            'Заведено: %d, обновлено: %d, без изменений: %d, снято: %d, отказов: %d',
            $report->created,
            $report->updated,
            $report->skipped,
            $report->archived,
            count($report->failures),
        ));
        if ($report->failures !== []) {
            $this->table(
                ['Каталог', 'Артикул', 'Отказ', 'Детали'],
                array_map(fn (array $failure) => [
                    $failure['catalog'],
                    $failure['sku'],
                    $failure['error'],
                    json_encode($failure['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ], $report->failures),
            );
        }
        if ($report->gitSources !== []) {
            $this->table(
                ['Каталог', 'Артикул', 'Репозиторий', 'Ключ развёртывания', 'Webhook', 'Отказы забора версий'],
                array_map(fn (array $gitSource) => [
                    $gitSource['catalog'],
                    $gitSource['sku'],
                    $gitSource['source']['repositoryUrl'] ?? '',
                    $gitSource['source']['deployPublicKey'] ?? '',
                    $gitSource['source']['webhookUrl'] ?? '',
                    self::fetchFailures($gitSource['source']),
                ], $report->gitSources),
            );
        }

        return self::SUCCESS;
    }

    private static function fetchFailures(array $source): string
    {
        $failures = array_filter([
            'failure' => $source['failure'] ?? null,
            'tagFailures' => $source['tagFailures'] ?? [],
        ]);

        return $failures === [] ? '' : json_encode($failures, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
