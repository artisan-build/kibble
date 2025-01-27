<?php

namespace ArtisanBuild\Kibble\Commands;

use ArtisanBuild\GH\GH;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
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
        $names = collect(json_decode((string) $packages, true))->map(fn ($package) => $package['name']);

        $description = text('What does this package do?');

        if ($names->contains($slug)) {
            $this->error('A package with this name already exists on GitHub');

            return self::FAILURE;
        }

        $create = GH::repo(implode('/', [config('kibble.organization'), $slug]))
            ->path(base_path('packages'))
            ->option('--description "'.$description.'"')
            ->option('--disable-issues')
            ->option('--disable-wiki')
            ->option('--public')
            ->option('--homepage '.config('kibble.homepage'))
            ->option('--template '.config('kibble.template'))
            ->option('--clone')
            ->create();

        $this->info($create);

        $this->info("Created {$create}");

        // Run string replacements to rename files and set up the correct class names, etc.
        $readme = File::get(base_path("packages/{$slug}/README.md"));
        File::put(base_path("packages/{$slug}/README.md"), Str::replace(
            ['SkeletonDescription', 'Skeleton', 'skeleton'],
            [$description, $headline, $slug],
            $readme
        ));

        $contributing = File::get(base_path("packages/{$slug}/CONTRIBUTING.md"));
        File::put(base_path("packages/{$slug}/CONTRIBUTING.md"), Str::replace(
            ['SkeletonDescription', 'Skeleton', 'skeleton'],
            [$description, $headline, $slug],
            $contributing
        ));

        $composer = File::get(base_path("packages/{$slug}/composer.json"));
        File::put(base_path("packages/{$slug}/composer.json"), Str::replace(
            ['SkeletonDescription', 'Skeleton', 'skeleton'],
            [$description, $pascal, $slug],
            $composer
        ));

        $composer = File::get(base_path("packages/{$slug}/src/Providers/SkeletonServiceProvider.php"));
        File::put(base_path("packages/{$slug}/src/Providers/SkeletonServiceProvider.php"), Str::replace(
            ['SkeletonDescription', 'Skeleton', 'skeleton'],
            [$description, $pascal, $slug],
            $composer
        ));

        File::move("packages/{$slug}/src/Providers/SkeletonServiceProvider.php", "packages/{$slug}/src/Providers/{$pascal}ServiceProvider.php");

        File::move("packages/{$slug}/config/skeleton.php", "packages/{$slug}/config/{$slug}.php");

        $ungit = Process::path(base_path("packages/{$slug}"))->run('rm -rf .git');

        $this->info(Process::run('composer require '.config('kibble.organization')."/{$slug}:*")->output());

        return self::SUCCESS;
    }
}
