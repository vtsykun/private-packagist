<?php

namespace Packagist\WebBundle\Controller;

use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Entity\Version;
use Packagist\WebBundle\Service\DistManager;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
   
class ProviderController extends Controller
{
    /**
     * @Route("/packages.json", name="root_packages", defaults={"_format" = "json"})
     * @Method({"GET"})
     */
    public function packagesAction()
    {
        $manager = $this->container->get('packagist.package_manager');
        $rootPackages = $manager->getRootPackagesJson($this->getUser());

        return new Response(\json_encode($rootPackages), 200, ['Content-Type' => 'application/json']);
    }

    /**
     * @Route(
     *     "/p/providers${hash}.json",
     *     requirements={"hash"="[a-f0-9]+"},
     *     name="root_providers", defaults={"_format" = "json"}
     * )
     *
     * @param string $hash
     * @return Response
     *
     * @Method({"GET"})
     */
    public function providersAction($hash)
    {
        $manager = $this->container->get('packagist.package_manager');
        $providers = $manager->getProvidersJson($this->getUser(), $hash);
        if (!$providers) {
            return $this->createNotFound();
        }

        return new Response(\json_encode($providers), 200, ['Content-Type' => 'application/json']);
    }

    /**
     * @Route(
     *     "/p/{package}.json",
     *     requirements={"package"="[\w+\/\-\$]+"},
     *     name="root_package", defaults={"_format" = "json"}
     * )
     *
     * @param string $package
     * @return Response
     *
     * @Method({"GET"})
     */
    public function packageAction($package)
    {
        $package = \explode('$', $package);
        $manager = $this->container->get('packagist.package_manager');
        if (\count($package) !== 2) {
            $package = $manager->getPackageJson($this->getUser(), $package[0]);
            if ($package) {
                return new Response(\json_encode($package), 200, ['Content-Type' => 'application/json']);
            }
            return $this->createNotFound();
        }

        $manager = $this->container->get('packagist.package_manager');
        $package = $manager->getCachedPackageJson($this->getUser(), $package[0], $package[1]);
        if (!$package) {
            return $this->createNotFound();
        }

        return new Response(\json_encode($package), 200, ['Content-Type' => 'application/json']);
    }

    /**
     * @Route(
     *     "/zipball/{package}/{hash}",
     *     name="download_dist_package",
     *     requirements={"package"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?", "hash"="[a-f0-9]{40}\.[a-z]+?"}
     * )
     * @Method({"GET"})
     * @ParamConverter("package", options={"mapping": {"package": "name"}})
     *
     * @param Package $package
     * @param string $hash
     * @return Response
     */
    public function zipballAction(Package $package, $hash)
    {
        $distManager = $this->container->get(DistManager::class);
        if (false === \preg_match('{[a-f0-9]{40}}i', $hash, $match)) {
            return new JsonResponse(['status' => 'error', 'message' => 'Not Found'], 404);
        }

        $versions = $package->getVersions()->filter(
            function (Version $version) use ($match) {
                if ($dist = $version->getDist()) {
                    return isset($dist['reference']) && $match[0] === $dist['reference'];
                }
                return false;
            }
        );

        // Try to download from cache
        if ($versions->count() === 0) {
            list($path, $versionName) = $distManager->lookupInCache($match[0], $package->getName());
            if (null !== $versionName) {
                $version = $package->getVersions()
                    ->filter(
                        function (Version $version) use ($versionName) {
                            return $versionName === $version->getVersion();
                        }
                )->first();
                if ($version && ($this->isGranted('ROLE_MAINTAINER', $version) || $this->container->getParameter('packagist_web.allow_read_only_access'))) {
                    return new BinaryFileResponse($path);
                }
            }
        }

        /** @var Version $version */
        foreach ($versions as $version) {
            if (!$this->isGranted('ROLE_MAINTAINER', $version) && !$this->container->getParameter('packagist_web.allow_read_only_access')) {
                continue;
            }
            

            if ($path = $distManager->getDistPath($version)) {
                return new BinaryFileResponse($path);
            }
            break;
        }

        return $this->createNotFound();
    }

    protected function createNotFound()
    {
        return new JsonResponse(['status' => 'error', 'message' => 'Not Found'], 404);
    }
}
