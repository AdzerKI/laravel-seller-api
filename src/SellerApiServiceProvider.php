<?php

namespace Opotopo\SellerApi\Laravel;

use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Opotopo\SellerApi\ClientConfig;
use Opotopo\SellerApi\Files\FileUploader;
use Opotopo\SellerApi\Laravel\Console\SyncCommand;
use Opotopo\SellerApi\Laravel\Files\EloquentFileStore;
use Opotopo\SellerApi\Laravel\Http\CacheDeliveryStore;
use Opotopo\SellerApi\Laravel\Http\LaravelHttpClient;
use Opotopo\SellerApi\Laravel\Http\WebhookController;
use Opotopo\SellerApi\Laravel\Sync\EloquentItemStore;
use Opotopo\SellerApi\SellerApiClient;
use Opotopo\SellerApi\Sync\Synchronizer;
use Opotopo\SellerApi\Webhooks\WebhookReceiver;

class SellerApiServiceProvider extends ServiceProvider
{
    public const WEBHOOK_ROUTE = 'opotopo.webhooks';

    private const CONFIG = 'opotopo';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/opotopo.php', self::CONFIG);
        $this->app->singleton(SellerApiClient::class, function (Application $app) {
            $config = $app['config'][self::CONFIG];
            $factory = new HttpFactory;

            return new SellerApiClient(
                new LaravelHttpClient($app->make(Factory::class), (int) ($config['timeout'] ?? 0)),
                $factory,
                $factory,
                new ClientConfig(
                    (string) $config['url'],
                    (string) $config['key'],
                    $config['organization_id'] ?? null,
                    (bool) ($config['publish'] ?? true),
                    (int) ($config['retry']['times'] ?? 1),
                    (int) ($config['retry']['sleep_ms'] ?? 0),
                ),
            );
        });
        $this->app->singleton(Synchronizer::class, function (Application $app) {
            $config = $app['config'][self::CONFIG];

            return new Synchronizer(
                $app->make(SellerApiClient::class),
                new FileUploader($app->make(SellerApiClient::class), new EloquentFileStore, new HttpFactory),
                new EloquentItemStore,
                (int) $config['batch'],
            );
        });
        $this->app->bind(WebhookController::class, function (Application $app) {
            $webhooks = $app['config'][self::CONFIG]['webhooks'];

            return new WebhookController(
                new WebhookReceiver(
                    (string) $webhooks['secret'],
                    new CacheDeliveryStore($app->make(Repository::class), (int) $webhooks['dedupe_ttl']),
                ),
                $app->make(Dispatcher::class),
            );
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $webhooks = config(self::CONFIG.'.webhooks');
        if ($webhooks['secret'] && ! $this->app->routesAreCached()) {
            $this->app->make(Router::class)->post($webhooks['path'], WebhookController::class)->name(self::WEBHOOK_ROUTE);
        }
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
