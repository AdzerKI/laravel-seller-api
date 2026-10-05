<?php

namespace Opotopo\SellerApi;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use Opotopo\SellerApi\Catalog\ItemBuilder;
use Opotopo\SellerApi\Console\SyncCommand;
use Opotopo\SellerApi\Files\FileRegistry;
use Opotopo\SellerApi\Http\SellerApiClient;
use Opotopo\SellerApi\Sync\SyncRunner;

class SellerApiServiceProvider extends ServiceProvider
{
    private const CONFIG = 'opotopo';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/opotopo.php', self::CONFIG);
        $this->app->singleton(SellerApiClient::class, fn (Application $app) => new SellerApiClient(
            $app->make(Factory::class),
            $app['config'][self::CONFIG],
        ));
        $this->app->singleton(SyncRunner::class, fn (Application $app) => new SyncRunner(
            $app->make(SellerApiClient::class),
            new ItemBuilder($app->make(FilesystemManager::class)),
            new FileRegistry($app->make(SellerApiClient::class)),
            $app['config'][self::CONFIG],
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        if (! $this->app->runningInConsole()) {
            return;
        }
        $this->publishes([__DIR__.'/../config/opotopo.php' => config_path('opotopo.php')], 'opotopo-config');
        $this->commands([SyncCommand::class]);
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $cron = config(self::CONFIG.'.schedule');
            if ($cron) {
                $schedule->command(SyncCommand::class)->cron($cron)->withoutOverlapping();
            }
        });
    }
}
