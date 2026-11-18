<?php

declare(strict_types=1);

namespace ArtisanBuild\Kibble\Tests;

use ArtisanBuild\Kibble\Providers\KibbleServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [KibbleServiceProvider::class];
    }
}
