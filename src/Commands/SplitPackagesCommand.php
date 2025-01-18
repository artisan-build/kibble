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

        $token = config('kibble.github_token');
        $username = config('kibble.github_username');

        if (strlen($token) < 40) {
            $this->error('Invalid (GH_TOKEN) in the environment variables.');

            return self::FAILURE;
        }

        foreach (File::directories(base_path('packages')) as $package) {
            $json = json_decode(File::get("{$package}/composer.json"), true);
            $this->info("Splitting package at '{$package}' into repository '{$json['name']}'");

            $repoUrl = "https://{$username}:{$token}@github.com/{$json['name']}.git";

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
