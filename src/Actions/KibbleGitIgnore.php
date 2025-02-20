<?php

declare(strict_types=1);

namespace ArtisanBuild\Kibble\Actions;

// TODO: Don't want to add this dependency just for this one attribute.
// use ArtisanBuild\Bench\Attributes\ChatGPT;

class KibbleGitIgnore
{
    // #[ChatGPT]
    public function __invoke(): void
    {
        if (! shell_exec('git check-ignore kibble.json')) {
            $gitignore = file_get_contents('.gitignore');
            $lines = explode("\n", $gitignore);
            $kibbleIndex = -1;

            // Find appropriate spot to insert kibble.json alphabetically
            for ($i = 0; $i < count($lines); $i++) {
                if (trim($lines[$i]) === '') {
                    continue;
                }
                if (strcasecmp('kibble.json', $lines[$i]) > 0) {
                    continue;
                }
                $kibbleIndex = $i;
                break;
            }

            if ($kibbleIndex === -1) {
                $lines[] = 'kibble.json';
            } else {
                array_splice($lines, $kibbleIndex, 0, 'kibble.json');
            }

            file_put_contents('.gitignore', implode("\n", $lines));
        }
    }
}
