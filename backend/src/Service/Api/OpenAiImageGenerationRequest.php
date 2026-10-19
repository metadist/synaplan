<?php

declare(strict_types=1);

namespace App\Service\Api;

/**
 * Parsed POST /v1/images/generations body (OpenAI shape).
 */
final readonly class OpenAiImageGenerationRequest
{
    public const MAX_PROMPT_CHARS = 32_000;
    public const MAX_IMAGES = 4;
    public const DEFAULT_SIZE = '1024x1024';

    private const QUALITIES = ['auto', 'standard', 'hd', 'low', 'medium', 'high'];

    public function __construct(
        public string $prompt,
        public ?string $model,
        public int $n,
        public string $size,
        public ?string $quality,
        public string $responseFormat,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromBody(array $body): self
    {
        $prompt = $body['prompt'] ?? null;
        if (!\is_string($prompt) || '' === trim($prompt)) {
            throw OpenAiGatewayException::invalid('prompt is required and must be a non-empty string', 'invalid_prompt');
        }
        if (mb_strlen($prompt) > self::MAX_PROMPT_CHARS) {
            throw OpenAiGatewayException::invalid(sprintf('prompt is longer than %d characters', self::MAX_PROMPT_CHARS), 'invalid_prompt');
        }

        $n = $body['n'] ?? 1;
        if (!\is_int($n) || $n < 1 || $n > self::MAX_IMAGES) {
            throw OpenAiGatewayException::invalid(sprintf('n must be an integer between 1 and %d', self::MAX_IMAGES), 'invalid_n');
        }

        $size = $body['size'] ?? self::DEFAULT_SIZE;
        if (!\is_string($size) || 1 !== preg_match('/^(auto|\d{3,4}x\d{3,4})$/', $size)) {
            throw OpenAiGatewayException::invalid('size must be "auto" or WIDTHxHEIGHT, e.g. "1024x1024"', 'invalid_size');
        }

        $quality = $body['quality'] ?? null;
        if (null !== $quality && (!\is_string($quality) || !\in_array($quality, self::QUALITIES, true))) {
            throw OpenAiGatewayException::invalid('quality must be one of: '.implode(', ', self::QUALITIES), 'invalid_quality');
        }

        $format = $body['response_format'] ?? 'url';
        if ('url' !== $format && 'b64_json' !== $format) {
            throw OpenAiGatewayException::invalid('response_format must be "url" or "b64_json"', 'invalid_response_format');
        }

        return new self($prompt, OpenAiGatewayModels::modelField($body), $n, $size, $quality, $format);
    }
}
