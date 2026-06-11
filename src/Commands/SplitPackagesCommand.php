<?php

declare(strict_types=1);

namespace ArtisanBuild\Kibble\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class SplitPackagesCommand extends Command
{
    protected $signature = 'kibble:split {package? : The package name to split (e.g., adverbs, kibble). If omitted, all packages will be split.}
        {--tag= : Also tag each split repository with this version (e.g. v1.2.0). Enables lockstep releases.}';

    protected $description = 'Update all of the individual repositories for the packages';

    public function handle(): int
    {
        $packageFilter = $this->argument('package');

        $tag = $this->option('tag');
        if ($tag !== null) {
            $tag = trim((string) $tag);

            // Fail fast on an empty or obviously invalid ref before we touch any repo.
            if ($tag === '' || str_starts_with($tag, '-') || preg_match('/\s/', $tag) === 1) {
                $this->error("Invalid --tag value: '{$this->option('tag')}'. Provide a git ref such as v1.2.0.");

                return self::FAILURE;
            }
        }

        $packagesProcessed = 0;

        foreach (File::directories(base_path('packages')) as $package) {
            $json = json_decode(File::get("{$package}/composer.json"), true);

            if (! isset($json['name'])) {
                $this->error("Could not find the 'name' field in the composer.json of '{$package}'");

                continue;
            }

            // If a package filter is provided, skip packages that don't match
            if ($packageFilter !== null) {
                $packageName = last(explode('/', (string) $json['name']));
                if ($packageName !== $packageFilter) {
                    continue;
                }
            }

            $packagesProcessed++;

            $this->info("Splitting package at '{$package}' into repository '{$json['name']}'");

            $repoUrl = "https://github.com/{$json['name']}.git";

            // Define commands to execute
            $commands = [
                ['git', 'subtree', 'split', '--prefix=packages/'.last(explode('/', (string) $package)), '-b', 'split-branch'],
                ['git', 'push', $repoUrl, 'split-branch:main', '--force'],
            ];

            if ($tag !== null) {
                // Push the freshly-split commit straight to a tag ref on the split repo. Using a
                // ref-spec push means no local tag is created in this checkout, so it can't collide
                // with the monorepo's own release tag that triggered the split. --force makes a
                // re-run of a failed/partial release idempotent (re-points the tag at the new
                // subtree-split SHA instead of erroring "tag already exists").
                $commands[] = ['git', 'push', $repoUrl, 'split-branch:refs/tags/'.$tag, '--force'];
            }

            $commands[] = ['git', 'branch', '-D', 'split-branch'];

            foreach ($commands as $command) {
                // We want to rely on our local git credentials if running locally.
                $process = Process::env(app()->isLocal() ? [] : [
                    'GIT_ASKPASS' => 'echo',
                    'GIT_USERNAME' => config('kibble.github_username'),
                    'GIT_PASSWORD' => config('kibble.github_token'),
                ])->run(implode(' ', $command));

                if (! $process->successful()) {
                    $this->error($process->errorOutput());

                    return self::FAILURE;
                }
            }

            $this->info("Done updating '{$json['name']}'");
        }

        if ($packageFilter !== null && $packagesProcessed === 0) {
            $this->error("No package found matching '{$packageFilter}'");

            return self::FAILURE;
        }

        if ($packagesProcessed === 0) {
            $this->warn('No packages were processed');

            return self::SUCCESS;
        }

        $this->info("Successfully processed {$packagesProcessed} package(s)");

        return self::SUCCESS;
    }
}
