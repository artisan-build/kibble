<?php

declare(strict_types=1);

namespace ArtisanBuild\Kibble\Commands;

use ArtisanBuild\Kibble\Actions\KibbleGitIgnore;
use Illuminate\Console\Command;

class LinkCommand extends Command
{
    protected $signature = 'kibble:link {repository}';

    protected $description = 'Add a Kibble monorepo to your composer project';

    public function handle(): int
    {
        app(KibbleGitIgnore::class)();

        if (! file_exists('kibble.json')) {
            file_put_contents(
                filename: 'kibble.json',
                data: json_encode(
                    value: [],
                    flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ).PHP_EOL
            );
        }

        $kibble = json_decode(file_get_contents('kibble.json'), true);

        $repository = $this->argument('repository');

        if (! isset($kibble[$repository])) {
            if (! $this->confirm(
                question: "{$repository} repository not found in kibble.json. Would you like to add it?",
                default: true,
            )) {
                return Command::FAILURE;
            }

            $path = $this->ask('Enter the path to the repository (without trailing slash)');

            $path = str_ends_with((string) $path, '/*') ? $path : $path.'/*';

            // "dogfood": {
            //     "type": "path",
            //     "url": "/Users/gopher/Code/artisan-build/dogfood/packages/*",
            //     "options": {
            //         "symlink": true
            //     }
            // },
            $kibble[$repository] = [
                'type' => 'path',
                'url' => $path,
                'options' => [
                    'symlink' => true,
                ],
            ];

            file_put_contents(
                filename: 'kibble.json',
                data: json_encode(
                    value: $kibble,
                    flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ).PHP_EOL
            );
        }

        /**
         * Now start work on the composer.json file.
         */
        $composer_json = file_get_contents('composer.json');
        $trailing_newline = str_ends_with($composer_json, "\n");

        $composer = json_decode($composer_json, true);

        if (! isset($composer['repositories'])) {
            $composer['repositories'] = [];
        }

        if (
            isset($composer['repositories'][$repository]) &&
            $composer['repositories'][$repository] === $kibble[$repository]
        ) {
            $this->info("{$repository} already in composer.json");

            return Command::SUCCESS;
        }

        if (
            isset($composer['repositories'][$repository]) &&
            $composer['repositories'][$repository] !== $kibble[$repository]
        ) {
            $this->error("{$repository} already in composer.json but with different configuration.");

            return Command::FAILURE;
        }

        $composer['repositories'][$repository] = $kibble[$repository];

        file_put_contents(
            filename: 'composer.json',
            data: json_encode(
                value: $composer,
                flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ).($trailing_newline ? "\n" : '')
        );

        $this->info("{$repository} added to composer.json");

        return Command::SUCCESS;
    }
}
