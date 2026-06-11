<?php

declare(strict_types=1);

use ArtisanBuild\Kibble\Commands\SplitPackagesCommand;
use Illuminate\Support\Facades\Process;

/*
 * The credential, validation and push-wiring tests below exercise the raw passthrough path
 * (--no-clean). That path keeps the original flat command list (subtree split -> push
 * split-branch:main -> optional tag push -> branch -D), so it stays fully assertable under a
 * blanket Process::fake() without needing a real worktree on disk.
 */
describe('kibble:split lockstep tagging (raw passthrough)', function (): void {
    beforeEach(fn () => Process::fake());

    it('pushes a tag ref to the split repo for the filtered package when --tag is set', function (): void {
        $this->artisan('kibble:split', ['package' => 'packagist', '--tag' => 'v1.2.0', '--no-clean' => true])
            ->assertSuccessful();

        Process::assertRan('git push https://github.com/artisan-build/packagist.git split-branch:main --force');
        Process::assertRan('git push https://github.com/artisan-build/packagist.git split-branch:refs/tags/v1.2.0 --force');
    });

    it('does not push any tag ref when --tag is omitted', function (): void {
        $this->artisan('kibble:split', ['package' => 'packagist', '--no-clean' => true])
            ->assertSuccessful();

        Process::assertRan('git push https://github.com/artisan-build/packagist.git split-branch:main --force');
        Process::assertNotRan(fn ($process) => str_contains((string) $process->command, 'refs/tags/'));
    });

    it('tags only the filtered package, not every package', function (): void {
        $this->artisan('kibble:split', ['package' => 'packagist', '--tag' => 'v1.2.0', '--no-clean' => true])
            ->assertSuccessful();

        Process::assertNotRan(fn ($process) => str_contains((string) $process->command, 'kibble.git split-branch:refs/tags/'));
    });

    it('injects GitHub credentials into the tag push outside the local environment', function (): void {
        config(['kibble.github_username' => 'ci-bot', 'kibble.github_token' => 'secret-token']);
        app()->detectEnvironment(fn () => 'production');

        $this->artisan('kibble:split', ['package' => 'packagist', '--tag' => 'v1.2.0', '--no-clean' => true])
            ->assertSuccessful();

        Process::assertRan(fn ($process) => str_contains((string) $process->command, 'refs/tags/v1.2.0')
            && ($process->environment['GIT_USERNAME'] ?? null) === 'ci-bot'
            && ($process->environment['GIT_PASSWORD'] ?? null) === 'secret-token');
    });

    it('does not inject credentials in the local environment', function (): void {
        app()->detectEnvironment(fn () => 'local');

        $this->artisan('kibble:split', ['package' => 'packagist', '--tag' => 'v1.2.0', '--no-clean' => true])
            ->assertSuccessful();

        Process::assertRan(fn ($process) => str_contains((string) $process->command, 'refs/tags/v1.2.0')
            && $process->environment === []);
    });

    it('passes composer.json through untouched with --no-clean (no worktree rewrite)', function (): void {
        $this->artisan('kibble:split', ['package' => 'packagist', '--no-clean' => true])
            ->assertSuccessful();

        // The raw path never materializes a worktree to rewrite composer.json in.
        Process::assertNotRan(fn ($process) => str_contains((string) $process->command, 'worktree add'));
        Process::assertRan('git push https://github.com/artisan-build/packagist.git split-branch:main --force');
    });
});

describe('kibble:split tag validation', function (): void {
    beforeEach(fn () => Process::fake());

    it('rejects an empty --tag before running any process', function (): void {
        $this->artisan('kibble:split', ['package' => 'packagist', '--tag' => '   '])
            ->assertFailed();

        Process::assertNothingRan();
    });

    it('rejects a --tag containing whitespace before running any process', function (): void {
        $this->artisan('kibble:split', ['package' => 'packagist', '--tag' => 'v1 2 0'])
            ->assertFailed();

        Process::assertNothingRan();
    });

    it('rejects a --tag that looks like a flag before running any process', function (): void {
        $this->artisan('kibble:split', ['package' => 'packagist', '--tag' => '--force'])
            ->assertFailed();

        Process::assertNothingRan();
    });
});

describe('cleanComposerJson distribution transform', function (): void {
    it('drops the top-level version field', function (): void {
        $cleaned = (new SplitPackagesCommand)->cleanComposerJson([
            'name' => 'artisan-build/example',
            'version' => '1.0.0',
        ]);

        expect($cleaned)->not->toHaveKey('version')
            ->and($cleaned['name'])->toBe('artisan-build/example');
    });

    it('drops path repositories and removes the empty repositories key', function (): void {
        $cleaned = (new SplitPackagesCommand)->cleanComposerJson([
            'name' => 'artisan-build/example',
            'repositories' => [
                ['type' => 'path', 'url' => '../sibling'],
            ],
        ]);

        expect($cleaned)->not->toHaveKey('repositories');
    });

    it('preserves a non-path repository while dropping path repositories', function (): void {
        $cleaned = (new SplitPackagesCommand)->cleanComposerJson([
            'name' => 'artisan-build/example',
            'repositories' => [
                ['type' => 'path', 'url' => '../sibling'],
                ['type' => 'vcs', 'url' => 'https://github.com/artisan-build/other.git'],
            ],
        ]);

        expect($cleaned['repositories'])->toBe([
            ['type' => 'vcs', 'url' => 'https://github.com/artisan-build/other.git'],
        ]);
    });

    it('leaves a composer with no version or path repositories unchanged', function (): void {
        $composer = [
            'name' => 'artisan-build/example',
            'require' => ['php' => '^8.4'],
        ];

        expect((new SplitPackagesCommand)->cleanComposerJson($composer))->toBe($composer);
    });
});

