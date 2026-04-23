<?php

declare(strict_types=1);

namespace App\Service;

use App\AI\Exception\ProviderException;
use App\AI\Service\AiFacade;
use App\Entity\Model;
use App\Entity\User;
use App\Service\Exception\NoModelAvailableException;
use App\Service\Exception\RateLimitExceededException;
use App\Service\File\FileHelper;
use App\Service\File\UserUploadPathBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

final readonly class MediaGenerationService implements MediaGenerationServiceInterface
{
    private const CURL_TIMEOUT_SECONDS = 60;
    private const CURL_CONNECT_TIMEOUT_SECONDS = 30;
    private const DEFAULT_IMAGE_SIZE = '1024x1024';
    private const DEFAULT_VIDEO_DURATION = 8;
    private const DEFAULT_VIDEO_ASPECT_RATIO = '16:9';
    /**
     * Fallback resolution when neither caller nor model JSON specifies one.
     * 720p is Google's cheapest tier across all Veo variants.
     */
    private const DEFAULT_VIDEO_RESOLUTION_FALLBACK = '720p';
    private const VIDEO_JOB_TTL_SECONDS = 1200;

    public function __construct(
        private AiFacade $aiFacade,
        private ModelConfigService $modelConfigService,
        private RateLimitService $rateLimitService,
        private UserUploadPathBuilder $userUploadPathBuilder,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private CacheItemPoolInterface $cache,
        private string $uploadDir = '/var/www/backend/var/uploads',
    ) {
    }

    /**
     * Generate media (image or video) from a text prompt.
     *
     * @return array{success: true, file: array{url: string, type: string, mimeType: string}, provider: string, model: string}
     *
     * @throws \InvalidArgumentException  on bad input
     * @throws RateLimitExceededException when user exceeds quota
     * @throws NoModelAvailableException  when no model is configured
     * @throws ProviderException          on AI provider failure
     * @throws \RuntimeException          on storage failure
     */
    public function generate(User $user, string $prompt, string $type, ?int $modelId = null, ?string $resolution = null): array
    {
        $this->validateInput($prompt, $type);
        $this->checkRateLimit($user, $type);

        $resolved = $this->resolveModel($user, $type, $modelId);
        $provider = $resolved['provider'];
        $modelName = $resolved['modelName'];
        $modelConfig = $resolved['modelConfig'];
        $resolvedModelId = $resolved['modelId'];

        if ('video' === $type) {
            $resolution = $this->normalizeResolution($resolution, $modelConfig);
        } else {
            $resolution = null;
        }

        $this->logger->info('Media generation request', [
            'user_id' => $user->getId(),
            'type' => $type,
            'provider' => $provider,
            'model' => $modelName,
            'prompt_length' => strlen($prompt),
            'resolution' => $resolution,
        ]);

        $result = $this->callProvider($prompt, $type, $user, $provider, $modelName, $modelConfig, $resolution);
        $mediaUrl = $this->extractMediaUrl($result, $type);

        if (null === $mediaUrl) {
            throw new \RuntimeException('Provider returned no media');
        }

        $localPath = $this->persistMedia($mediaUrl, $user->getId(), $provider, $type);

        if (null === $localPath) {
            throw new \RuntimeException('Failed to save generated media to disk');
        }

        $mimeType = $this->guessMimeType($localPath, $type);

        $usedResolution = 'video' === $type
            ? ($result['videos'][0]['resolution'] ?? $resolution)
            : null;

        $this->recordUsage($user, $type, $provider, $modelName, $resolvedModelId, null, $usedResolution);

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

    public function generateFromImages(User $user, string $prompt, array $imagePaths, ?int $modelId = null): array
    {
        if ('' === trim($prompt)) {
            throw new \InvalidArgumentException('Prompt is required');
        }

        if (empty($imagePaths) || \count($imagePaths) > 2) {
            throw new \InvalidArgumentException('1 or 2 images are required');
        }

        foreach ($imagePaths as $path) {
            if (!file_exists($path)) {
                throw new \InvalidArgumentException('Uploaded image not found: '.basename($path));
            }
        }

        $this->checkRateLimit($user, 'image');

        $resolved = $this->resolveModel($user, 'pic2pic', $modelId);
        $provider = $resolved['provider'];
        $modelName = $resolved['modelName'];
        $modelConfig = $resolved['modelConfig'];
        $resolvedModelId = $resolved['modelId'];

        $this->logger->info('Pic2pic generation request', [
            'user_id' => $user->getId(),
            'provider' => $provider,
            'model' => $modelName,
            'image_count' => \count($imagePaths),
            'prompt_length' => \strlen($prompt),
        ]);

        $result = $this->aiFacade->generateImage($prompt, $user->getId(), [
            'provider' => $provider,
            'model' => $modelName,
            'modelConfig' => $modelConfig,
            'images' => $imagePaths,
            'quality' => 'high',
            'size' => self::DEFAULT_IMAGE_SIZE,
        ]);

        $mediaUrl = $this->extractMediaUrl($result, 'image');

        if (null === $mediaUrl) {
            throw new \RuntimeException('Provider returned no media');
        }

        $localPath = $this->persistMedia($mediaUrl, $user->getId(), $provider, 'image');

        if (null === $localPath) {
            throw new \RuntimeException('Failed to save generated media to disk');
        }

        $mimeType = $this->guessMimeType($localPath, 'image');

        $this->recordUsage($user, 'image', $provider, $modelName, $resolvedModelId);

        // Clean up temporary upload files
        foreach ($imagePaths as $tmpPath) {
            if (file_exists($tmpPath) && str_contains($tmpPath, sys_get_temp_dir())) {
                @unlink($tmpPath);
            }
        }

        return [
            'success' => true,
            'file' => [
                'url' => '/api/v1/files/uploads/'.$localPath,
                'type' => 'image',
                'mimeType' => $mimeType,
            ],
            'provider' => $result['provider'] ?? $provider ?? 'unknown',
            'model' => $result['model'] ?? $modelName ?? 'unknown',
        ];
    }

    public function startVideoGeneration(User $user, string $prompt, ?int $modelId = null, ?string $resolution = null): array
    {
        if ('' === trim($prompt)) {
            throw new \InvalidArgumentException('Prompt is required');
        }

        $this->checkRateLimit($user, 'video');

        $resolved = $this->resolveModel($user, 'video', $modelId);
        $provider = $resolved['provider'];
        $modelName = $resolved['modelName'];
        $modelConfig = $resolved['modelConfig'];
        $resolvedModelId = $resolved['modelId'];

        $effectiveResolution = $this->normalizeResolution($resolution, $modelConfig);

        $this->logger->info('Starting async video generation', [
            'user_id' => $user->getId(),
            'provider' => $provider,
            'model' => $modelName,
            'resolution' => $effectiveResolution,
        ]);

        $operationData = $this->aiFacade->startVideoGeneration($prompt, $user->getId(), [
            'provider' => $provider,
            'model' => $modelName,
            'modelConfig' => $modelConfig,
            'duration' => self::DEFAULT_VIDEO_DURATION,
            'aspect_ratio' => self::DEFAULT_VIDEO_ASPECT_RATIO,
            'resolution' => $effectiveResolution,
        ]);

        $jobId = bin2hex(random_bytes(16));

        $item = $this->cache->getItem('video_job_'.$jobId);
        $item->set([
            'operationName' => $operationData['operationName'],
            'status' => 'processing',
            'provider' => $operationData['provider'],
            'model' => $operationData['model'],
            'duration' => $operationData['duration'],
            'resolution' => $operationData['resolution'],
            'userId' => $user->getId(),
            'prompt' => $prompt,
            'startedAt' => time(),
            'modelId' => $resolvedModelId,
        ]);
        $item->expiresAfter(self::VIDEO_JOB_TTL_SECONDS);
        $this->cache->save($item);

        return [
            'jobId' => $jobId,
            'status' => 'processing',
            'provider' => $operationData['provider'],
            'model' => $operationData['model'],
            'resolution' => $operationData['resolution'],
        ];
    }

    public function checkVideoJob(User $user, string $jobId): array
    {
        if (1 !== preg_match('/^[a-f0-9]{32}$/', $jobId)) {
            throw new \InvalidArgumentException('Invalid job ID format');
        }

        $item = $this->cache->getItem('video_job_'.$jobId);
        if (!$item->isHit()) {
            throw new \InvalidArgumentException('Video job not found or expired');
        }

        /** @var array<string, mixed> $jobData */
        $jobData = $item->get();

        if ($jobData['userId'] !== $user->getId()) {
            throw new \InvalidArgumentException('Video job not found or expired');
        }

        $elapsed = time() - $jobData['startedAt'];
        $status = is_string($jobData['status'] ?? null) ? $jobData['status'] : 'processing';

        if ('completed' === $status && isset($jobData['result']) && is_array($jobData['result'])) {
            return $jobData['result'];
        }

        if ('failed' === $status && isset($jobData['error']) && is_string($jobData['error'])) {
            return [
                'status' => 'failed',
                'error' => $jobData['error'],
                'elapsed_seconds' => $elapsed,
            ];
        }

        if ('finalizing' === $status) {
            return [
                'status' => 'processing',
                'elapsed_seconds' => $elapsed,
            ];
        }

        if (isset($jobData['videoUri'])) {
            // If we already have the URI from a previous poll but download failed, reuse it
            $result = ['done' => true, 'videoUri' => $jobData['videoUri'], 'error' => null];
        } else {
            try {
                $result = $this->aiFacade->pollVideoOperation($jobData['operationName'], $jobData['provider']);
            } catch (ProviderException $e) {
                $jobData['status'] = 'failed';
                $jobData['error'] = $e->getMessage();
                $this->storeVideoJobState($jobId, $jobData);

                throw $e;
            }

            if (!$result['done']) {
                return [
                    'status' => 'processing',
                    'elapsed_seconds' => $elapsed,
                ];
            }

            if ($result['error']) {
                $jobData['status'] = 'failed';
                $jobData['error'] = $result['error'];
                $this->storeVideoJobState($jobId, $jobData);

                return [
                    'status' => 'failed',
                    'error' => $result['error'],
                    'elapsed_seconds' => $elapsed,
                ];
            }
        }

        $jobData['status'] = 'finalizing';
        $jobData['videoUri'] = $result['videoUri'];
        $this->storeVideoJobState($jobId, $jobData);

        try {
            $videoBytes = $this->aiFacade->downloadVideoRaw($result['videoUri'], $jobData['provider']);
            $localPath = $this->saveRawVideo($videoBytes, $user->getId(), $jobData['provider']);
        } catch (\Throwable $e) {
            $jobData['status'] = 'processing';
            $this->storeVideoJobState($jobId, $jobData);

            throw new \RuntimeException('Video download/save failed: '.$e->getMessage(), 0, $e);
        }

        if (null === $localPath) {
            $jobData['status'] = 'processing';
            $this->storeVideoJobState($jobId, $jobData);

            throw new \RuntimeException('Failed to save generated video to disk');
        }

        $this->recordUsage(
            $user,
            'video',
            $jobData['provider'],
            $jobData['model'],
            $jobData['modelId'] ?? null,
            (float) ($jobData['duration'] ?? self::DEFAULT_VIDEO_DURATION),
            isset($jobData['resolution']) && is_string($jobData['resolution']) ? $jobData['resolution'] : null,
        );
        $completedResult = $this->buildCompletedVideoJobResult($jobData['provider'], $jobData['model'], $localPath, $elapsed);
        $jobData['status'] = 'completed';
        $jobData['result'] = $completedResult;
        $this->storeVideoJobState($jobId, $jobData);

        return $completedResult;
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
            throw new RateLimitExceededException($action, (int) $check['used'], (int) $check['limit']);
        }
    }

    /**
     * @return array{provider: ?string, modelName: ?string, modelConfig: array<string, mixed>, modelId: int}
     */
    private function resolveModel(User $user, string $type, ?int $modelId): array
    {
        if (null === $modelId) {
            $capability = match ($type) {
                'pic2pic' => 'PIC2PIC',
                'video' => 'TEXT2VID',
                default => 'TEXT2PIC',
            };
            $modelId = $this->modelConfigService->getDefaultModel($capability, $user->getId());
        }

        if (null === $modelId) {
            throw new NoModelAvailableException('No model available for '.$type.' generation');
        }

        $model = $this->em->getRepository(Model::class)->find($modelId);
        if (null === $model) {
            throw new NoModelAvailableException('Model not found: '.$modelId);
        }

        return [
            'provider' => strtolower($model->getService()),
            'modelName' => $model->getProviderId() ?: $model->getName(),
            'modelConfig' => $model->getJson(),
            'modelId' => $modelId,
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
        ?string $resolution = null,
    ): array {
        if ('video' === $type) {
            return $this->aiFacade->generateVideo($prompt, $user->getId(), [
                'provider' => $provider,
                'model' => $modelName,
                'modelConfig' => $modelConfig,
                'duration' => self::DEFAULT_VIDEO_DURATION,
                'aspect_ratio' => self::DEFAULT_VIDEO_ASPECT_RATIO,
                'resolution' => $resolution,
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
    }

    /**
     * Validate a caller-supplied resolution against the model's allowed list and
     * fall back to the model's default (or the global fallback) when invalid/missing.
     *
     * @param array<string, mixed> $modelConfig
     */
    private function normalizeResolution(?string $requested, array $modelConfig): string
    {
        $allowed = is_array($modelConfig['allowed_resolutions'] ?? null)
            ? array_values(array_filter($modelConfig['allowed_resolutions'], 'is_string'))
            : [];

        if (null !== $requested && '' !== $requested && [] !== $allowed && !in_array($requested, $allowed, true)) {
            $this->logger->warning('Requested video resolution not allowed for model, falling back', [
                'requested' => $requested,
                'allowed' => $allowed,
            ]);
            $requested = null;
        }

        if (null !== $requested && '' !== $requested) {
            return $requested;
        }

        $default = $modelConfig['default_resolution'] ?? null;
        if (is_string($default) && '' !== $default) {
            return $default;
        }

        return [] !== $allowed ? $allowed[0] : self::DEFAULT_VIDEO_RESOLUTION_FALLBACK;
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

        if (isset($first['url'])) {
            return $first['url'];
        }

        if (isset($first['b64_json'])) {
            $mime = $first['content_type'] ?? ('video' === $type ? 'video/mp4' : 'image/png');

            return 'data:'.$mime.';base64,'.$first['b64_json'];
        }

        return $first['data'] ?? null;
    }

    private function saveRawVideo(string $content, int $userId, string $provider): ?string
    {
        $filename = $this->buildFilename($userId, $provider, 'mp4');
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

    /**
     * @param array<string, mixed> $jobData
     */
    private function storeVideoJobState(string $jobId, array $jobData): void
    {
        $item = $this->cache->getItem('video_job_'.$jobId);
        $item->set($jobData);
        $item->expiresAfter(self::VIDEO_JOB_TTL_SECONDS);
        $this->cache->save($item);
    }

    /**
     * @return array{status: string, file: array{url: string, type: string, mimeType: string}, provider: string, model: string, elapsed_seconds: int}
     */
    private function buildCompletedVideoJobResult(string $provider, string $model, string $localPath, int $elapsed): array
    {
        return [
            'status' => 'completed',
            'file' => [
                'url' => '/api/v1/files/uploads/'.$localPath,
                'type' => 'video',
                'mimeType' => 'video/mp4',
            ],
            'provider' => $provider,
            'model' => $model,
            'elapsed_seconds' => $elapsed,
        ];
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
        if (false === $ch) {
            $this->logger->error('Failed to initialize cURL', [
                'url' => FileHelper::redactUrlForLogging($url),
            ]);

            return null;
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::CURL_TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CURL_CONNECT_TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; PHP; Synaplan)');
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (false === $content || 200 !== $httpCode) {
            $this->logger->error('Media download failed', [
                'url' => FileHelper::redactUrlForLogging($url),
                'http_code' => $httpCode,
            ]);

            return null;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($content) ?: ('video' === $type ? 'video/mp4' : 'image/png');
        $extension = FileHelper::getExtensionFromMimeType($mimeType, 'video' === $type ? 'mp4' : 'png');

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

    private function recordUsage(
        User $user,
        string $type,
        ?string $provider,
        ?string $modelName,
        ?int $modelId = null,
        ?float $durationSeconds = null,
        ?string $resolution = null,
    ): void {
        $action = 'image' === $type ? 'IMAGES' : 'VIDEOS';

        if ('image' === $type) {
            $mediaUsage = ['images' => 1.0];
        } else {
            $mediaUsage = [
                'duration_seconds' => $durationSeconds ?? (float) self::DEFAULT_VIDEO_DURATION,
            ];
            if (null !== $resolution && '' !== $resolution) {
                $mediaUsage['resolution'] = $resolution;
            }
        }

        $this->rateLimitService->recordUsage($user, $action, [
            'provider' => $provider ?? 'unknown',
            'model' => $modelName ?? 'unknown',
            'model_id' => $modelId,
            'media_usage' => $mediaUsage,
        ]);
    }
}
