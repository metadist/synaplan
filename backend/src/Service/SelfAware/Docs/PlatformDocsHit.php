<?php

declare(strict_types=1);

namespace App\Service\SelfAware\Docs;

final readonly class PlatformDocsHit
{
    public function __construct(
        public string $slug,
        public string $title,
        public string $url,
        public string $section,
        public string $text,
        public float $score,
    ) {
    }

    /**
     * @return array{slug: string, title: string, url: string}
     */
    public function toClient(): array
    {
        return [
            'slug' => $this->slug,
            'title' => $this->title,
            'url' => $this->url,
        ];
    }
}
