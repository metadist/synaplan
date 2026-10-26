<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Source;

/**
 * What a source reader returns. RS6 replaces this stub with SourceText and
 * BlockedSource once the run types exist.
 */
final readonly class SourceRead
{
    public function __construct(
        public string $url,
        public bool $blocked,
        public string $text,
        public string $reason,
    ) {
    }

    public function trimmed(int $maxChars): self
    {
        $text = mb_substr($this->text, 0, max(0, $maxChars));
        if ($text === $this->text) {
            return $this;
        }

        return new self($this->url, $this->blocked, $text, $this->reason);
    }
}
