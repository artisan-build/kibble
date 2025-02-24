<?php

declare(strict_types=1);

namespace ArtisanBuild\Kibble\Commands;

use ArtisanBuild\Kibble\Actions\KibbleGitIgnore;
use Illuminate\Console\Command;

class LinkFluxCommand extends Command
{
    protected $signature = 'kibble:link-flux';

    protected $description = 'Link the Flux Pro repo';

    public function handle(): int
    {
        $composer_json = file_get_contents('composer.json');
        $trailing_newline = str_ends_with($composer_json, "\n");

        $composer = json_decode($composer_json, true);

        if (! isset($composer['repositories'])) {
            $composer['repositories'] = [];
        }

        if (
            isset($composer['repositories']['flux-pro'])
        ) {
            $this->info("Flux Pro already in composer.json");

            return Command::SUCCESS;
        }


        $composer['repositories']['flux-pro'] = [
            'type' => 'composer',
            'url' => 'https://composer.fluxui.dev',
        ];

        file_put_contents(
            filename: 'composer.json',
            data: json_encode(
                value: $composer,
                flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ).($trailing_newline ? "\n" : '')
        );

        $this->info("Flux Pro added to composer.json");

        return Command::SUCCESS;
    }
}
