<?php

declare(strict_types=1);

namespace App\Service\Document\Model;

use App\Service\Document\DocumentKind;
use App\Service\File\Presentation\PptxTheme;
use App\Service\File\Presentation\SlideContent;
use App\Service\File\Presentation\SlideDeck;
use App\Service\File\Presentation\SlideTransitionKind;

/**
 * Presentation model that reuses the existing SlideDeck structures.
 */
final class DeckModel
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param list<array<string, mixed>> $slides
     */
    public function __construct(
        public array $slides = [],
        public string $theme = 'Default',
        public string $transition = 'None',
    ) {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function kind(): string
    {
        return DocumentKind::PPTX;
    }

    public function toSlideDeck(): SlideDeck
    {
        $slides = [];
        foreach ($this->slides as $row) {
            $slides[] = new SlideContent(
                isset($row['title']) && is_string($row['title']) ? $row['title'] : null,
                isset($row['bullets']) && is_array($row['bullets']) ? $this->normalizeBullets($row['bullets']) : [],
                isset($row['imageReferences']) && is_array($row['imageReferences'])
                    ? array_values(array_filter($row['imageReferences'], 'is_string'))
                    : [],
                [],
                (bool) ($row['titleSlide'] ?? false),
            );
        }

        $theme = PptxTheme::fromName($this->theme);
        $transition = SlideTransitionKind::fromName($this->transition);

        return new SlideDeck($slides, $theme, $transition);
    }

    /**
     * @param list<mixed> $bullets
     *
     * @return list<\App\Service\File\Presentation\SlideBullet>
     */
    private function normalizeBullets(array $bullets): array
    {
        $out = [];
        foreach ($bullets as $bullet) {
            if (is_string($bullet)) {
                $out[] = new \App\Service\File\Presentation\SlideBullet([
                    new \App\Service\File\Presentation\SlideTextRun($bullet),
                ]);
            } elseif (is_array($bullet) && isset($bullet['text']) && is_string($bullet['text'])) {
                $out[] = new \App\Service\File\Presentation\SlideBullet([
                    new \App\Service\File\Presentation\SlideTextRun($bullet['text']),
                ]);
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'kind' => DocumentKind::PPTX,
            'theme' => $this->theme,
            'transition' => $this->transition,
            'slides' => $this->slides,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $slides = [];
        if (isset($data['slides']) && is_array($data['slides'])) {
            foreach ($data['slides'] as $slide) {
                if (is_array($slide)) {
                    $slides[] = $slide;
                }
            }
        }

        return new self(
            $slides,
            is_string($data['theme'] ?? null) ? $data['theme'] : 'Default',
            is_string($data['transition'] ?? null) ? $data['transition'] : 'None',
        );
    }
}
