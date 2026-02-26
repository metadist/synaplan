<?php

declare(strict_types=1);

namespace App\Service;

use App\AI\Exception\ProviderException;
use App\AI\Service\AiFacade;
use App\Entity\Model;
use App\Entity\User;
use App\Service\File\FileHelper;
use App\Service\File\UserUploadPathBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class MediaGenerationService
{
    private const CURL_TIMEOUT_SECONDS = 60;
    private const CURL_CONNECT_TIMEOUT_SECONDS = 30;
    private const DEFAULT_IMAGE_SIZE = '1024x1024';
    private const DEFAULT_VIDEO_DURATION = 8;
    private const DEFAULT_VIDEO_ASPECT_RATIO = '16:9';

    public function __construct(
        private AiFacade $aiFacade,
        private ModelConfigService $modelConfigService,
        private RateLimitService $rateLimitService,
        private UserUploadPathBuilder $userUploadPathBuilder,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private string $uploadDir = '/var/www/backend/var/uploads',
    ) {
    }

    /**
     * Generate media (image or video) from a text prompt.
     *
     * @return array{success: true, file: array{url: string, type: string, mimeType: string}, provider: string, model: string}
     *
     * @throws \InvalidArgumentException on bad input
     * @throws \RuntimeException         on generation or storage failure
     */
    public function generate(User $user, string $prompt, string $type, ?int $modelId = null): array
    {
        $this->validateInput($prompt, $type);
        $this->checkRateLimit($user, $type);

        $resolved = $this->resolveModel($user, $type, $modelId);
        $provider = $resolved['provider'];
        $modelName = $resolved['modelName'];
        $modelConfig = $resolved['modelConfig'];

        $this->logger->info('Media generation request', [
            'user_id' => $user->getId(),
            'type' => $type,
            'provider' => $provider,
            'model' => $modelName,
            'prompt_length' => strlen($prompt),
        ]);

        $result = $this->callProvider($prompt, $type, $user, $provider, $modelName, $modelConfig);
        $mediaUrl = $this->extractMediaUrl($result, $type);

        if (null === $mediaUrl) {
            throw new \RuntimeException('Provider returned no media');
        }

        $localPath = $this->persistMedia($mediaUrl, $user->getId(), $provider, $type);

        if (null === $localPath) {
            throw new \RuntimeException('Failed to save generated media to disk');
        }

        $mimeType = $this->guessMimeType($localPath, $type);

        $this->recordUsage($user, $type, $provider, $modelName);

        return [
            'success' => true,
            'file' => [
                'url' => '/api/v1/files/uploads/'.$localPath,
                'type' => $type,
                'mimeType' => $mimeType,
            ],
            'provider' => $result['provider'] ?? $provider ?? 'unknown',
            'model' => $result['model'] ?? $modelName ?? 'unknown',
        ];
    }

    private function validateInput(string $prompt, string $type): void
    {
        if ('' === trim($prompt)) {
            throw new \InvalidArgumentException('Prompt is required');
        }

        if (!\in_array($type, ['image', 'video'], true)) {
            throw new \InvalidArgumentException('Type must be "image" or "video"');
        }
    }

    private function checkRateLimit(User $user, string $type): void
    {
        $action = 'image' === $type ? 'IMAGES' : 'VIDEOS';
        $check = $this->rateLimitService->checkLimit($user, $action);

        if (!$check['allowed']) {
            throw new \RuntimeException(sprintf('Rate limit exceeded for %s. Used: %d/%d', $action, $check['used'], $check['limit']));
        }
    }

    /**
     * @return array{provider: ?string, modelName: ?string, modelConfig: array<string, mixed>}
     */
    private function resolveModel(User $user, string $type, ?int $modelId): array
    {
        if (null === $modelId) {
            $capability = 'image' === $type ? 'TEXT2PIC' : 'TEXT2VID';
            $modelId = $this->modelConfigService->getDefaultModel($capability, $user->getId());
        }

        if (null === $modelId) {
            throw new \RuntimeException('No model available for '.$type.' generation');
        }

        $model = $this->em->getRepository(Model::class)->find($modelId);
        if (null === $model) {
            throw new \RuntimeException('Model not found: '.$modelId);
        }

        return [
            'provider' => strtolower($model->getService()),
            'modelName' => $model->getProviderId() ?: $model->getName(),
            'modelConfig' => $model->getJson(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function callProvider(
        string $prompt,
        string $type,
        User $user,
        ?string $provider,
        ?string $modelName,
        array $modelConfig,
    ): array {
        try {
            if ('video' === $type) {
                return $this->aiFacade->generateVideo($prompt, $user->getId(), [
                    'provider' => $provider,
                    'model' => $modelName,
                    'duration' => self::DEFAULT_VIDEO_DURATION,
                    'aspect_ratio' => self::DEFAULT_VIDEO_ASPECT_RATIO,
                ]);
            }

            return $this->aiFacade->generateImage($prompt, $user->getId(), [
                'provider' => $provider,
                'model' => $modelName,
                'modelConfig' => $modelConfig,
                'quality' => 'standard',
                'style' => 'vivid',
                'size' => self::DEFAULT_IMAGE_SIZE,
            ]);
        } catch (ProviderException $e) {
            $this->logger->error('Media generation provider error', [
                'error' => $e->getMessage(),
                'provider' => $provider,
                'type' => $type,
            ]);
            throw new \RuntimeException('Media generation failed: '.$e->getMessage(), 0, $e);
        }
    }

    private function extractMediaUrl(array $result, string $type): ?string
    {
        $items = 'video' === $type
            ? ($result['videos'] ?? [])
            : ($result['images'] ?? []);

        if (empty($items)) {
            return null;
        }

        $first = $items[0];

        if (\is_string($first)) {
            return $first;
        }

        return $first['url'] ?? $first['b64_json'] ?? $first['data'] ?? null;
    }

    private function persistMedia(string $mediaUrl, int $userId, ?string $provider, string $type): ?string
    {
        if (str_starts_with($mediaUrl, 'data:')) {
            return $this->saveDataUrl($mediaUrl, $userId, $provider ?? 'unknown');
        }

        return $this->downloadAndSave($mediaUrl, $userId, $provider ?? 'unknown', $type);
    }

    private function saveDataUrl(string $dataUrl, int $userId, string $provider): ?string
    {
        if (!preg_match('#^data:([^;]+);base64,(.+)$#', $dataUrl, $matches)) {
            return null;
        }

        $mimeType = $matches[1];
        $content = base64_decode($matches[2], true);
        if (false === $content) {
            return null;
        }

        $extension = FileHelper::getExtensionFromMimeType($mimeType);
        $filename = $this->buildFilename($userId, $provider, $extension);
        $relativePath = $this->buildRelativePath($userId, $filename);
        $absolutePath = $this->uploadDir.'/'.$relativePath;

        if (!FileHelper::ensureParentDirectory($absolutePath)) {
            return null;
        }

        if (false === FileHelper::writeFile($absolutePath, $content)) {
            return null;
        }

        return $relativePath;
    }

    private function downloadAndSave(string $url, int $userId, string $provider, string $type): ?string
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::CURL_TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CURL_CONNECT_TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; PHP; Synaplan)');
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if (false === $content || 200 !== $httpCode) {
            $this->logger->error('Media download failed', ['url' => $url, 'http_code' => $httpCode]);

            return null;
        }

        $extension = FileHelper::getExtensionFromMimeType($contentType ?: ('video' === $type ? 'video/mp4' : 'image/png'));
        $filename = $this->buildFilename($userId, $provider, $extension);
        $relativePath = $this->buildRelativePath($userId, $filename);
        $absolutePath = $this->uploadDir.'/'.$relativePath;

        if (!FileHelper::ensureParentDirectory($absolutePath)) {
            return null;
        }

        if (false === FileHelper::writeFile($absolutePath, $content)) {
            return null;
        }

        return $relativePath;
    }

    private function buildFilename(int $userId, string $provider, string $extension): string
    {
        $sanitized = FileHelper::sanitizeProviderName($provider);

        return sprintf('media_%d_%s_%d.%s', $userId, $sanitized, time(), $extension);
    }

    private function buildRelativePath(int $userId, string $filename): string
    {
        $userBase = $this->userUploadPathBuilder->buildUserBaseRelativePath($userId);

        return $userBase.'/'.date('Y').'/'.date('m').'/'.$filename;
    }

    private function guessMimeType(string $path, string $type): string
    {
        $ext = pathinfo($path, PATHINFO_EXTENSION);

        return match ($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            default => 'video' === $type ? 'video/mp4' : 'image/png',
        };
    }

    private function recordUsage(User $user, string $type, ?string $provider, ?string $modelName): void
    {
        $action = 'image' === $type ? 'IMAGES' : 'VIDEOS';
        $this->rateLimitService->recordUsage($user, $action, [
            'provider' => $provider ?? 'unknown',
            'model' => $modelName ?? 'unknown',
        ]);
    }
}
