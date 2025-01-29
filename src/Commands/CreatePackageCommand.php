<?php

namespace ArtisanBuild\Kibble\Commands;

use ArtisanBuild\GH\GH;
use ArtisanBuild\Kibble\Package;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use ZipArchive;

use function Laravel\Prompts\text;

class CreatePackageCommand extends Command
{
    protected $signature = 'kibble:create-package';

    protected $description = 'Create a new package and add it to GitHub and Packagist';

    /**
     * @throws FatalRequestException
     * @throws RequestException
     * @throws JsonException
     */
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
            ->option('--description "'.$description.'"')
            ->option('--disable-issues')
            ->option('--disable-wiki')
            ->option('--public')
            ->option('--homepage '.config('kibble.homepage'))
            ->option('--template '.config('kibble.template'))
            ->create();

        $remote_zip = 'https://github.com/'.config('kibble.organization')."/{$slug}/archive/refs/heads/main.zip";

        Process::path(base_path('packages'))->run("wget {$remote_zip}");

        $local_zip = base_path('packages/main.zip');

        $zip = new ZipArchive;

        if ($zip->open($local_zip) === true) {
            $zip->extractTo(base_path('packages'));
            $zip->close();
        } else {
            $this->error('Failed to extract the package zip file.');
            File::delete($local_zip);

            return self::FAILURE;
        }

        $original = base_path("packages/{$slug}-main");
        $final = base_path("packages/{$slug}");

        File::moveDirectory($original, $final);

        File::delete($local_zip);

        $this->info("Package {$slug} pulled down and cleaned successfully.");

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

        $this->info("Attempting to add {$slug} to Packagist");

        $package = Package::fromDirectory(base_path("packages/{$slug}"));

        if (! is_null($package->packagist())) {
            $this->info("{$slug} appears to already be in Packagist");
        }

        if ($package->addToPackagist()) {
            $this->info('Added to Packagist');
        } else {
            $this->error('Failed to add to Packagist');
        }

        return self::SUCCESS;
    }
}
