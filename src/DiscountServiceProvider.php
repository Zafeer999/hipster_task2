<?php

namespace Zafeer\Discounts;

use Illuminate\Support\ServiceProvider;
use Zafeer\Discounts\Services\DiscountManager;
use Zafeer\Discounts\Services\DiscountService;

class DiscountServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../config/discounts.php' => config_path('discounts.php'),
        ], 'config');

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/discounts.php', 'discounts');

        $this->app->singleton(DiscountManager::class, function ($app) {
            $connection = $app['db']->connection();
            return new DiscountManager(
                $connection,
                $app['events'],
                $app['config']['discounts'] ?? []
            );
        });

        $this->app->singleton(DiscountService::class, function ($app) {
            return new DiscountService($app->make(DiscountManager::class));
        });

        $this->app->singleton('zafeer.discounts', function ($app) {
            return $app->make(DiscountManager::class);
        });
    }
}
