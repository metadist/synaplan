<?php

declare(strict_types=1);

namespace App\Controller\Plugin;

use App\Service\File\FileStorageService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves static assets from user-installed plugins.
 */
final class PluginAssetController extends AbstractController
{
    public function __construct(
        private readonly FileStorageService $fileStorageService,
    ) {
    }

    #[Route('/api/v1/user/{userId}/plugins/{pluginName}/assets/{path}', name: 'app_plugin_asset', requirements: ['path' => '.*'], methods: ['GET'])]
    public function serveAsset(int $userId, string $pluginName, string $path): Response
    {
        // 1. Resolve user plugin directory
        $userBaseDir = $this->fileStorageService->getUserBaseAbsolutePath($userId);
        $pluginFrontendDir = $userBaseDir.'/PLUGINS/'.$pluginName.'/frontend';

        // 2. Prevent path traversal
        $realPath = realpath($pluginFrontendDir.'/'.$path);
        if (!$realPath || !str_starts_with($realPath, realpath($pluginFrontendDir))) {
            throw new NotFoundHttpException("Asset '$path' not found or invalid.");
        }

        // 3. Check if file exists
        if (!is_file($realPath)) {
            throw new NotFoundHttpException("Asset '$path' not found.");
        }

        // 4. Serve file
        $response = new BinaryFileResponse($realPath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE);

        // Set cache for 1 hour in dev, could be more in prod
        $response->setMaxAge(3600);

        return $response;
    }
}
