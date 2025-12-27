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
        $pluginFrontendDir = $userBaseDir.'/plugins/'.$pluginName.'/frontend';

        // 2. Prevent path traversal
        $realPath = realpath($pluginFrontendDir.'/'.$path);
        if (!$realPath || !str_starts_with($realPath, realpath($pluginFrontendDir))) {
            throw new NotFoundHttpException("Asset '$path' not found or invalid.");
        }

        // 3. Check if file exists
        if (!is_file($realPath)) {
            throw new NotFoundHttpException("Asset '$path' not found.");
        }

        // 4. Determine MIME type (critical for ES modules)
        $mimeTypes = [
            'js' => 'application/javascript',
            'mjs' => 'application/javascript',
            'css' => 'text/css',
            'html' => 'text/html',
            'json' => 'application/json',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
        ];

        $extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
        $mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';

        // 5. Serve file with correct MIME type
        $response = new BinaryFileResponse($realPath);
        $response->headers->set('Content-Type', $mimeType);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE);

        // Set cache for 1 hour in dev, could be more in prod
        $response->setMaxAge(3600);

        return $response;
    }
}
