<?php

declare(strict_types=1);

namespace App\AI\Messages\Tools;

use App\AI\Service\AiFacade;
use App\Service\Security\SsrfGuard;
use App\Service\Vision\VisionModelResolver;
use Psr\Log\LoggerInterface;

/**
 * Synaplan's PIC2TEXT / vision models, exposed to the Messages gateway as a
 * callable tool so Claude Code can OCR or describe an image on demand without
 * replacing Anthropic's native multimodal path.
 */
final readonly class AnalyzeImageTool
{
    public const NAME = 'analyze_image';

    private const MAX_PROMPT_CHARS = 2000;
    private const MAX_BASE64_CHARS = 6_000_000;
    private const DEFAULT_PROMPT = 'Describe what you see in this image in detail. Extract any visible text.';
    private const TMP_SUBDIR = 'gateway-vision';

    private const MEDIA_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private AiFacade $aiFacade,
        private VisionModelResolver $visionModelResolver,
        private SsrfGuard $ssrfGuard,
        private LoggerInterface $logger,
        private string $uploadDir,
    ) {
    }

    public function isAvailable(?int $userId = null): bool
    {
        return $this->visionModelResolver->isAvailable($userId);
    }

    /**
     * @return array{name: string, description: string, input_schema: array<string, mixed>}
     */
    public function declaration(): array
    {
        return [
            'name' => self::NAME,
            'description' => 'Analyse an image with Synaplan\'s vision model: describe what is visible '
                .'and extract any text (OCR). Pass either a public HTTPS image URL or base64 image '
                .'bytes with a media_type. Use this when you need a dedicated OCR/describe pass; '
                .'images already in the conversation are still seen by vision-capable chat models.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'prompt' => [
                        'type' => 'string',
                        'description' => 'What to look for or extract. Defaults to a full describe + OCR pass.',
                    ],
                    'image_url' => [
                        'type' => 'string',
                        'description' => 'HTTPS URL of the image to analyse.',
                    ],
                    'image_base64' => [
                        'type' => 'string',
                        'description' => 'Raw base64 image bytes (no data: URI prefix). Requires media_type.',
                    ],
                    'media_type' => [
                        'type' => 'string',
                        'description' => 'MIME type of image_base64: image/jpeg, image/png, image/gif, or image/webp.',
                        'enum' => array_keys(self::MEDIA_EXTENSIONS),
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{text: string, isError: bool, summary: string}
     */
    public function execute(array $input, ?int $userId = null): array
    {
        $prompt = \is_string($input['prompt'] ?? null) ? trim($input['prompt']) : '';
        if ('' === $prompt) {
            $prompt = self::DEFAULT_PROMPT;
        } elseif (mb_strlen($prompt) > self::MAX_PROMPT_CHARS) {
            $prompt = mb_substr($prompt, 0, self::MAX_PROMPT_CHARS);
        }

        if (!$this->isAvailable($userId)) {
            return $this->error('No Synaplan vision model is configured on this instance.');
        }

        $relativePath = null;
        try {
            $relativePath = $this->materialiseImage($input);
            $result = $this->aiFacade->analyzeImage($relativePath, $prompt, $userId);
            $text = trim((string) ($result['content'] ?? ''));
            if ('' === $text) {
                return $this->error('Vision model returned an empty description.');
            }

            $this->logger->info('AnalyzeImageTool: analysis completed', [
                'user_id' => $userId,
                'provider' => $result['provider'] ?? null,
                'chars' => mb_strlen($text),
            ]);

            return [
                'text' => $text,
                'isError' => false,
                'summary' => 'image',
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('AnalyzeImageTool: analysis failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return $this->error('Image analysis failed: '.$e->getMessage());
        } finally {
            if (null !== $relativePath) {
                $full = rtrim($this->uploadDir, '/').'/'.ltrim($relativePath, '/');
                if (is_file($full)) {
                    @unlink($full);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws \InvalidArgumentException
     */
    private function materialiseImage(array $input): string
    {
        $base64 = $input['image_base64'] ?? null;
        $url = $input['image_url'] ?? null;

        if (\is_string($base64) && '' !== trim($base64)) {
            return $this->writeBase64(trim($base64), $input['media_type'] ?? null);
        }

        if (\is_string($url) && '' !== trim($url)) {
            return $this->downloadUrl(trim($url));
        }

        throw new \InvalidArgumentException('analyze_image requires `image_url` or `image_base64` (+ `media_type`).');
    }

    private function writeBase64(string $base64, mixed $mediaType): string
    {
        if (!\is_string($mediaType) || !isset(self::MEDIA_EXTENSIONS[strtolower($mediaType)])) {
            throw new \InvalidArgumentException('analyze_image requires a supported `media_type` with `image_base64`.');
        }

        $mediaType = strtolower($mediaType);
        if (str_starts_with($base64, 'data:')) {
            $comma = strpos($base64, ',');
            if (false === $comma) {
                throw new \InvalidArgumentException('Invalid data URI for image_base64.');
            }
            $base64 = substr($base64, $comma + 1);
        }

        if (strlen($base64) > self::MAX_BASE64_CHARS) {
            throw new \InvalidArgumentException('image_base64 is too large.');
        }

        $binary = base64_decode($base64, true);
        if (false === $binary || '' === $binary) {
            throw new \InvalidArgumentException('image_base64 is not valid base64.');
        }

        return $this->writeBytes($binary, self::MEDIA_EXTENSIONS[$mediaType]);
    }

    private function downloadUrl(string $url): string
    {
        if (!str_starts_with(strtolower($url), 'https://')) {
            throw new \InvalidArgumentException('image_url must be an HTTPS URL.');
        }
        if ($this->ssrfGuard->isBlockedUrl($url)) {
            throw new \InvalidArgumentException('image_url points at a blocked host.');
        }

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 20,
                'follow_location' => 0,
                'user_agent' => 'Synaplan-MessagesGateway/1.0',
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $binary = @file_get_contents($url, false, $ctx);
        if (false === $binary || '' === $binary) {
            throw new \InvalidArgumentException('Failed to download image_url.');
        }
        if (strlen($binary) > self::MAX_BASE64_CHARS) {
            throw new \InvalidArgumentException('Downloaded image is too large.');
        }

        $finfo = new \finfo(\FILEINFO_MIME_TYPE);
        $mime = strtolower((string) $finfo->buffer($binary));
        if (!isset(self::MEDIA_EXTENSIONS[$mime])) {
            throw new \InvalidArgumentException('Downloaded file is not a supported image type.');
        }

        return $this->writeBytes($binary, self::MEDIA_EXTENSIONS[$mime]);
    }

    private function writeBytes(string $binary, string $ext): string
    {
        $dir = rtrim($this->uploadDir, '/').'/'.self::TMP_SUBDIR;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create gateway vision temp directory.');
        }

        $relative = self::TMP_SUBDIR.'/'.bin2hex(random_bytes(16)).'.'.$ext;
        $full = rtrim($this->uploadDir, '/').'/'.$relative;
        if (false === file_put_contents($full, $binary)) {
            throw new \RuntimeException('Unable to write temporary image for analysis.');
        }

        return $relative;
    }

    /**
     * @return array{text: string, isError: true, summary: string}
     */
    private function error(string $message): array
    {
        return [
            'text' => $message,
            'isError' => true,
            'summary' => 'error',
        ];
    }
}
