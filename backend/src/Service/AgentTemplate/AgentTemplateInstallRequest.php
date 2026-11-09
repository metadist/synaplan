<?php

declare(strict_types=1);

namespace App\Service\AgentTemplate;

final readonly class AgentTemplateInstallRequest
{
    /**
     * @param list<string> $tryIt
     */
    public function __construct(
        public string $audience,
        public ?string $title,
        public ?string $description,
        public array $tryIt,
    ) {
    }

    public static function fromBody(string $json): self
    {
        if ('' === trim($json)) {
            return new self('me', null, null, []);
        }
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('The install request is not valid JSON.');
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new \InvalidArgumentException('The install request must be an object.');
        }
        $audience = $data['audience'] ?? 'me';
        if (!is_string($audience) || !in_array($audience, ['me', 'everyone'], true)) {
            throw new \InvalidArgumentException('audience must be me or everyone.');
        }

        return new self(
            $audience,
            self::optionalText($data['title'] ?? null, 'title', 128),
            self::optionalText($data['description'] ?? null, 'description', 2000),
            self::tryIt($data['tryIt'] ?? []),
        );
    }

    private static function optionalText(mixed $value, string $field, int $max): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException($field.' must be a string.');
        }
        $text = trim($value);
        if (mb_strlen($text) > $max) {
            throw new \InvalidArgumentException($field.' is too long.');
        }

        return '' === $text ? null : $text;
    }

    /**
     * @return list<string>
     */
    private static function tryIt(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException('tryIt must be a list of sentences.');
        }
        if (count($value) > 4) {
            throw new \InvalidArgumentException('tryIt accepts at most 4 sentences.');
        }
        $out = [];
        foreach ($value as $sentence) {
            if (!is_string($sentence) || '' === trim($sentence) || mb_strlen(trim($sentence)) > 400) {
                throw new \InvalidArgumentException('Each example must be a sentence of at most 400 characters.');
            }
            $out[] = trim($sentence);
        }

        return $out;
    }
}
