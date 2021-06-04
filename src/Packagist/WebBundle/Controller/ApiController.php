<?php

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Packagist\WebBundle\Controller;

use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Entity\Webhook;
use Packagist\WebBundle\Model\PackageManager;
use Packagist\WebBundle\Webhook\HookBus;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ApiController extends Controller
{
    /**
     * @Route("/api/create-package", name="generic_create", defaults={"_format" = "json"})
     * @Method({"POST"})
     *
     * {@inheritdoc}
     */
    public function createPackageAction(Request $request)
    {
        $payload = json_decode($request->getContent(), true);
        if (!$payload) {
            return new JsonResponse(['status' => 'error', 'message' => 'Missing payload parameter'], 406);
        }
        $url = $payload['repository']['url'];
        $package = new Package;
        $user = $this->getUser();
        $package->addMaintainer($user);
        $package->setRepository($url);
        $this->get(PackageManager::class)->updatePackageUrl($package);
        $errors = $this->get('validator')->validate($package, null, ['Create']);
        if (count($errors) > 0) {
            $errorArray = [];
            foreach ($errors as $error) {
                $errorArray[$error->getPropertyPath()] =  $error->getMessage();
            }
            return new JsonResponse(['status' => 'error', 'message' => $errorArray], 406);
        }
        try {
            $em = $this->getDoctrine()->getManager();
            $em->persist($package);
            $em->flush();
        } catch (\Exception $e) {
            $this->get('logger')->critical($e->getMessage(), array('exception', $e));
            return new JsonResponse(['status' => 'error', 'message' => 'Error saving package'], 500);
        }

        return new JsonResponse(['status' => 'success'], 202);
    }

    /**
     * @Route("/api/update-package", name="generic_postreceive", defaults={"_format" = "json"})
     * @Route("/api/github", name="github_postreceive", defaults={"_format" = "json"})
     * @Route("/api/bitbucket", name="bitbucket_postreceive", defaults={"_format" = "json"})
     * @Method({"POST", "GET"})
     *
     * {@inheritdoc}
     */
    public function updatePackageAction(Request $request)
    {
        // parse the payload
        $payload = json_decode($request->request->get('payload'), true);
        if (!$payload && 0 === strpos($request->headers->get('Content-Type'), 'application/json')) {
            $payload = json_decode($request->getContent(), true);
        }

        if (!$payload && !$request->get('composer_package_name')) {
            return new JsonResponse(['status' => 'error', 'message' => 'Missing payload parameter'], 406);
        }

        $packages = [];
        // Get from query parameter.
        if ($packageNames = $request->get('composer_package_name')) {
            $packageNames = explode(',', $packageNames);
            $repo = $this->getDoctrine()->getRepository(Package::class);
            foreach ($packageNames as $packageName) {
                $packages = array_merge($packages, $repo->findBy(['name' => $packageName]));
            }
        }

        if (isset($payload['project']['git_http_url'])) { // gitlab event payload
            $urlRegex = '{^(?:ssh://git@|https?://|git://|git@)?(?P<host>[a-z0-9.-]+)(?::[0-9]+/|[:/])(?P<path>[\w.-]+(?:/[\w.-]+?)+)(?:\.git|/)?$}i';
            $url = $payload['project']['git_http_url'];
	    } elseif (isset($payload['repository']['html_url']) && !isset($payload['repository']['url'])) { // gitea event payload https://docs.gitea.io/en-us/webhooks/
            $urlRegex = '{^(?:ssh://(git@|gitea@)|https?://|git://|git@)?(?P<host>[a-z0-9.-]+)(?::[0-9]+/|[:/])(?P<path>[\w.-]+(?:/[\w.-]+?)+)(?:\.git|/)?$}i';
            $url = $payload['repository']['html_url'];
        } elseif (isset($payload['repository']['url'])) { // github/anything hook
            $urlRegex = '{^(?:ssh://git@|https?://|git://|git@)?(?P<host>[a-z0-9.-]+)(?::[0-9]+/|[:/])(?P<path>[\w.-]+(?:/[\w.-]+?)+)(?:\.git|/)?$}i';
            $url = $payload['repository']['url'];
            $url = str_replace('https://api.github.com/repos', 'https://github.com', $url);
        } elseif (isset($payload['repository']['links']['html']['href'])) { // bitbucket push event payload
            $urlRegex = '{^(?:https?://|git://|git@)?(?:api\.)?(?P<host>bitbucket\.org)[/:](?P<path>[\w.-]+/[\w.-]+?)(\.git)?/?$}i';
            $url = $payload['repository']['links']['html']['href'];
        } elseif (isset($payload['repository']['links']['clone'][0]['href'])) { // bitbucket on-premise
            $urlRegex = '{^(?:ssh://git@|https?://|git://|git@)?(?P<host>[a-z0-9.-]+)(?::[0-9]+/|[:/])(?P<path>[\w.-]+(?:/[\w.-]+?)+)(?:\.git|/)?$}i';
            $url = '';
            foreach ($payload['repository']['links']['clone'] as $id => $data) {
                if ($data['name'] == 'ssh') {
                    $url = $data['href'];
                    break;
                }
            }
        } elseif (isset($payload['canon_url']) && isset($payload['repository']['absolute_url'])) { // bitbucket post hook (deprecated)
            $urlRegex = '{^(?:https?://|git://|git@)?(?P<host>bitbucket\.org)[/:](?P<path>[\w.-]+/[\w.-]+?)(\.git)?/?$}i';
            $url = $payload['canon_url'] . $payload['repository']['absolute_url'];
        } elseif (isset($payload['composer']['package_name'])) { // custom webhook
            $packages = [];
            $packageNames = (array) $payload['composer']['package_name'];
            $repo = $this->getDoctrine()->getRepository(Package::class);
            foreach ($packageNames as $packageName) {
                $packages = array_merge($packages, $repo->findBy(['name' => $packageName]));
            }
        } elseif (empty($packages)) {
            return new JsonResponse(['status' => 'error', 'message' => 'Missing or invalid payload'], 406);
        }

        if ($packages) {
            return $this->schedulePostJobs($packages);
        }

        // Use the custom regex
        if (isset($payload['packeton']['regex'])) {
            $urlRegex = $payload['packeton']['regex'];
        }

        return $this->receivePost($request, $url, $urlRegex);
    }

    /**
     * @Route(
     *     "/api/packages/{package}",
     *     name="api_edit_package",
     *     requirements={"package"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?"},
     *     defaults={"_format" = "json"}
     * )
     * {@inheritDoc}
     * @ParamConverter("package", options={"mapping": {"package": "name"}})
     * @Method({"PUT"})
     */
    public function editPackageAction(Request $request, Package $package)
    {
        $user = $this->getUser();
        if (!$package->getMaintainers()->contains($user) && !$this->isGranted('ROLE_EDIT_PACKAGES')) {
            throw new AccessDeniedException();
        }

        $payload = json_decode($request->request->get('payload'), true);
        if (!$payload && 0 === strpos($request->headers->get('Content-Type'), 'application/json')) {
            $payload = json_decode($request->getContent(), true);
        }

        $package->setRepository($payload['repository']);
        $this->get(PackageManager::class)->updatePackageUrl($package);
        $errors = $this->get('validator')->validate($package, null, ["Update"]);
        if (count($errors) > 0) {
            $errorArray = [];
            foreach ($errors as $error) {
                $errorArray[$error->getPropertyPath()] =  $error->getMessage();
            }
            return new JsonResponse(['status' => 'error', 'message' => $errorArray], 406);
        }

        $package->setCrawledAt(null);

        $em = $this->getDoctrine()->getManager();
        $em->persist($package);
        $em->flush();

        return new JsonResponse(['status' => 'success'], 200);
    }

    /**
     * @Route("/downloads/{name}", name="track_download", requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+"}, defaults={"_format" = "json"})
     * @Method({"POST"})
     * @inheritDoc
     */
    public function trackDownloadAction(Request $request, $name)
    {
        $result = $this->getPackageAndVersionId($name, $request->request->get('version_normalized'));

        if (!$result) {
            return new JsonResponse(['status' => 'error', 'message' => 'Package not found'], 200);
        }

        $this->get('packagist.download_manager')->addDownloads(['id' => $result['id'], 'vid' => $result['vid'], 'ip' => $request->getClientIp()]);

        return new JsonResponse(['status' => 'success'], 201);
    }

    /**
     * @Route("/jobs/{id}", name="get_job", requirements={"id"="[a-f0-9]+"}, defaults={"_format" = "json"})
     * @Method({"GET"})
     * @inheritDoc
     */
    public function getJobAction(Request $request, string $id)
    {
        return new JsonResponse($this->get('scheduler')->getJobStatus($id), 200);
    }

    /**
     * Expects a json like:
     *
     * {
     *     "downloads": [
     *         {"name": "foo/bar", "version": "1.0.0.0"},
     *         // ...
     *     ]
     * }
     *
     * The version must be the normalized one
     *
     * @Route("/downloads/", name="track_download_batch", defaults={"_format" = "json"})
     * @Method({"POST"})
     * @inheritDoc
     */
    public function trackDownloadsAction(Request $request)
    {
        $contents = json_decode($request->getContent(), true);
        if (empty($contents['downloads']) || !is_array($contents['downloads'])) {
            return new JsonResponse(['status' => 'error', 'message' => 'Invalid request format, must be a json object containing a downloads key filled with an array of name/version objects'], 200);
        }

        $failed = [];
        $ip = $request->getClientIp();

        $jobs = [];
        foreach ($contents['downloads'] as $package) {
            $result = $this->getPackageAndVersionId($package['name'], $package['version']);

            if (!$result) {
                $failed[] = $package;
                continue;
            }

            $jobs[] = ['id' => $result['id'], 'vid' => $result['vid'], 'ip' => $ip];
        }
        $this->get('packagist.download_manager')->addDownloads($jobs);

        if ($failed) {
            return new JsonResponse(['status' => 'partial', 'message' => 'Packages '.json_encode($failed).' not found'], 200);
        }

        return new JsonResponse(['status' => 'success'], 201);
    }

    /**
     * @Route("/api/webhook-invoke/{name}", name="generic_webhook_invoke", defaults={"_format" = "json", "name" = "default"})
     *
     * {@inheritdoc}
     */
    public function notifyWebhookAction($name, Request $request)
    {
        $payload = array_merge($request->request->all(), $request->query->all());
        unset($payload['token']);

        // Always try to decode json
        if ($content = $request->getContent()) {
            $content = @json_decode($content, true);
            if (is_array($content)) {
                $payload = array_merge($payload, $content);
            }
        }

        $context = [
            'event' => Webhook::HOOK_HTTP_REQUEST,
            'name' => $name,
            'ip_address' => $request->getClientIp(),
            'request' => $payload
        ];

        $jobs = [];
        $bus = $this->get(HookBus::class);
        $webhooks = $this->getDoctrine()->getRepository(Webhook::class)
            ->findActive($name,  [Webhook::HOOK_HTTP_REQUEST]);

        foreach ($webhooks as $webhook) {
            $jobs[] = $bus->dispatch($context, $webhook)->getId();
        }

        return new JsonResponse(['status' => 'success', 'jobs' => $jobs], count($jobs) === 0 ? 200 : 202);
    }

    /**
     * @param string $name
     * @param string $version
     * @return array
     */
    protected function getPackageAndVersionId($name, $version)
    {
        return $this->get('doctrine.dbal.default_connection')->fetchAssoc(
            'SELECT p.id, v.id as vid
            FROM package p
            LEFT JOIN package_version v ON p.id = v.package_id
            WHERE p.name = ?
            AND v.normalizedVersion = ?
            LIMIT 1',
            array($name, $version)
        );
    }

    /**
     * Perform the package update
     *
     * @param Request $request the current request
     * @param string $url the repository's URL (deducted from the request)
     * @param string $urlRegex the regex used to split the user packages into domain and path
     * @return Response
     */
    protected function receivePost(Request $request, $url, $urlRegex)
    {
        // try to parse the URL first to avoid the DB lookup on malformed requests
        if (!preg_match($urlRegex, $url)) {
            return new Response(json_encode(['status' => 'error', 'message' => 'Could not parse payload repository URL']), 406);
        }

        // try to find the all package
        $packages = $this->findPackagesByUrl($url, $urlRegex);

        return $this->schedulePostJobs($packages);
    }

    /**
     * @param Package[] $packages
     * @return Response
     */
    protected function schedulePostJobs(array $packages)
    {
        if (!$packages) {
            return new Response(json_encode(['status' => 'error', 'message' => 'Could not find a package that matches this request (does user maintain the package?)']), 404);
        }

        // put both updating the database and scanning the repository in a transaction
        $em = $this->get('doctrine.orm.entity_manager');
        $jobs = [];

        foreach ($packages as $package) {
            $package->setAutoUpdated(true);
            $em->flush($package);

            $job = $this->get('scheduler')->scheduleUpdate($package);
            $jobs[] = $job->getId();
        }

        return new JsonResponse(['status' => 'success', 'jobs' => $jobs], 202);
    }

    /**
     * Find a user package given by its full URL
     *
     * @param string $url
     * @param string $urlRegex
     * @return array the packages found
     */
    protected function findPackagesByUrl($url, $urlRegex)
    {
        if (!preg_match($urlRegex, $url, $matched)) {
            return [];
        }

        $packages = [];
        $repo = $this->getDoctrine()->getRepository('PackagistWebBundle:Package');
        foreach ($repo->findAll() as $package) {
            if (preg_match($urlRegex, $package->getRepository(), $candidate)
                && strtolower($candidate['host']) === strtolower($matched['host'])
                && strtolower($candidate['path']) === strtolower($matched['path'])
            ) {
                $packages[] = $package;
            }
        }

        return $packages;
    }
}
