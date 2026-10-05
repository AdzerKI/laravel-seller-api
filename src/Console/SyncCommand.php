<?php

namespace Opotopo\SellerApi\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Opotopo\SellerApi\Sync\SyncRunner;

class SyncCommand extends Command
{
    protected $signature = 'opotopo:sync
        {--catalog= : Каталог конфигурации; без ключа — все каталоги}
        {--full : Отправить все позиции, включая неизменные}';

    protected $description = 'Синхронизация каталога сайта с площадкой ОПОТОПО';

    public function handle(SyncRunner $runner): int
    {
        $catalog = $this->option('catalog');
        try {
            $report = $runner->run($catalog === null ? null : (string) $catalog, (bool) $this->option('full'));
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

        return self::SUCCESS;
    }
}
