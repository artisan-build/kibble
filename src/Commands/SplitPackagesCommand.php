<?php

declare(strict_types=1);

namespace ArtisanBuild\Kibble\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class SplitPackagesCommand extends Command
{
    protected $signature = 'kibble:split {package? : The package name to split (e.g., adverbs, kibble). If omitted, all packages will be split.}
        {--tag= : Also tag each split repository with this version (e.g. v1.2.0). Enables lockstep releases.}
        {--no-clean : Publish each package'."'".'s composer.json verbatim instead of stripping dev-only wiring (top-level "version" and "path" repositories).}';

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

        $clean = ! $this->option('no-clean');

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
            $prefix = 'packages/'.last(explode('/', (string) $package));

            $result = $clean
                ? $this->splitAndClean($repoUrl, $prefix, (string) $json['name'], $tag)
                : $this->splitRaw($repoUrl, $prefix, $tag);

            if ($result !== self::SUCCESS) {
                return $result;
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

    /**
     * Produce the distribution composer.json for a split artifact.
     *
     * Dev-only wiring used to make local `^1.x` resolution work inside the monorepo is
     * load-bearing there but wrong in the published package, so it is stripped from the
     * split (never from the monorepo source on disk):
     *
     *   1. The top-level `version` field is removed so Packagist derives the version from
     *      the git tag instead of a hardcoded value.
     *   2. `repositories` entries of type `path` are removed (their `../sibling` urls do not
     *      exist for consumers). Non-path repositories are preserved, and the `repositories`
     *      key is dropped entirely when nothing is left.
     *
     * @param  array<string, mixed>  $composer
     * @return array<string, mixed>
     */
    public function cleanComposerJson(array $composer): array
    {
        unset($composer['version']);

        if (isset($composer['repositories']) && is_array($composer['repositories'])) {
            $repositories = array_values(array_filter(
                $composer['repositories'],
                fn ($repository): bool => ! (is_array($repository) && ($repository['type'] ?? null) === 'path'),
            ));

            if ($repositories === []) {
                unset($composer['repositories']);
            } else {
                $composer['repositories'] = $repositories;
            }
        }

        return $composer;
    }

    /**
     * Force-sync the split repo (and optional tag) straight from the subtree-split branch,
     * publishing each package's composer.json verbatim. Used by --no-clean.
     */
    private function splitRaw(string $repoUrl, string $prefix, ?string $tag): int
    {
        $commands = [
            ['git', 'subtree', 'split', '--prefix='.$prefix, '-b', 'split-branch'],
            ['git', 'push', $repoUrl, 'split-branch:main', '--force'],
        ];

        if ($tag !== null) {
            $commands[] = ['git', 'push', $repoUrl, 'split-branch:refs/tags/'.$tag, '--force'];
        }

        $commands[] = ['git', 'branch', '-D', 'split-branch'];

        return $this->runCommands($commands);
    }

    /**
     * Materialize the split content in a detached worktree, rewrite its composer.json into the
     * distribution form, commit, then push that cleaned commit to main and (optionally) the tag.
     *
     * The rewrite happens after `git subtree split` and before any push so that both the main
     * branch and the tag ref point at the cleaned commit. Using a worktree keeps the monorepo
     * working tree (and the package's own composer.json on disk) untouched.
     */
    private function splitAndClean(string $repoUrl, string $prefix, string $name, ?string $tag): int
    {
        $worktree = $this->worktreePath($name);

        $setup = $this->runCommands([
            ['git', 'subtree', 'split', '--prefix='.$prefix, '-b', 'split-branch'],
            ['git', 'worktree', 'add', '--detach', $worktree, 'split-branch'],
        ]);

        if ($setup !== self::SUCCESS) {
            $this->cleanupWorktree($worktree);

            return self::FAILURE;
        }

        if (! $this->rewriteWorktreeComposer($worktree)) {
            $this->cleanupWorktree($worktree);

            return self::FAILURE;
        }

        $commit = $this->runCommands([
            ['git', '-C', $worktree, 'add', 'composer.json'],
            ['git', '-C', $worktree, 'commit', '--allow-empty', '-m', "'Prepare ".$name." for distribution'"],
        ]);

        if ($commit !== self::SUCCESS) {
            $this->cleanupWorktree($worktree);

            return self::FAILURE;
        }

        $pushes = [
            ['git', '-C', $worktree, 'push', $repoUrl, 'HEAD:main', '--force'],
        ];

        if ($tag !== null) {
            $pushes[] = ['git', '-C', $worktree, 'push', $repoUrl, 'HEAD:refs/tags/'.$tag, '--force'];
        }

        $pushed = $this->runCommands($pushes);

        $this->cleanupWorktree($worktree);

        return $pushed;
    }

    /**
     * Rewrite the split worktree's composer.json into its distribution form. Reads, transforms
     * via cleanComposerJson(), and writes pretty JSON with a trailing newline. Only the artifact
     * in the worktree is touched; the monorepo source composer.json is never modified.
     */
    private function rewriteWorktreeComposer(string $worktree): bool
    {
        $path = $worktree.'/composer.json';

        $composer = json_decode(File::get($path), true);

        if (! is_array($composer)) {
            $this->error("Could not parse composer.json for the split at '{$worktree}'");

            return false;
        }

        $cleaned = $this->cleanComposerJson($composer);

        File::put(
            $path,
            json_encode($cleaned, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL,
        );

        return true;
    }

    /**
     * Run a list of git commands in order, applying the same credential strategy used for pushes.
     * Stops and reports on the first failure.
     *
     * @param  array<int, array<int, string>>  $commands
     */
    private function runCommands(array $commands): int
    {
        foreach ($commands as $command) {
            // We want to rely on our local git credentials if running locally.
            $process = Process::path(base_path())->env($this->pushEnvironment())
                ->run(implode(' ', $command));

            if (! $process->successful()) {
                $this->error($process->errorOutput());

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /**
     * Credential environment for git operations: local checkouts use the ambient credential
     * helper (empty env); elsewhere we inject the configured GitHub username/token.
     *
     * @return array<string, string|null>
     */
    private function pushEnvironment(): array
    {
        return app()->isLocal() ? [] : [
            'GIT_ASKPASS' => 'echo',
            'GIT_USERNAME' => config('kibble.github_username'),
            'GIT_PASSWORD' => config('kibble.github_token'),
        ];
    }

    /**
     * Best-effort teardown of the temporary worktree and the split branch. Failures here are
     * ignored so a cleanup attempt never masks the real result of the split.
     */
    private function cleanupWorktree(string $worktree): void
    {
        Process::path(base_path())->env($this->pushEnvironment())
            ->run('git worktree remove --force '.$worktree);
        Process::path(base_path())->env($this->pushEnvironment())
            ->run('git branch -D split-branch');
    }

    private function worktreePath(string $name): string
    {
        return rtrim(sys_get_temp_dir(), '/').'/kibble-split-'.str_replace('/', '-', $name);
    }
}
