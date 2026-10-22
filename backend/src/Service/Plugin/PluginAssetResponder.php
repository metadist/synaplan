<?php

declare(strict_types=1);

namespace App\Service\Plugin;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves one file from a plugin's frontend directory, refusing anything
 * that resolves outside of it.
 */
final readonly class PluginAssetResponder
{
    private const MAX_AGE_SECONDS = 3600;

    /** The Content-Type matters for ES modules: browsers refuse a module served as octet-stream. */
    private const MIME_TYPES = [
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

    public function respond(string $frontendDir, string $path): BinaryFileResponse
    {
        $root = realpath($frontendDir);
        $realPath = false === $root ? false : realpath($frontendDir.'/'.$path);
        if (false === $root || false === $realPath || !str_starts_with($realPath, $root.DIRECTORY_SEPARATOR) || !is_file($realPath)) {
            throw new NotFoundHttpException(sprintf("Asset '%s' not found.", $path));
        }

        $extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
        $response = new BinaryFileResponse($realPath);
        $response->headers->set('Content-Type', self::MIME_TYPES[$extension] ?? 'application/octet-stream');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE);
        $response->setMaxAge(self::MAX_AGE_SECONDS);

        return $response;
    }
}
