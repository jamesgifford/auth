<?php

declare(strict_types=1);

namespace Progravity\Auth\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Progravity\Auth\AuthServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            AuthServiceProvider::class,
        ];
    }
}
