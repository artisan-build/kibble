<?php

namespace Commands;

use Illuminate\Console\Command;

use function Laravel\Prompts\text;

class CreatePackageCommand extends Command
{
    protected $signature = 'kibble:create-package';

    protected $description = 'Create a new package and add it to GitHub and Packagist';

    public function handle(): int
    {
        $name = text('What are we naming this package?');
        $description = text('What does this package do?');

        // Use GH to create a new repo from the template

        // Import that repo into the packages directory

        // Run string replacements to rename files and set up the correct class names, etc.

        return self::SUCCESS;
    }
}
