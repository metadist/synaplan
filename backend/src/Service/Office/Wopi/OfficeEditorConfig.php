<?php

declare(strict_types=1);

namespace App\Service\Office\Wopi;

/**
 * Where the browser loads Collabora, and where Collabora calls back.
 *
 * Those are different hosts in Docker: the browser uses a published port on
 * localhost, while coolwsd (inside the compose network) must call the backend
 * service. Discovery is fetched from the internal convert URL when that is
 * set, then rewritten onto the public URL — coolwsd stamps its own hostname
 * into `urlsrc`, which the browser cannot resolve.
 */
final readonly class OfficeEditorConfig
{
    public function __construct(
        private string $publicUrl,
        private string $wopiUrl,
        private string $convertUrl,
        private string $frontendUrl,
    ) {
    }

    public static function usable(string $url): bool
    {
        $url = trim($url);

        return '' !== $url && 'disabled' !== strtolower($url);
    }

    public function isEnabled(): bool
    {
        return self::usable($this->publicUrl) && self::usable($this->wopiUrl);
    }

    public function publicUrl(): string
    {
        return rtrim(trim($this->publicUrl), '/');
    }

    /**
     * Base URL the backend uses to fetch `/hosting/discovery`.
     */
    public function discoverySource(): string
    {
        if (self::usable($this->convertUrl)) {
            return rtrim(trim($this->convertUrl), '/');
        }

        return $this->publicUrl();
    }

    public function wopiSrc(int $fileId): string
    {
        return rtrim(trim($this->wopiUrl), '/').'/api/v1/wopi/files/'.$fileId;
    }

    /**
     * Origin of the page that embeds the editor iframe. Collabora refuses
     * postMessage from anywhere else.
     */
    public function postMessageOrigin(): string
    {
        $parts = parse_url(trim($this->frontendUrl));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }
        $origin = $parts['scheme'].'://'.$parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }

    public function editorUrl(string $path, string $wopiSrc): string
    {
        $url = $this->publicUrl().$path;
        if (!str_ends_with($url, '?') && !str_ends_with($url, '&')) {
            $url .= str_contains($url, '?') ? '&' : '?';
        }

        return $url.'WOPISrc='.rawurlencode($wopiSrc);
    }
}
