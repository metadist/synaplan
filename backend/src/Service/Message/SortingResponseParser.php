<?php

declare(strict_types=1);

namespace App\Service\Message;

use Psr\Log\LoggerInterface;

/**
 * Parses AI sorter JSON including optional BSTEPS for multi-step plans.
 */
final readonly class SortingResponseParser
{
    public const MAX_STEPS = 5;

    /** @var list<string> */
    public const ALLOWED_CAPABILITIES = [
        'CHAT',
        'TEXT2PIC',
        'TEXT2VID',
        'TEXT2SOUND',
        'ANALYZE',
        'TEXT2DOC',
    ];

    /** @var list<string> */
    private const SUPPORTED_VIDEO_RESOLUTIONS = ['720p', '1080p', '4K'];

    private const RESOLUTION_ALIASES = [
        '720' => '720p',
        '720p' => '720p',
        'hd' => '720p',
        'readyhd' => '720p',
        '1080' => '1080p',
        '1080p' => '1080p',
        'fhd' => '1080p',
        'fullhd' => '1080p',
        '4k' => '4K',
        'uhd' => '4K',
        'ultrahd' => '4K',
        '2160' => '4K',
        '2160p' => '4K',
        '8k' => '4K',
        '5k' => '4K',
        '1440' => '1080p',
        '1440p' => '1080p',
        'qhd' => '1080p',
        '2k' => '1080p',
    ];

    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $originalData
     *
     * @return array{
     *     topic: string,
     *     language: string,
     *     web_search: bool,
     *     media_type: ?string,
     *     duration: ?int,
     *     resolution: ?string,
     *     input_mode: ?string,
     *     steps: ?list<array{
     *         id: string,
     *         capability: string,
     *         web_search?: bool,
     *         input_from?: string,
     *         label_key?: string,
     *         prompt_from?: string
     *     }>
     * }
     */
    public function parse(string $response, array $originalData): array
    {
        $response = trim($response);

        if (str_starts_with($response, '```')) {
            $response = (string) preg_replace('/^```(?:json)?\s*/', '', $response);
            $response = (string) preg_replace('/\s*```$/', '', $response);
            $response = trim($response);
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

            $webSearch = isset($data['BWEBSEARCH']) && (bool) $data['BWEBSEARCH'];

            $mediaType = null;
            if (isset($data['BMEDIA']) && is_string($data['BMEDIA'])) {
                $mediaType = $this->normalizeMediaType($data['BMEDIA']);
            }

            $duration = null;
            if (isset($data['BDURATION']) && is_numeric($data['BDURATION'])) {
                $duration = (int) $data['BDURATION'];
                if ($duration < 1 || $duration > 120) {
                    $duration = null;
                }
            }

            $inputMode = null;
            if (isset($data['BINPUTMODE']) && is_string($data['BINPUTMODE'])) {
                $inputMode = strtolower(trim($data['BINPUTMODE']));
                if (!in_array($inputMode, ['text_only', 'reference_images'], true)) {
                    $inputMode = null;
                }
            }

            $resolution = null;
            if (isset($data['BRESOLUTION']) && (is_string($data['BRESOLUTION']) || is_int($data['BRESOLUTION']))) {
                $resolution = $this->normalizeResolution((string) $data['BRESOLUTION']);
            }

            $steps = $this->parseSteps($data['BSTEPS'] ?? null);

            return [
                'topic' => (string) ($data['BTOPIC'] ?? $originalData['BTOPIC'] ?? 'general'),
                'language' => (string) ($data['BLANG'] ?? $originalData['BLANG'] ?? 'en'),
                'web_search' => $webSearch,
                'media_type' => $mediaType,
                'duration' => $duration,
                'resolution' => $resolution,
                'input_mode' => $inputMode,
                'steps' => $steps,
            ];
        } catch (\JsonException $e) {
            $this->logger->warning('SortingResponseParser: Failed to parse JSON response', [
                'error' => $e->getMessage(),
                'response' => substr($response, 0, 200),
            ]);

            return [
                'topic' => (string) ($originalData['BTOPIC'] ?? 'general'),
                'language' => (string) ($originalData['BLANG'] ?? 'en'),
                'web_search' => false,
                'media_type' => null,
                'duration' => null,
                'resolution' => null,
                'input_mode' => null,
                'steps' => null,
            ];
        }
    }

    /**
     * @return list<array{
     *     id: string,
     *     capability: string,
     *     web_search?: bool,
     *     input_from?: string,
     *     label_key?: string,
     *     prompt_from?: string
     * }>|null
     */
    private function parseSteps(mixed $rawSteps): ?array
    {
        if (!is_array($rawSteps) || [] === $rawSteps) {
            return null;
        }

        $parsed = [];
        foreach ($rawSteps as $index => $rawStep) {
            if (!is_array($rawStep)) {
                $this->logger->warning('SortingResponseParser: Skipping invalid BSTEPS entry', [
                    'index' => $index,
                ]);
                continue;
            }

            $id = isset($rawStep['id']) && is_string($rawStep['id']) ? trim($rawStep['id']) : '';
            $capability = isset($rawStep['capability']) && is_string($rawStep['capability'])
                ? strtoupper(trim($rawStep['capability']))
                : '';

            if ('' === $id || '' === $capability) {
                $this->logger->warning('SortingResponseParser: BSTEPS entry missing id or capability', [
                    'index' => $index,
                ]);
                continue;
            }

            if (!in_array($capability, self::ALLOWED_CAPABILITIES, true)) {
                $this->logger->warning('SortingResponseParser: Unknown BSTEPS capability', [
                    'capability' => $capability,
                    'index' => $index,
                ]);
                continue;
            }

            $step = [
                'id' => $id,
                'capability' => $capability,
            ];

            if (isset($rawStep['web_search'])) {
                $step['web_search'] = (bool) $rawStep['web_search'];
            }

            if (isset($rawStep['input_from']) && is_string($rawStep['input_from']) && '' !== trim($rawStep['input_from'])) {
                $step['input_from'] = trim($rawStep['input_from']);
            }

            if (isset($rawStep['label_key']) && is_string($rawStep['label_key']) && '' !== trim($rawStep['label_key'])) {
                $step['label_key'] = trim($rawStep['label_key']);
            }

            if (isset($rawStep['prompt_from']) && is_string($rawStep['prompt_from']) && '' !== trim($rawStep['prompt_from'])) {
                $step['prompt_from'] = trim($rawStep['prompt_from']);
            }

            $parsed[] = $step;

            if (count($parsed) >= self::MAX_STEPS) {
                $this->logger->warning('SortingResponseParser: BSTEPS truncated to max steps', [
                    'max' => self::MAX_STEPS,
                ]);
                break;
            }
        }

        return [] !== $parsed ? $parsed : null;
    }

    private function normalizeMediaType(string $value): ?string
    {
        $value = strtolower(trim($value));

        return match ($value) {
            'audio', 'sound', 'tts', 'speech', 'voice', 'text2sound', 'mp3', 'wav', 'ogg' => 'audio',
            'video', 'vid', 'clip', 'film', 'animation', 'text2vid' => 'video',
            'image', 'img', 'picture', 'pic', 'photo', 'text2pic' => 'image',
            default => null,
        };
    }

    private function normalizeResolution(string $raw): ?string
    {
        $value = trim($raw);
        if ('' === $value) {
            return null;
        }

        if (in_array($value, self::SUPPORTED_VIDEO_RESOLUTIONS, true)) {
            return $value;
        }

        $key = preg_replace('/[\s\-_]+/', '', strtolower($value));
        if (!is_string($key) || '' === $key) {
            return null;
        }

        return self::RESOLUTION_ALIASES[$key] ?? null;
    }
}
