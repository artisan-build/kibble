<?php

namespace ArtisanBuild\Kibble\Actions;

use ArtisanBuild\Kibble\Package;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\Facades\File;

class GetPackages
{
    public function __invoke(): Enumerable|Collection
    {
        return collect(File::directories(config('kibble.path')))
            ->map(fn ($directory) => Package::fromDirectory($directory));
    }
}
