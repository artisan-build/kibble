<?php

namespace ArtisanBuild\Kibble\Commands;

use ArtisanBuild\GH\GH;
use Illuminate\Console\Command;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use function Laravel\Prompts\text;

class CreatePackageCommand extends Command
{
    protected $signature = 'kibble:create-package';

    protected $description = 'Create a new package and add it to GitHub and Packagist';

    public function handle(): int
    {
        $name = text('What are we naming this package?');

        [$headline, $slug, $pascal] = [
            Str::headline($name),
            Str::slug($name),
            Str::replace(' ', '', Str::headline($name)),
        ];

        $packages = GH::repo()->option('--json name')->list(config('kibble.organization'));
        $names = collect(json_decode($packages, true))->map(fn($package) => $package['name']);

        $description = text('What does this package do?');

        /*if ($names->contains($slug)) {
            $this->error('A package with this name already exists on GitHub');
            return self::FAILURE;
        }




        $create = GH::repo(implode('/', [config('kibble.organization'), $slug]))
            ->option('--description "' . $description . '"')
            ->option('--disable-issues')
            ->option('--disable-wiki')
            ->option('--public')
            ->option('--homepage ' . config('kibble.homepage'))
            ->option('--template ' . config('kibble.template'))
            ->create();

        $this->info($create);

        $create = 'https://github.com/artisan-build/packagist';

        $this->info("Created {$create}");

        if (! filter_var($create, FILTER_VALIDATE_URL)) {
            $this->error('We expected GitHub to return the URL of a new repo but got this instead: ' . $create);
            return self::FAILURE;
        }

        $this->call('kibble:import-package', [
            'name' => $slug,
            'path' => $slug,
            'url' => $create,
        ]);

        */


        // Import that repo into the packages directory

        // Run string replacements to rename files and set up the correct class names, etc.

        // Add to Packagist

        return self::SUCCESS;
    }
}
