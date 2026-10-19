<?php

declare(strict_types=1);

namespace App\Service\Api;

/**
 * Parsed POST /v1/embeddings body (OpenAI shape). Token-id arrays are not
 * accepted; every input is a non-empty string.
 */
final readonly class OpenAiEmbeddingsRequest
{
    public const MAX_INPUTS = 2048;
    public const MAX_INPUT_CHARS = 100_000;
    public const MAX_TOTAL_CHARS = 2_000_000;
    public const MAX_DIMENSIONS = 8192;

    /**
     * @param list<string> $inputs
     */
    public function __construct(
        public array $inputs,
        public ?string $model,
        public string $encodingFormat,
        public ?int $dimensions,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromBody(array $body): self
    {
        $raw = $body['input'] ?? null;
        $inputs = \is_string($raw) ? [$raw] : $raw;
        if (!\is_array($inputs) || [] === $inputs || !array_is_list($inputs)) {
            throw OpenAiGatewayException::invalid('input is required and must be a string or a non-empty array of strings', 'invalid_input');
        }
        if (\count($inputs) > self::MAX_INPUTS) {
            throw OpenAiGatewayException::invalid(sprintf('input may contain at most %d items', self::MAX_INPUTS), 'invalid_input');
        }

        $total = 0;
        $texts = [];
        foreach ($inputs as $i => $input) {
            if (!\is_string($input) || '' === trim($input)) {
                throw OpenAiGatewayException::invalid(sprintf('input[%d] must be a non-empty string', $i), 'invalid_input');
            }
            $length = mb_strlen($input);
            if ($length > self::MAX_INPUT_CHARS) {
                throw OpenAiGatewayException::invalid(sprintf('input[%d] is longer than %d characters', $i, self::MAX_INPUT_CHARS), 'invalid_input');
            }
            $total += $length;
            $texts[] = $input;
        }
        if ($total > self::MAX_TOTAL_CHARS) {
            throw OpenAiGatewayException::invalid(sprintf('input is longer than %d characters in total', self::MAX_TOTAL_CHARS), 'invalid_input');
        }

        $format = $body['encoding_format'] ?? 'float';
        if ('float' !== $format && 'base64' !== $format) {
            throw OpenAiGatewayException::invalid('encoding_format must be "float" or "base64"', 'invalid_encoding_format');
        }

        $dimensions = $body['dimensions'] ?? null;
        if (null !== $dimensions && (!\is_int($dimensions) || $dimensions < 1 || $dimensions > self::MAX_DIMENSIONS)) {
            throw OpenAiGatewayException::invalid(sprintf('dimensions must be an integer between 1 and %d', self::MAX_DIMENSIONS), 'invalid_dimensions');
        }

        return new self($texts, OpenAiGatewayModels::modelField($body), $format, $dimensions);
    }
}
