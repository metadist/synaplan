<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use App\Entity\PlatformInstance;

/**
 * The browser address of a folder in a linked cloud, for **Open in …** on a
 * run card. Built from the host the instance registered with, because a
 * refused read never reaches the app that could name its own link.
 */
final readonly class CloudFolderLink
{
    public function __construct(
        private RedirectUriPolicy $redirects,
    ) {
    }

    public function to(string $client, string $host, string $folder): ?string
    {
        if ('' === $host || str_contains($host, '/') || !in_array($client, [PlatformInstance::CLIENT_NEXTCLOUD, PlatformInstance::CLIENT_OWNCLOUD], true)) {
            return null;
        }
        $bare = (string) preg_replace('/:\d+$/', '', $host);
        $scheme = $this->redirects->isDev() && $this->redirects->isLocalDevHost($bare) ? 'http' : 'https';
        $dir = '/'.trim($folder, '/');

        return $scheme.'://'.$host.'/index.php/apps/files/?'.http_build_query(['dir' => $dir]);
    }
}
