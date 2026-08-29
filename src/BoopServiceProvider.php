<?php

namespace SolutionForest\Boop;

use Illuminate\Support\ServiceProvider;

class BoopServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/boop.php', 'boop');

        $this->app->singleton(Boop::class, function ($app) {
            return new Boop($app['config']['boop'] ?? []);
        });

        $this->app->alias(Boop::class, 'boop');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/boop.php' => config_path('boop.php'),
            ], 'boop-config');
        }
    }
}
