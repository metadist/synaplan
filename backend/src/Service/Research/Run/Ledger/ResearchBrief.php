<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Ledger;

final readonly class ResearchBrief
{
    /**
     * @param list<string>                           $mustUse
     * @param list<string>                           $mustAvoid
     * @param array{from: ?string, to: ?string}|null $timeFrame
     */
    public function __construct(
        public string $goal,
        public string $language,
        public string $output,
        public ?string $audience = null,
        public ?array $timeFrame = null,
        public ?string $region = null,
        public array $mustUse = [],
        public array $mustAvoid = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'goal' => $this->goal,
            'language' => $this->language,
            'output' => $this->output,
            'audience' => $this->audience,
            'time_frame' => $this->timeFrame,
            'region' => $this->region,
            'must_use' => $this->mustUse,
            'must_avoid' => $this->mustAvoid,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $goal = PlainText::line((string) ($data['goal'] ?? ''), 4000);
        if ('' === $goal) {
            throw new \InvalidArgumentException('A research brief needs a goal.');
        }
        $output = PlainText::line((string) ($data['output'] ?? ''), 40);

        $frame = null;
        if (isset($data['time_frame']) && is_array($data['time_frame'])) {
            $frame = [
                'from' => self::optionalText($data['time_frame']['from'] ?? null, 40),
                'to' => self::optionalText($data['time_frame']['to'] ?? null, 40),
            ];
        }

        return new self(
            $goal,
            PlainText::line((string) ($data['language'] ?? ''), 16),
            '' === $output ? 'auto' : $output,
            self::optionalText($data['audience'] ?? null, 200),
            $frame,
            self::optionalText($data['region'] ?? null, 40),
            self::lines($data['must_use'] ?? []),
            self::lines($data['must_avoid'] ?? []),
        );
    }

    private static function optionalText(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $text = PlainText::line($value, $max);

        return '' === $text ? null : $text;
    }

    /**
     * @return list<string>
     */
    private static function lines(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $lines = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }
            $line = PlainText::line($item, 200);
            if ('' !== $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }
}
