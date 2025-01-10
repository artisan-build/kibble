<?php

namespace ArtisanBuild\Kibble\Providers;

use Illuminate\Support\ServiceProvider;

class KibbleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/kibble.php', 'kibble');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../config/kibble.php' => config_path('kibble.php'),
        ], 'kibble');
    }
}
