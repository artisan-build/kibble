<?php

namespace ArtisanBuild\Kibble\Providers;

use ArtisanBuild\Kibble\Commands\CreatePackageCommand;
use ArtisanBuild\Kibble\Commands\ImportPackageCommand;
use ArtisanBuild\Kibble\Commands\LinkCommand;
use ArtisanBuild\Kibble\Commands\SplitPackagesCommand;
use ArtisanBuild\Kibble\Commands\UnlinkCommand;
use Illuminate\Support\ServiceProvider;

class KibbleServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/kibble.php', 'kibble');
    }

    public function boot(): void
    {
        $this->commands([
            CreatePackageCommand::class,
            ImportPackageCommand::class,
            SplitPackagesCommand::class,
            LinkCommand::class,
            UnlinkCommand::class,
        ]);

        $this->publishes([
            __DIR__.'/../../config/kibble.php' => config_path('kibble.php'),
        ], 'kibble');
    }
}
