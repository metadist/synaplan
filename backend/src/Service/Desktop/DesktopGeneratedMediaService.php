<?php

declare(strict_types=1);

namespace App\Service\Desktop;

use App\AI\Service\AiFacade;
use App\Entity\File;
use App\Entity\Model;
use App\Entity\User;
use App\Model\ModelCatalog;
use App\Service\Exception\NoModelAvailableException;
use App\Service\Exception\RateLimitExceededException;
use App\Service\MediaGenerationServiceInterface;
use App\Service\RateLimitService;
use App\Service\TtsTextSanitizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Desktop machine API for generated media. Resolves a catalog key to a BID so
 * the project's IMAGE / VIDEO / SPEAK binding is what actually runs — never
 * a silent account default.
 */
final readonly class DesktopGeneratedMediaService
{
    public function __construct(
        private MediaGenerationServiceInterface $media,
        private AiFacade $aiFacade,
        private RateLimitService $rateLimitService,
        private EntityManagerInterface $em,
        private string $uploadDir,
    ) {
    }

    /**
     * @return array{success: true, file: array{url: string, type: string, mimeType: string, id: int|null}, provider: string, model: string, resolution?: string}
     */
    public function generate(User $user, string $prompt, string $type, string $catalogKey): array
    {
        $type = strtolower(trim($type));
        if (!\in_array($type, ['image', 'video'], true)) {
            throw new \InvalidArgumentException('Type must be "image" or "video"');
        }

        $modelId = $this->requireModelId($catalogKey);
        $result = $this->media->generate($user, $prompt, $type, $modelId);
        $result['file']['id'] = $this->fileIdFromUrl($result['file']['url']);

        return $result;
    }

    /**
     * @return array{success: true, file: array{url: string, type: string, mimeType: string, id: int|null}, provider: string, model: string}
     */
    public function speak(User $user, string $text, string $catalogKey): array
    {
        $text = TtsTextSanitizer::prepareForSynthesis($text);
        if ('' === trim($text)) {
            throw new \InvalidArgumentException('No speakable text provided');
        }

        $this->checkAudioRateLimit($user);

        $modelId = $this->requireModelId($catalogKey);
        $model = $this->em->getRepository(Model::class)->find($modelId);
        if (!$model instanceof Model) {
            throw new NoModelAvailableException('Model not found: '.$modelId);
        }

        $result = $this->aiFacade->synthesize($text, $user->getId(), [
            'provider' => strtolower($model->getService()),
            'model' => $model->getProviderId() ?: $model->getName(),
        ]);

        $relativePath = (string) ($result['relativePath'] ?? '');
        if ('' === $relativePath) {
            throw new \RuntimeException('TTS returned no file');
        }

        $mimeType = $this->mimeFromPath($relativePath);
        $fileId = $this->registerGeneratedFile($user, $relativePath, $mimeType);

        return [
            'success' => true,
            'file' => [
                'url' => '/api/v1/files/uploads/'.$relativePath,
                'type' => 'audio',
                'mimeType' => $mimeType,
                'id' => $fileId,
            ],
            'provider' => (string) ($result['provider'] ?? $model->getService()),
            'model' => (string) ($result['model'] ?? $model->getProviderId()),
        ];
    }

    public function resolveModelId(string $catalogKey): ?int
    {
        $key = strtolower(trim($catalogKey));
        if ('' === $key) {
            return null;
        }

        return ModelCatalog::findBidByKey($key);
    }

    private function requireModelId(string $catalogKey): int
    {
        $key = trim($catalogKey);
        if ('' === $key) {
            throw new \InvalidArgumentException('model is required');
        }

        $id = $this->resolveModelId($key);
        if (null === $id) {
            throw new \InvalidArgumentException('Unknown model: '.$key);
        }

        return $id;
    }

    private function checkAudioRateLimit(User $user): void
    {
        $check = $this->rateLimitService->checkLimit($user, 'AUDIOS');
        if (!$check['allowed']) {
            throw new RateLimitExceededException('AUDIOS', (int) $check['used'], (int) $check['limit']);
        }
    }

    private function fileIdFromUrl(string $url): ?int
    {
        $prefix = '/api/v1/files/uploads/';
        if (!str_starts_with($url, $prefix)) {
            return null;
        }

        $path = substr($url, \strlen($prefix));
        $file = $this->em->getRepository(File::class)->findOneBy(['filePath' => $path]);

        return $file?->getId();
    }

    private function registerGeneratedFile(User $user, string $relativePath, string $mimeType): ?int
    {
        $existing = $this->em->getRepository(File::class)->findOneBy(['filePath' => $relativePath]);
        if ($existing instanceof File) {
            return $existing->getId();
        }

        $absolutePath = $this->uploadDir.'/'.$relativePath;
        $fileSize = is_file($absolutePath) ? (int) filesize($absolutePath) : 0;
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

        $file = new File();
        $file->setUserId($user->getId());
        $file->setFilePath($relativePath);
        $file->setFileType($extension);
        $file->setFileName(basename($relativePath));
        $file->setFileSize($fileSize);
        $file->setFileMime($mimeType);
        $file->setStatus('generated');
        $file->setSource('generated');

        $this->em->persist($file);
        $this->em->flush();

        return $file->getId();
    }

    private function mimeFromPath(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'm4a' => 'audio/mp4',
            'flac' => 'audio/flac',
            'opus' => 'audio/opus',
            default => 'audio/mpeg',
        };
    }
}
