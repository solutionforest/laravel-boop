<?php

namespace SolutionForest\Boop\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \SolutionForest\Boop\Result send(string|array $event, array $overrides = [])
 * @method static void sendAsync(string|array $event, array $overrides = [])
 * @method static bool healthy(array $overrides = [])
 * @method static bool enabled(array $overrides = [])
 * @method static mixed config(string $key, mixed $default = null, array $overrides = [])
 *
 * @see \SolutionForest\Boop\Boop
 */
class Boop extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \SolutionForest\Boop\Boop::class;
    }
}
