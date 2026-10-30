<?php

declare(strict_types=1);

namespace App\Service\Message\Handler\Media;

use App\Service\Egress\HttpBytes;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches a generated media file from the URL a provider returned, through
 * the shared HTTP client. Tests replace it, so no request leaves the process.
 */
final readonly class MediaDownloader
{
    private HttpBytes $bytes;

    public function __construct(HttpClientInterface $http)
    {
        $this->bytes = new HttpBytes($http);
    }

    /**
     * @throws \Exception when the download fails
     */
    public function fetch(string $url): string
    {
        return $this->bytes->download($url, 60);
    }
}
