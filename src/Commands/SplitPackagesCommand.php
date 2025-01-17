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
        $packages = [];

        foreach (File::directories(base_path('packages')) as $package) {
            $json = json_decode(File::get("{$package}/composer.json"), true);
            $this->info("Splitting package at '{$package}' into repository '{$json['name']}'");

            $commands = [
                ['git', 'subtree', 'split', '--prefix=packages/'.last(explode('/', $package)), '-b', 'split-branch'],
                ['git', 'push', 'https://github.com/'.$json['name'], 'split-branch:main', '--force'],
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
