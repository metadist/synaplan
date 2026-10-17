<?php

namespace App\Service\Message\Handler\Media;

/**
 * Fetches a generated media file from the URL a provider returned: cURL with
 * redirects, peer verification, a 60-second budget, a fresh DNS lookup and
 * IPv4 (Docker DNS), or file_get_contents() where cURL is missing. Tests
 * replace it, so no request leaves the process.
 */
final readonly class MediaDownloader
{
    /**
     * @throws \Exception when the download fails
     */
    public function fetch(string $url): string
    {
        // Download with cURL
        $content = null;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; PHP; Synaplan)');
            // Force fresh DNS lookup and prefer IPv4 to avoid Docker DNS issues
            curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, 0);
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

            $content = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            if (false === $content || 200 !== $httpCode) {
                throw new \Exception("cURL download failed (HTTP {$httpCode}): {$curlError}");
            }
        } else {
            $content = @file_get_contents($url);
            if (false === $content) {
                throw new \Exception('file_get_contents download failed');
            }
        }

        return $content;
    }
}
