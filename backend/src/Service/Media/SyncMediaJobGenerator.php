<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\AI\Service\AiFacade;
use App\Service\File\FileHelper;
use App\Service\File\UserUploadPathBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Runs a SYNCHRONOUS media render (image or audio) for the async-job worker.
 *
 * Why this exists separately from the video path:
 * video generation is an asynchronous submit→poll→finalize operation
 * ({@see \App\AI\Interface\SupportsAsyncVideo}), so {@see AdvanceMediaJobCommandHandler}
 * advances it in short non-blocking steps. Image and audio generation are, by
 * contrast, a single blocking provider call ({@see AiFacade::generateImage()} /
 * {@see AiFacade::synthesize()}). To detach them onto the worker uniformly
 * (Release 4.0 locked decision: "detach all media") the worker needs a path that
 * does the whole render in ONE step and goes straight to `completed` — that is
 * this service.
 *
 * It reuses the same low-level upload primitives the worker's video finalize uses
 * ({@see UserUploadPathBuilder} + {@see FileHelper}) rather than duplicating the
 * inline {@see \App\Service\Message\Handler\MediaGenerationHandler} save logic, so
 * both paths write to the identical user-scoped layout.
 */
final readonly class SyncMediaJobGenerator
{
    private const DOWNLOAD_TIMEOUT_SECONDS = 60;

    public function __construct(
        private AiFacade $aiFacade,
        private UserUploadPathBuilder $userUploadPathBuilder,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $uploadDir = '/var/www/backend/var/uploads',
    ) {
    }

    /**
     * Produce the completed-job result descriptor for an image/audio job.
     *
     * @return array{file: array{url: string, type: string, mimeType: string}, provider: ?string, model: ?string}
     */
    public function generate(MediaJob $job): array
    {
        return match ($job->getType()) {
            MediaJob::TYPE_AUDIO => $this->generateAudio($job),
            MediaJob::TYPE_IMAGE => $this->generateImage($job),
            default => throw new \InvalidArgumentException(sprintf('SyncMediaJobGenerator cannot handle job type "%s"', $job->getType())),
        };
    }

    /**
     * @return array{file: array{url: string, type: string, mimeType: string}, provider: ?string, model: ?string}
     */
    private function generateAudio(MediaJob $job): array
    {
        $options = $job->getOptions();
        $language = is_string($options['lang'] ?? null)
            ? (string) $options['lang']
            : (is_string($options['language'] ?? null) ? (string) $options['language'] : 'en');

        // synthesize() already writes the file to the user-scoped upload path and
        // returns the relative path — no extra save step needed for audio.
        $result = $this->aiFacade->synthesize($job->getPrompt() ?? '', $job->getUserId(), [
            'provider' => $job->getProvider(),
            'model' => $job->getModel(),
            'format' => 'mp3',
            'language' => $language,
        ]);

        $relativePath = $result['relativePath'] ?? '';
        if (!is_string($relativePath) || '' === $relativePath) {
            throw new \RuntimeException('Audio synthesis returned no output file');
        }

        return [
            'file' => [
                'url' => '/api/v1/files/uploads/'.$relativePath,
                'type' => MediaJob::TYPE_AUDIO,
                'mimeType' => 'audio/mpeg',
            ],
            'provider' => is_string($result['provider'] ?? null) ? $result['provider'] : $job->getProvider(),
            'model' => is_string($result['model'] ?? null) ? $result['model'] : $job->getModel(),
        ];
    }

    /**
     * @return array{file: array{url: string, type: string, mimeType: string}, provider: ?string, model: ?string}
     */
    private function generateImage(MediaJob $job): array
    {
        $options = $job->getOptions();
        $options['provider'] = $job->getProvider();
        if (null !== $job->getModel()) {
            $options['model'] = $job->getModel();
        }

        $result = $this->aiFacade->generateImage($job->getPrompt() ?? '', $job->getUserId(), $options);

        $images = is_array($result['images'] ?? null) ? $result['images'] : [];
        $url = is_array($images[0] ?? null) ? ($images[0]['url'] ?? null) : null;
        if (!is_string($url) || '' === $url) {
            throw new \RuntimeException('Image generation returned no output');
        }

        $bytes = $this->resolveBytes($url);
        $relativePath = $this->saveImageBytes($bytes, $job->getUserId(), $job->getProvider());
        if (null === $relativePath) {
            throw new \RuntimeException('Failed to save generated image to disk');
        }

        return [
            'file' => [
                'url' => '/api/v1/files/uploads/'.$relativePath,
                'type' => MediaJob::TYPE_IMAGE,
                'mimeType' => 'image/png',
            ],
            'provider' => is_string($result['provider'] ?? null) ? $result['provider'] : $job->getProvider(),
            'model' => is_string($result['model'] ?? null) ? $result['model'] : $job->getModel(),
        ];
    }

    /**
     * Resolve the raw bytes for a provider image result, which may be a base64
     * `data:` URL (most providers) or an external http(s) URL.
     */
    private function resolveBytes(string $url): string
    {
        if (str_starts_with($url, 'data:')) {
            $commaPos = strpos($url, ',');
            if (false === $commaPos) {
                throw new \RuntimeException('Malformed data URL in image result');
            }
            $meta = substr($url, 5, $commaPos - 5);
            $payload = substr($url, $commaPos + 1);
            $bytes = str_contains($meta, 'base64')
                ? base64_decode($payload, true)
                : rawurldecode($payload);
            if (false === $bytes || '' === $bytes) {
                throw new \RuntimeException('Failed to decode image data URL');
            }

            return $bytes;
        }

        $response = $this->httpClient->request('GET', $url, ['timeout' => self::DOWNLOAD_TIMEOUT_SECONDS]);
        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException(sprintf('Image download failed (HTTP %d)', $response->getStatusCode()));
        }

        return $response->getContent();
    }

    private function saveImageBytes(string $bytes, int $userId, string $provider): ?string
    {
        $sanitized = FileHelper::sanitizeProviderName($provider);
        $filename = sprintf('media_%d_%s_%d.png', $userId, $sanitized, time());
        $relativePath = $this->userUploadPathBuilder->buildUserBaseRelativePath($userId)
            .'/'.date('Y').'/'.date('m').'/'.$filename;
        $absolutePath = $this->uploadDir.'/'.$relativePath;

        if (!FileHelper::ensureParentDirectory($absolutePath)) {
            $this->logger->error('SyncMediaJobGenerator: could not create upload directory', [
                'path' => $absolutePath,
            ]);

            return null;
        }

        if (false === FileHelper::writeFile($absolutePath, $bytes)) {
            $this->logger->error('SyncMediaJobGenerator: could not write image file', [
                'path' => $absolutePath,
            ]);

            return null;
        }

        return $relativePath;
    }
}
