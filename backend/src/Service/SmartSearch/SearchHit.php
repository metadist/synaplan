<?php

declare(strict_types=1);

namespace App\Service\SmartSearch;

/**
 * One Smart Search result as the palette renders it.
 *
 * @phpstan-type SettingAction array{type: 'toggle'|'select', key: string, current: string, options: list<string>, scope: 'system', envPinned: bool}
 */
final readonly class SearchHit
{
    public const MATCHED_LEXICAL = 'lexical';
    public const MATCHED_SEMANTIC = 'semantic';
    public const MATCHED_BOTH = 'both';

    /**
     * @param SettingAction|null $action inline control for a setting; null means "open the route"
     */
    public function __construct(
        public string $kind,
        public string $refId,
        public string $title,
        public string $route,
        public string $matchedBy = self::MATCHED_LEXICAL,
        public ?string $subtitle = null,
        public ?string $snippet = null,
        public float $score = 0.0,
        public ?array $action = null,
    ) {
    }

    public function id(): string
    {
        return $this->kind.':'.$this->refId;
    }

    public function with(float $score, string $matchedBy): self
    {
        return new self($this->kind, $this->refId, $this->title, $this->route, $matchedBy, $this->subtitle, $this->snippet, $score, $this->action);
    }

    /**
     * @return array{id: string, kind: string, title: string, subtitle: ?string, snippet: ?string, route: string, score: float, matchedBy: string, action: SettingAction|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id(),
            'kind' => $this->kind,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'snippet' => $this->snippet,
            'route' => $this->route,
            'score' => round($this->score, 6),
            'matchedBy' => $this->matchedBy,
            'action' => $this->action,
        ];
    }
}
