<?php

namespace App\Service\Message\Handler\Media;

use App\Service\File\FileHelper;
use App\Service\File\UserUploadPathBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Writes media into the user's upload tree: a generated file from the data URL
 * or the download URL a provider returned (through MediaDownloader), and a
 * public `ai_` copy of an input image that a remote image-to-video provider
 * fetches. Knows whether APP_URL is reachable from the internet at all.
 */
final readonly class MediaUploadStore
{
    public function __construct(
        private UserUploadPathBuilder $userUploadPathBuilder,
        private MediaDownloader $mediaDownloader,
        private LoggerInterface $logger,
        private string $uploadDir = '/var/www/backend/var/uploads',
        #[Autowire('%env(APP_URL)%')]
        private string $publicBaseUrl = '',
    ) {
    }

    /**
     * Save a data URL (base64 encoded) as a file on disk.
     *
     * @param string $dataUrl   the data URL (e.g., data:image/png;base64,...)
     * @param int    $messageId the message ID for filename
     * @param int    $userId    the user ID for path generation
     * @param string $provider  the AI provider name (google, openai, etc.)
     *
     * @return string|null relative path from uploads dir or null on failure
     */
    public function saveDataUrlAsFile(string $dataUrl, int $messageId, int $userId, string $provider): ?string
    {
        // Parse: data:image/png;base64,XXXX
        if (!preg_match('#^data:([^;]+);base64,(.+)$#', $dataUrl, $matches)) {
            $this->logger->error('MediaGenerationHandler: Invalid data URL format');

            return null;
        }

        $mimeType = $matches[1];
        $base64Data = $matches[2];
        $content = base64_decode($base64Data, true);

        if (false === $content || '' === $content) {
            $this->logger->error('MediaGenerationHandler: Failed to decode base64 data');

            return null;
        }

        // Determine extension and sanitize provider
        $extension = FileHelper::getExtensionFromMimeType($mimeType);
        $sanitizedProvider = FileHelper::sanitizeProviderName($provider);

        // Generate filename: messageId_provider_timestamp.ext
        $timestamp = time();
        $filename = sprintf('%d_%s_%d.%s', $messageId, $sanitizedProvider, $timestamp, $extension);

        // Build storage path with user subdirectories: {last2}/{prev3}/{paddedUserId}/{year}/{month}/{filename}
        $year = date('Y');
        $month = date('m');
        $userBase = $this->userUploadPathBuilder->buildUserBaseRelativePath($userId);
        $relativePath = $userBase.'/'.$year.'/'.$month.'/'.$filename;
        $absolutePath = $this->uploadDir.'/'.$relativePath;

        // Create directory if not exists
        if (!FileHelper::ensureParentDirectory($absolutePath)) {
            $this->logger->error('MediaGenerationHandler: Failed to create upload directory', ['dir' => dirname($absolutePath)]);

            return null;
        }

        // Save file with proper permissions
        $bytesWritten = FileHelper::writeFile($absolutePath, $content);

        if (false === $bytesWritten) {
            $this->logger->error('MediaGenerationHandler: Failed to write file', ['path' => $absolutePath]);

            return null;
        }

        $this->logger->info('MediaGenerationHandler: Saved data URL as file', [
            'relative_path' => $relativePath,
            'mime_type' => $mimeType,
            'size' => $bytesWritten,
        ]);

        return $relativePath;
    }

    /**
     * Download media from URL to local storage.
     *
     * @param string $url       the media URL to download
     * @param int    $messageId the message ID for filename
     * @param int    $userId    the user ID for path generation
     * @param string $provider  the AI provider name
     * @param string $mediaType the type of media (image, video, audio)
     *
     * @return string|null relative path from uploads dir or null on failure
     */
    public function downloadMedia(string $url, int $messageId, int $userId, string $provider, string $mediaType): ?string
    {
        try {
            $this->logger->info('MediaGenerationHandler: Starting download', [
                'url' => $url,
                'upload_dir' => $this->uploadDir,
            ]);

            $content = $this->mediaDownloader->fetch($url);

            if (empty($content)) {
                throw new \Exception('Downloaded content is empty');
            }

            // Detect MIME type from content
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->buffer($content);

            // Determine extension with media-type-aware fallback
            $fallback = 'image' === $mediaType ? 'png' : ('video' === $mediaType ? 'mp4' : 'bin');
            $extension = FileHelper::getExtensionFromMimeType($mimeType, $fallback);
            $sanitizedProvider = FileHelper::sanitizeProviderName($provider);

            // Generate filename: messageId_provider_timestamp.ext
            $timestamp = time();
            $filename = sprintf('%d_%s_%d.%s', $messageId, $sanitizedProvider, $timestamp, $extension);

            // Build storage path with user subdirectories: {last2}/{prev3}/{paddedUserId}/{year}/{month}/{filename}
            $year = date('Y');
            $month = date('m');
            $userBase = $this->userUploadPathBuilder->buildUserBaseRelativePath($userId);
            $relativePath = $userBase.'/'.$year.'/'.$month.'/'.$filename;
            $absolutePath = $this->uploadDir.'/'.$relativePath;

            // Create directory if not exists
            if (!FileHelper::ensureParentDirectory($absolutePath)) {
                throw new \Exception('Failed to create upload directory: '.dirname($absolutePath));
            }

            // Save to disk with proper permissions
            $bytesWritten = FileHelper::writeFile($absolutePath, $content);
            if (false === $bytesWritten) {
                throw new \Exception('Failed to save media to disk');
            }

            $this->logger->info('MediaGenerationHandler: Media downloaded successfully', [
                'relative_path' => $relativePath,
                'bytes' => $bytesWritten,
                'mime_type' => $mimeType,
            ]);

            return $relativePath;
        } catch (\Exception $e) {
            $this->logger->error('MediaGenerationHandler: Failed to download media', [
                'error' => $e->getMessage(),
                'url' => FileHelper::redactUrlForLogging($url),
            ]);

            return null;
        }
    }

    /**
     * Publish a locally-stored input image at a public, internet-reachable URL
     * so a remote image-to-video provider can fetch it.
     *
     * The image is copied to an `ai_`-prefixed filename in the user's upload
     * tree. StaticUploadController serves `ai_*` files without authentication
     * (the same public bypass already used for AI-generated media), so the
     * resulting absolute URL is fetchable by the provider. Returns null when
     * the file can't be read/written or no public base URL is configured.
     *
     * @param string $absoluteLocalPath absolute path to the source image on disk
     * @param int    $userId            owner user id (for the upload sub-tree)
     *
     * @return string|null absolute public URL (e.g. https://host/api/v1/files/uploads/...), or null
     */
    public function publishInputImageForRemoteFetch(string $absoluteLocalPath, int $userId): ?string
    {
        $base = rtrim($this->publicBaseUrl, '/');
        if ('' === $base) {
            $this->logger->warning('MediaGenerationHandler: APP_URL not configured; cannot publish input image for image-to-video');

            return null;
        }

        if (!is_file($absoluteLocalPath)) {
            $this->logger->warning('MediaGenerationHandler: Input image not found on disk', ['path' => $absoluteLocalPath]);

            return null;
        }

        // Remote providers can only fetch publicly-resolvable hosts. localhost /
        // private hosts (typical in dev) will be rejected by the provider — warn
        // so the failure is diagnosable, but still return the URL so a public
        // deployment behind a localhost-looking proxy is not blocked here.
        // (The handler also guards this up front via isPublicBaseUrlReachable().)
        if (!$this->isPublicBaseUrlReachable()) {
            $this->logger->warning('MediaGenerationHandler: APP_URL is not internet-reachable; image-to-video providers will not be able to fetch the source image', [
                'app_url' => $base,
            ]);
        }

        $extension = strtolower(pathinfo($absoluteLocalPath, PATHINFO_EXTENSION)) ?: 'jpg';
        // `ai_` prefix => served without auth by StaticUploadController.
        $filename = sprintf('ai_i2vsrc_%d_%d_%s.%s', $userId, time(), bin2hex(random_bytes(6)), $extension);

        $userBase = $this->userUploadPathBuilder->buildUserBaseRelativePath($userId);
        $relativePath = $userBase.'/'.date('Y').'/'.date('m').'/'.$filename;
        $absoluteTarget = $this->uploadDir.'/'.$relativePath;

        if (!FileHelper::ensureParentDirectory($absoluteTarget)) {
            $this->logger->error('MediaGenerationHandler: Failed to create directory for input image copy', ['dir' => dirname($absoluteTarget)]);

            return null;
        }

        if (!@copy($absoluteLocalPath, $absoluteTarget)) {
            $this->logger->error('MediaGenerationHandler: Failed to copy input image to public path', [
                'source' => $absoluteLocalPath,
                'target' => $absoluteTarget,
            ]);

            return null;
        }

        FileHelper::setFilePermissions($absoluteTarget);

        return $base.'/api/v1/files/uploads/'.$relativePath;
    }

    /**
     * Is the configured public base URL (APP_URL) one that a remote AI provider
     * could realistically fetch from? Returns false for an empty value and for
     * hosts that are not routable from the public internet: loopback,
     * RFC 1918 / RFC 4193 private ranges, link-local, and non-public TLDs
     * (.local / .localhost / .internal / .lan / .home / .test). Used to
     * short-circuit image-to-video requests (whose source frame must be
     * provider-fetchable) with an actionable message instead of a raw
     * provider 400 ("invalid_image_url").
     */
    public function isPublicBaseUrlReachable(): bool
    {
        $base = rtrim($this->publicBaseUrl, '/');
        if ('' === $base) {
            return false;
        }

        $host = parse_url($base, \PHP_URL_HOST);
        if (!is_string($host) || '' === $host) {
            return false;
        }

        // Normalise an IPv6 literal ("[::1]" → "::1") before inspection.
        $host = trim($host, '[]');
        $lowerHost = strtolower($host);

        // Obvious local hostnames + non-public TLD suffixes.
        if ('localhost' === $lowerHost || 'host.docker.internal' === $lowerHost) {
            return false;
        }
        foreach (['.local', '.localhost', '.internal', '.lan', '.home', '.test'] as $suffix) {
            if (str_ends_with($lowerHost, $suffix)) {
                return false;
            }
        }

        // If the host is an IP literal, reject private + reserved ranges
        // (covers 10.x, 172.16–31.x, 192.168.x, 169.254.x, 127.x, ::1, fc00::/7, …).
        if (false !== filter_var($host, \FILTER_VALIDATE_IP)) {
            return false !== filter_var(
                $host,
                \FILTER_VALIDATE_IP,
                \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE,
            );
        }

        // A non-IP hostname that isn't one of the local cases above is assumed
        // to resolve publicly (a real DNS name behind a proxy/tunnel).
        return true;
    }
}
