<?php

namespace SolutionForest\Boop\Tests;

use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            \SolutionForest\Boop\BoopServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('boop.url', 'https://boop.test');
        $app['config']->set('boop.api_key', 'boop_proj_test');
        $app['config']->set('boop.enabled', true);
        $app['config']->set('boop.retry_delay', 0);
    }
}
