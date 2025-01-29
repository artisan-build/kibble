<?php

namespace ArtisanBuild\Kibble;

use ArtisanBuild\GH\GH;
use ArtisanBuild\Packagist\Connectors\PackagistConnector;
use ArtisanBuild\Packagist\Requests\CreatePackageRequest;
use ArtisanBuild\Packagist\Requests\PackageInfoRequest;
use Illuminate\Support\Facades\File;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;

class Package
{
    public string $directory;

    public array $composer;

    public function __construct(
        public string $package,
        public ?string $vendor = null,
    ) {
        $this->vendor ??= config('kibble.organization');
        $this->directory = implode('/', [config('kibble.path'), $this->package]);
        $this->composer = File::json("{$this->directory}/composer.json");
    }

    public static function fromDirectory(string $directory): Package
    {
        $json = File::json("{$directory}/composer.json");

        [$vendor, $package] = explode('/', (string) $json['name']);

        return new self(package: $package, vendor: $vendor);
    }

    public function github(string $branch = 'main'): string
    {
        return GH::repo(implode('/', [$this->vendor, $this->package]))->view($branch);
    }

    /**
     * @throws FatalRequestException
     * @throws RequestException
     * @throws JsonException
     */
    public function packagist(): ?array
    {
        $connector = new PackagistConnector;
        $request = new PackageInfoRequest(vendor: $this->vendor, package: $this->package);

        $response = $connector->send($request);

        return $response->successful()
            ? $response->json()
            : null;
    }

    /**
     * @throws FatalRequestException
     * @throws RequestException
     * @throws JsonException
     */
    public function addToPackagist(): bool
    {
        $connector = new PackagistConnector;
        $request = new CreatePackageRequest(
            repository: "https://github.com/{$this->vendor}/{$this->package}",
            username: config('packagist.username'),
            token: config('packagist.main_token'),
        );

        return $connector->send($request)->successful();
    }

    public function directory(): string
    {
        return implode('/', [config('kibble.path'), $this->package]);
    }

    public function directoryExists(): bool
    {
        return File::isDirectory($this->directory());
    }
}
