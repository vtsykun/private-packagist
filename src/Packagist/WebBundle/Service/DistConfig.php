<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Service;

use Symfony\Component\Routing\RouterInterface;

class DistConfig
{
    private $config;
    private $router;

    /**
     * @param RouterInterface $router
     * @param array $config
     */
    public function __construct(RouterInterface $router, array $config)
    {
        $this->config = $config;
        $this->router = $router;
    }

    public function generateTargetDir(string $name)
    {
        $intermediatePath = \preg_replace('#[^a-z0-9-_/]#i', '-', $name);
        return \sprintf('%s/%s', $this->config['basedir'], $intermediatePath);
    }

    /**
     * @return string|null
     */
    public function getDistDir(): ?string
    {
        return $this->config['basedir'] ?? null;
    }

    /**
     * @return string
     */
    public function getArchiveFormat(): string
    {
        return $this->config['format'] ?? 'zip';
    }

    /**
     * @return int
     */
    public function getCacheDuration(): int
    {
        return $this->config['cache_duration'] ?? 1800;
    }

    /**
     * @param string $name
     * @param string $reference
     * @param string $version
     *
     * @return string
     */
    public function generateDistFileName(string $name, string $reference, string $version):  string
    {
        $targetDir = $this->generateTargetDir($name);
        $fileName = $this->getFileName($reference, $version);
        return $targetDir . '/' . $fileName . '.' . $this->getArchiveFormat();
    }

    /**
     * @param string $reference
     * @param string $version
     * @return string
     */
    public function getFileName(string $reference, string $version): string
    {
        $fileName = $version . '-' . $reference;
        return str_replace('/', '-', $fileName);
    }

    /**
     * @param string $fileName
     * @return string
     */
    public function guessesVersion(string $fileName)
    {
        $fileName = explode('-', $fileName);
        $pathCount = count($fileName);
        if ($pathCount > 1) {
            unset($fileName[$pathCount - 1]);
        }

        $fileName = implode('-', $fileName);
        $fileName = preg_replace('/(ticket|feature|fix)-/i', '$1/', $fileName);
        return $fileName;
    }

    /**
     * @return bool
     */
    public function isIncludeArchiveChecksum(): bool
    {
        return $this->config['include_archive_checksum'] ?? false;
    }

    /**
     * Generate link to download from dist
     *
     * @param string $name
     * @param string $reference
     *
     * @return string
     */
    public function generateRoute(string $name, string $reference): string
    {
        $uri = $this->router->generate(
            'download_dist_package',
            ['package' => $name, 'hash' => $reference . '.' . $this->getArchiveFormat()]
        );

        return rtrim($this->config['endpoint'], '/') . $uri;
    }

    /**
     * @return bool
     */
    public function isEnable(): bool
    {
        return !empty($this->config);
    }

    /**
     * @return bool
     */
    public function isLazy(): bool
    {
        return $this->config['lazy'] ?? true;
    }
}
