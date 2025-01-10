<?php

namespace ArtisanBuild\Kibble\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\text;

class ImportPackageCommand extends Command
{
    protected $signature = 'kibble:import-package {name?} {url?} {path?}';

    protected $description = 'Import a repository into the monorepo with its history';

    public function handle()
    {

        $process = Process::run(implode(' ', ['git', 'status', '--porcelain']));


        if (!$process->successful()) {
            $this->error('Failed to check Git status.');
            return self::FAILURE;
        }

        if (!empty($process->output())) {
            $this->error('Git repository is not clean. Please commit or stash changes.');
            return self::FAILURE;
        }

        $url = $this->argument('url') ?? text('Enter the repository URL');
        $name = $this->argument('name') ??text('Enter the package name (used as directory)');
        $path = $this->argument('path') ?? text('Enter the package path (leave blank to use the name)', default: $name);

        $this->info("Cloning $url into packages/$path...");

        File::ensureDirectoryExists(base_path('packages'));

        chdir(base_path());

        $emptyTree = trim(Process::run('git hash-object -t tree /dev/null')->output());

        Process::run("git remote add $name $url");
        Process::run("git config --add remote.$name.fetch '+refs/tags/*:refs/tags/$name/*'");
        Process::run("git config remote.$name.tagOpt --no-tags");
        Process::run("git fetch --atomic $name");

        $branches = explode("\n", trim(Process::run("git branch -r --no-color --list '$name/*' --format '%(refname:lstrip=3)'")->output()));

        foreach ($branches as $branch) {
            if (empty($branch)) {
                continue;
            }

            // Create an empty tree
            Process::run("git read-tree $emptyTree");
            Process::run("git read-tree --prefix=packages/$path refs/remotes/$name/$branch");
            $tree = trim(Process::run('git write-tree')->output());

            $moveCommit = trim(Process::run("git commit-tree $tree -p refs/remotes/$name/$branch -m 'Move all files to $path/'")->output());

            $branchExists = Process::run("git show-ref --verify --quiet refs/heads/$branch")->successful();

            if (! $branchExists) {
                $rootCommit = trim(Process::run("git commit-tree $emptyTree -m 'Root commit for monorepo branch $branch'")->output());
                Process::run("git branch -- $branch $rootCommit");
            }

            Process::run("git symbolic-ref HEAD refs/heads/$branch");
            Process::run('git reset -q');

            Process::run("git read-tree --prefix=packages/$path refs/remotes/$name/$branch");
            $tree = trim(Process::run('git write-tree')->output());

            $mergeCommit = trim(Process::run("git commit-tree $tree -p $branch -p $moveCommit -m 'Merge $name/$branch'")->output());
            Process::run("git reset -q $mergeCommit");
        }

        Process::run('git checkout .');

        $this->info("Repository $name imported into packages/$path.");
    }
}
