<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

beforeEach(fn () => Process::fake());

describe('kibble:split lockstep tagging', function (): void {
    it('pushes a tag ref to the split repo for the filtered package when --tag is set', function (): void {
        $this->artisan('kibble:split', ['package' => 'packagist', '--tag' => 'v1.2.0'])
            ->assertSuccessful();

        Process::assertRan('git push https://github.com/artisan-build/packagist.git split-branch:main --force');
        Process::assertRan('git push https://github.com/artisan-build/packagist.git split-branch:refs/tags/v1.2.0 --force');
    });

    it('does not push any tag ref when --tag is omitted', function (): void {
        $this->artisan('kibble:split', ['package' => 'packagist'])
            ->assertSuccessful();

        Process::assertRan('git push https://github.com/artisan-build/packagist.git split-branch:main --force');
        Process::assertNotRan(fn ($process) => str_contains((string) $process->command, 'refs/tags/'));
    });

    it('tags only the filtered package, not every package', function (): void {
        $this->artisan('kibble:split', ['package' => 'packagist', '--tag' => 'v1.2.0'])
            ->assertSuccessful();

        Process::assertNotRan(fn ($process) => str_contains((string) $process->command, 'kibble.git split-branch:refs/tags/'));
    });

    it('injects GitHub credentials into the tag push outside the local environment', function (): void {
        config(['kibble.github_username' => 'ci-bot', 'kibble.github_token' => 'secret-token']);
        app()->detectEnvironment(fn () => 'production');

        $this->artisan('kibble:split', ['package' => 'packagist', '--tag' => 'v1.2.0'])
            ->assertSuccessful();

        Process::assertRan(fn ($process) => str_contains((string) $process->command, 'refs/tags/v1.2.0')
            && ($process->environment['GIT_USERNAME'] ?? null) === 'ci-bot'
            && ($process->environment['GIT_PASSWORD'] ?? null) === 'secret-token');
    });

    it('does not inject credentials in the local environment', function (): void {
        app()->detectEnvironment(fn () => 'local');

        $this->artisan('kibble:split', ['package' => 'packagist', '--tag' => 'v1.2.0'])
            ->assertSuccessful();

        Process::assertRan(fn ($process) => str_contains((string) $process->command, 'refs/tags/v1.2.0')
            && $process->environment === []);
    });

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
