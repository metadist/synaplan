<?php

declare(strict_types=1);

namespace App\Controller\Plugin;

use App\Service\File\FileStorageService;
use App\Service\Plugin\PluginAssetResponder;
use App\Service\Plugin\PluginManager;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the static frontend files of plugins.
 */
final class PluginAssetController extends AbstractController
{
    public function __construct(
        private readonly FileStorageService $fileStorageService,
        private readonly PluginAssetResponder $responder,
        #[Autowire('%plugins_dir%')]
        private readonly string $pluginsDir,
    ) {
    }

    #[Route('/api/v1/user/{userId}/plugins/{pluginName}/assets/{path}', name: 'app_plugin_asset', requirements: ['path' => '.*'], methods: ['GET'])]
    #[OA\Get(path: '/api/v1/user/{userId}/plugins/{pluginName}/assets/{path}', summary: 'Download a file from a plugin installed for this user', tags: ['Plugins'])]
    public function serveAsset(int $userId, string $pluginName, string $path): Response
    {
        $this->requireValidName($pluginName);
        $userBaseDir = $this->fileStorageService->getUserBaseAbsolutePath($userId);

        return $this->responder->respond($userBaseDir.'/plugins/'.$pluginName.'/frontend', $path);
    }

    /**
     * The same files without a user in the address: for pages outside
     * Synaplan (a script tag in Jitsi) that must not depend on an account.
     */
    #[Route('/api/v1/plugins/{pluginName}/assets/{path}', name: 'app_plugin_bundled_asset', requirements: ['pluginName' => '[a-z0-9_-]+', 'path' => '.+'], methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/plugins/{pluginName}/assets/{path}',
        summary: 'Download a file from a plugin shipped with this Synaplan',
        tags: ['Plugins'],
        parameters: [
            new OA\Parameter(name: 'pluginName', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'synascriber')),
            new OA\Parameter(name: 'path', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'jitsi/loader.js')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The file, with a Content-Type from its extension'),
            new OA\Response(response: 404, description: 'No such plugin or file'),
        ],
    )]
    public function serveBundledAsset(string $pluginName, string $path): Response
    {
        $this->requireValidName($pluginName);

        return $this->responder->respond($this->pluginsDir.'/'.$pluginName.'/frontend', $path);
    }

    private function requireValidName(string $pluginName): void
    {
        if (!PluginManager::isValidPluginName($pluginName)) {
            throw new NotFoundHttpException(sprintf("Plugin '%s' not found.", $pluginName));
        }
    }
}
