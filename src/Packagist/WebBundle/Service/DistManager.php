<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Service;

use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Package\PackageInterface;
use Doctrine\Common\Cache\Cache;
use Packagist\WebBundle\Composer\PackagistFactory;
use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Entity\Version;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

class DistManager
{
    private $config;
    private $fileSystem;
    private $packagistFactory;
    private $logger;
    private $cache;

    public function __construct(
        DistConfig $config,
        PackagistFactory $packagistFactory,
        LoggerInterface $logger,
        Cache $cache
    ) {
        $this->config = $config;
        $this->packagistFactory = $packagistFactory;
        $this->fileSystem = new Filesystem();
        $this->logger = $logger;
        $this->cache = $cache;
    }

    public function getDistPath(Version $version, $forceDownload = false): ?string
    {
        $dist = $version->getDist();
        if (false === isset($dist['reference'])) {
            return null;
        }

        $path = $this->config->generateDistFileName($version->getName(), $dist['reference'], $version->getVersion());
        if ($this->fileSystem->exists($path)) {
            try {
                $this->fileSystem->touch($path);
            } catch (IOException $exception) {}

            return $path;
        }

        return $this->download($version, $forceDownload);
    }

    public function lookupInCache(string $reference, string $packageName): ?array
    {
        $finder = new Finder();
        $files = $finder
            ->in($this->config->generateTargetDir($packageName))
            ->name("/$reference/")
            ->files();
        /** @var \SplFileObject $file */
        foreach ($files as $file) {
            $fileName = $file->getFilename();
            if ($version = $this->config->guessesVersion($fileName)) {
                try {
                    $this->fileSystem->touch($file->getRealPath());
                } catch (IOException $exception) {}

                return [$file->getRealPath(), $version];
            }
        }

        return [null, null];
    }

    public function resolvePackage(Package $package, string $hashRef): ?array
    {
        $cacheKey = self::getCacheId($package->getName(), $hashRef);
        // Check the cache for the zip.
        if ($cache = $this->cache->fetch($cacheKey)) {
            if (is_array($cache) && count($cache) === 2) {
                // Return the cached results.
                return $cache;
            }
        }

        [$path, $versionName] = $this->lookupInCache($hashRef, $package->getName());

        if ($versionName === null) {
            // The hash hasn't been downloaded before, attempt to download it.
            $sortedVersions = $package->getVersions()->toArray();
            usort($sortedVersions, [static::class, 'sortVersions']);
            if (count($sortedVersions)) {
                // Fetch the latest version available (most likely dev), and
                // attempt to use it to resolve the hash.
                $pseudoVersion = clone reset($sortedVersions);
                // Update references to the hash where it could possibly be used.
                $dist = $pseudoVersion->getDist();
                $source = $pseudoVersion->getSource();
                $dist['url'] = str_replace($dist['reference'], $hashRef, $dist['url']);
                $source['url'] = str_replace($source['reference'], $hashRef, $source['url']);
                $source['reference'] = $dist['reference'] = $hashRef;
                $pseudoVersion->setDist($dist);
                $pseudoVersion->setSource($source);
                // Attempt to force a download of the hash.
                if ($path = $this->getDistPath($pseudoVersion, true)) {
                    $versionName = $this->config->guessesVersion(basename($path));
                }
            }
        }

        $return = [$path, $versionName];

        // Cache the results here for a certain number of minutes.
        // This avoids wasting resources scanning the filesystem or checking out
        // repos searching for the specific hash.
        $this->cache->save($cacheKey, $return, $this->config->getCacheDuration());

        return $return;
    }

    private function download(Version $version, $forceDownload = false): ?string
    {
        $package = $version->getPackage();
        $io = new NullIO();
        $repository = $this->packagistFactory->createRepository(
            $package->getRepository(),
            $io,
            null,
            $package->getCredentials()
        );

        $factory = new Factory();
        $dm = $factory->createDownloadManager($io, $repository->getConfig());
        $archiveManager = $factory->createArchiveManager($repository->getConfig(), $dm);
        $archiveManager->setOverwriteFiles(false);

        $versions = $repository->getPackages();
        $source = $version->getSource();
        foreach ($versions as $rootVersion) {
            if ($rootVersion->getSourceReference() === $source['reference']) {
                $fileName = $this->config->getFileName($source['reference'], $version->getVersion());

                return $archiveManager->archive(
                    $rootVersion,
                    $this->config->getArchiveFormat(),
                    $this->config->generateTargetDir($version->getName()),
                    $fileName
                );
            }
        }

        if ($forceDownload && count($versions)) {
            // Attempt to resolve the latest version (typically dev).
            $sortedPackages = $versions;
            usort($sortedPackages, [static::class, 'sortPackages']);
            $latestPackage = reset($sortedPackages);
            $latestPackage->setSourceReference($source['reference']);
            // Create a forced download using the current tagged version.
            $fileName = $this->config->getFileName($source['reference'], $version->getVersion());

            try {
                return $archiveManager->archive(
                    $latestPackage,
                    $this->config->getArchiveFormat(),
                    $this->config->generateTargetDir($version->getName()),
                    $fileName
                );
            } catch (\Exception $e) {
                // Log silently.
                $this->logger->info('['.get_class($e).'] '.$e->getMessage());

                return null;
            }
        }

        return null;
    }

    public static function getCacheId(string $packageName, string $hashRef): string
    {
        return implode('|', ['zipball', $packageName, $hashRef]);
    }

    public function sortVersions(Version $a, Version $b)
    {
        $aVersion = $a->getVersion();
        $bVersion = $b->getVersion();
        $aVersion = static::normalizeVersionForComparison($aVersion);
        $bVersion = static::normalizeVersionForComparison($bVersion);

        // equal versions are sorted by date with newer first.
        if ($aVersion === $bVersion) {
            return $a->getUpdatedAt() > $b->getReleasedAt() ? -1 : 1;
        }

        // the rest is sorted by version
        return version_compare($aVersion, $bVersion);
    }

    public static function sortPackages(PackageInterface $a, PackageInterface $b)
    {
        $aVersion = $a->getPrettyVersion();
        $bVersion = $b->getPrettyVersion();
        $aVersion = static::normalizeVersionForComparison($aVersion);
        $bVersion = static::normalizeVersionForComparison($bVersion);

        // equal versions are sorted by date with newer first.
        if ($aVersion === $bVersion) {
            return $a->getReleaseDate() > $b->getReleaseDate() ? -1 : 1;
        }

        // the rest is sorted by version
        return version_compare($aVersion, $bVersion);
    }

    private static function normalizeVersionForComparison($version)
    {
        $version = preg_replace('{^dev-.+}', '0.0.0-alpha', $version);

        return $version;
    }

}