/*
 * Integration coverage for the default (cleaning) path. We build a throwaway git monorepo
 * containing a fixture package whose composer.json carries the dev-only wiring (a top-level
 * "version" and a path repository), point the command's base_path() at it, and fake ONLY the
 * network pushes so subtree-split, worktree add, the composer rewrite and the commit all run
 * for real on disk. That lets us assert the artifact is cleaned, the tag points at the cleaned
 * commit, and the monorepo source is never touched.
 */
describe('kibble:split cleaning (default) integration', function (): void {
    beforeEach(function (): void {
        $this->repo = sys_get_temp_dir().'/kibble-clean-test-'.uniqid();
        $this->packageDir = $this->repo.'/packages/example';
        @mkdir($this->packageDir.'/src', 0777, true);

        $this->sourceComposer = [
            'name' => 'artisan-build/example',
            'version' => '1.0.0',
            'repositories' => [
                ['type' => 'path', 'url' => '../sibling', 'options' => ['symlink' => true]],
                ['type' => 'vcs', 'url' => 'https://github.com/artisan-build/keepme.git'],
            ],
            'require' => ['php' => '^8.4'],
        ];

        file_put_contents(
            $this->packageDir.'/composer.json',
            json_encode($this->sourceComposer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );
        file_put_contents($this->packageDir.'/src/Example.php', "<?php\n");

        $git = fn (string $args) => Process::path($this->repo)->run('git '.$args);
        $git('init -q');
        $git('config user.email test@example.com');
        $git('config user.name Test');
        $git('config commit.gpgsign false');
        $git('add -A');
        $git('commit -q -m initial');

        // Point the command at our throwaway monorepo and run locally (no injected creds).
        app()->detectEnvironment(fn () => 'local');
        app()->setBasePath($this->repo);
    });

    afterEach(function (): void {
        Process::run('git worktree prune');
        if (is_dir($this->repo)) {
            Process::run('rm -rf '.escapeshellarg($this->repo));
        }
    });

    it('publishes a cleaned artifact whose tag points at the stripped composer.json', function (): void {
        $pushed = [];
        $artifactComposer = null;
        // Cleaned pushes run as `git -C <worktree> push ...`, so match on the push verb anywhere.
        Process::fake([
            '* push *' => function ($process) use (&$pushed, &$artifactComposer) {
                $pushed[] = (string) $process->command;

                // The worktree still exists at push time (cleanup runs after pushes), so we can
                // read the exact composer.json that the pushed HEAD commit carries.
                $path = cleanWorktreePath().'/composer.json';
                if ($artifactComposer === null && is_file($path)) {
                    $artifactComposer = file_get_contents($path);
                }

                return Process::result('');
            },
        ]);

        $this->artisan('kibble:split', ['package' => 'example', '--tag' => 'v1.2.0'])
            ->assertSuccessful();

        // The monorepo source composer.json on disk is unchanged (artifact-only strip).
        $source = json_decode(file_get_contents($this->packageDir.'/composer.json'), true);
        expect($source)->toBe($this->sourceComposer);

        // Both main and the tag ref were pushed from the cleaned worktree HEAD.
        expect($pushed)->toContain('git -C '.cleanWorktreePath().' push https://github.com/artisan-build/example.git HEAD:main --force');
        expect($pushed)->toContain('git -C '.cleanWorktreePath().' push https://github.com/artisan-build/example.git HEAD:refs/tags/v1.2.0 --force');

        // The pushed (tagged) HEAD commit carries the cleaned composer.json: no version, no path
        // repository, the non-path repository preserved, and a trailing newline on pretty JSON.
        expect($artifactComposer)->not->toBeNull()
            ->and($artifactComposer)->toEndWith("}\n");

        $cleaned = json_decode((string) $artifactComposer, true);
        expect($cleaned)->not->toHaveKey('version')
            ->and($cleaned['repositories'])->toBe([
                ['type' => 'vcs', 'url' => 'https://github.com/artisan-build/keepme.git'],
            ])
            ->and($cleaned['require'])->toBe(['php' => '^8.4']);
    });
});

function cleanWorktreePath(): string
{
    return rtrim(sys_get_temp_dir(), '/').'/kibble-split-artisan-build-example';
}
