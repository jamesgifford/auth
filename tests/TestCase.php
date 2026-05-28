<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests;

use JamesGifford\Auth\AuthServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            AuthServiceProvider::class,
        ];
    }
}
