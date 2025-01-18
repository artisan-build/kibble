<?php

namespace ArtisanBuild\Kibble\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class SplitPackagesCommand extends Command
{
    protected $signature = 'kibble:split';

    protected $description = 'Update all of the individual repositories for the packages';

    public function handle(): int
    {
        foreach (File::directories(base_path('packages')) as $package) {
            $json = json_decode(File::get("{$package}/composer.json"), true);

            if (! isset($json['name'])) {
                $this->error("Could not find the 'name' field in the composer.json of '{$package}'");

                continue;
            }

            $this->info("Splitting package at '{$package}' into repository '{$json['name']}'");

            // Repository URL (no token needed when using actions/checkout)
            $repoUrl = "https://github.com/{$json['name']}.git";

            // Ensure clean git configuration
            Process::run("git config -l | grep 'http\\..*\\.extraheader' | cut -d= -f1 | xargs -L1 git config --unset-all");

            // Define the commands
            $commands = [
                ['git', 'subtree', 'split', '--prefix=packages/'.last(explode('/', $package)), '-b', 'split-branch'],
                ['git', 'push', $repoUrl, 'split-branch:main', '--force'],
                ['git', 'branch', '-D', 'split-branch'],
            ];

            foreach ($commands as $command) {
                $process = Process::run(implode(' ', $command));

                if (! $process->successful()) {
                    $this->error($process->errorOutput());

                    return self::FAILURE;
                }
            }

            $this->info("Done updating '{$json['name']}'");
        }

        return self::SUCCESS;
    }
}
