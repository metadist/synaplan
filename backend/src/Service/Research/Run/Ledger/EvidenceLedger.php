<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Ledger;

/**
 * What a run has found. Ids stay stable for the run (`s*`, `e*`, `p*`).
 * A source that could not be read stays, with the reason, so the report can
 * say so instead of guessing. Two disagreeing facts are both kept.
 */
final class EvidenceLedger
{
    private const MAX_POINT_QUERIES = 12;

    /** @var array<string, Source> */
    private array $sources = [];

    /** @var array<string, string> */
    private array $byKey = [];

    /** @var array<string, EvidenceCard> */
    private array $evidence = [];

    /** @var array<string, PlanPoint> */
    private array $points = [];

    /** @var list<SourceLead> */
    private array $leads = [];

    /** @var list<SearchQuery> */
    private array $queries = [];

    /** @var list<string> */
    private array $outline = [];

    /** @var array<string, string> */
    private array $models = [];

    private ?ResearchBrief $brief = null;

    private ?string $report = null;

    /** @var array{statements: int, supported: int, removed: int}|null */
    private ?array $check = null;

    private int $nextSource = 1;

    private int $nextEvidence = 1;

    private int $nextPoint = 1;

    public function setBrief(ResearchBrief $brief): void
    {
        $this->brief = $brief;
    }

    public function brief(): ?ResearchBrief
    {
        return $this->brief;
    }

    /**
     * @param list<string> $sections
     */
    public function setOutline(array $sections): void
    {
        $outline = [];
        foreach ($sections as $section) {
            $line = PlainText::line($section, 200);
            if ('' !== $line) {
                $outline[] = $line;
            }
        }
        $this->outline = $outline;
    }

    /**
     * @param list<array{q?: string, language?: string, scope?: string}> $queries
     */
    public function addPoint(string $title, string $why = '', array $queries = []): string
    {
        $id = 'p'.$this->nextPoint;
        ++$this->nextPoint;
        $this->points[$id] = new PlanPoint($id, PlainText::line($title, 300), PlanPoint::OPEN, PlainText::line($why, 300), $this->queriesOf($queries));

        return $id;
    }

    /**
     * @return list<PlanPoint>
     */
    public function points(): array
    {
        return array_values($this->points);
    }

    /**
     * @return list<string>
     */
    public function outline(): array
    {
        return $this->outline;
    }

    public function source(string $id): ?Source
    {
        return $this->sources[$id] ?? null;
    }

    /**
     * @return list<Source>
     */
    public function sources(): array
    {
        return array_values($this->sources);
    }

    public function report(): ?string
    {
        return $this->report;
    }

    public function setReport(string $markdown): void
    {
        $text = trim($markdown);
        $this->report = '' === $text ? null : $text;
    }

    public function setCheck(int $statements, int $supported, int $removed): void
    {
        $this->check = [
            'statements' => max(0, $statements),
            'supported' => max(0, $supported),
            'removed' => max(0, $removed),
        ];
    }

    /**
     * @return array{statements: int, supported: int, removed: int}|null
     */
    public function check(): ?array
    {
        return $this->check;
    }

    /**
     * @param list<array{q?: string, language?: string, scope?: string}> $queries replace the planned queries
     */
    public function renamePoint(string $id, string $title, array $queries): void
    {
        $point = $this->points[$id] ?? null;
        $title = PlainText::line($title, 300);
        if (null === $point || '' === $title) {
            throw new \InvalidArgumentException('That plan point is not in this research.');
        }
        $this->points[$id] = new PlanPoint($id, $title, $point->status, $point->why, $this->queriesOf($queries));
    }

    public function dropPoint(string $id): void
    {
        unset($this->points[$id]);
    }

    public function markPoint(string $id, string $status, string $note = ''): void
    {
        $point = $this->points[$id] ?? null;
        if (null === $point) {
            throw new \InvalidArgumentException('That plan point is not in this research.');
        }
        if (!in_array($status, PlanPoint::STATUSES, true)) {
            throw new \InvalidArgumentException('A plan point status is not recognised.');
        }
        $why = '' !== trim($note) ? PlainText::line($note, 300) : $point->why;
        $this->points[$id] = new PlanPoint($id, $point->title, $status, $why, $point->queries);
    }

    /**
     * A search the lead asked for after the plan. The next round runs it
     * unless that exact search was already run.
     */
    public function planSearch(string $pointId, string $query, string $language, string $scope): bool
    {
        $point = $this->points[$pointId] ?? null;
        if (null === $point || count($point->queries) >= self::MAX_POINT_QUERIES) {
            return false;
        }
        $text = PlainText::line($query, 200);
        if ('' === $text || !in_array($scope, ['web', 'both'], true)) {
            return false;
        }
        $queries = $point->queries;
        $queries[] = [
            'q' => $text,
            'language' => '' === $language ? 'en' : $language,
            'scope' => $scope,
        ];
        $this->points[$pointId] = new PlanPoint($point->id, $point->title, $point->status, $point->why, $queries);

        return true;
    }

    /** A planned web search that has not run yet, keyed like the gather step. */
    public function hasPlannedSearch(): bool
    {
        $ran = [];
        foreach ($this->queries as $query) {
            $ran[$query->pointId.'|'.$query->query] = true;
        }
        foreach ($this->points as $point) {
            foreach ($point->queries as $query) {
                if ('files' !== $query['scope'] && !isset($ran[$point->id.'|'.$query['q']])) {
                    return true;
                }
            }
        }

        return false;
    }

    public function hasEvidenceFor(string $pointId): bool
    {
        foreach ($this->evidence as $card) {
            if ($card->pointId === $pointId) {
                return true;
            }
        }

        return false;
    }

    public function evidenceCount(): int
    {
        return count($this->evidence);
    }

    public function describeSource(string $id, ?string $sourceClass, ?string $published): void
    {
        $source = $this->sources[$id] ?? null;
        if (null === $source) {
            throw new \InvalidArgumentException('That source is not in this research.');
        }
        $this->sources[$id] = $source->described($sourceClass, $published);
    }

    public function addSource(Source $source): string
    {
        $key = $this->dedupeKey($source);
        if (null !== $key && isset($this->byKey[$key])) {
            return $this->byKey[$key];
        }

        $id = 's'.$this->nextSource;
        ++$this->nextSource;
        $stored = new Source(
            $id,
            $source->kind,
            $source->title,
            $source->status,
            $source->url,
            $source->fileId,
            $source->host,
            $source->details,
        );
        $this->sources[$id] = $stored;
        if (null !== $key) {
            $this->byKey[$key] = $id;
        }

        return $id;
    }

    public function markSource(string $id, string $status, ?string $reason = null, ?string $readBy = null): void
    {
        $source = $this->sources[$id] ?? null;
        if (null === $source) {
            throw new \InvalidArgumentException('That source is not in this research.');
        }
        if (!in_array($status, Source::STATUSES, true)) {
            throw new \InvalidArgumentException('A source status is not recognised.');
        }
        $this->sources[$id] = $source->withStatus($status, $reason, $readBy);
    }

    public function addEvidence(EvidenceCard $card): string
    {
        if (!isset($this->sources[$card->sourceId])) {
            throw new \InvalidArgumentException('Evidence needs a source that is already in the ledger.');
        }
        if (!isset($this->points[$card->pointId])) {
            throw new \InvalidArgumentException('Evidence needs a plan point that is already in the ledger.');
        }

        $id = 'e'.$this->nextEvidence;
        ++$this->nextEvidence;
        $confidence = in_array($card->confidence, ['high', 'medium', 'low'], true) ? $card->confidence : 'medium';
        $this->evidence[$id] = new EvidenceCard(
            $id,
            $card->sourceId,
            $card->pointId,
            PlainText::line($card->fact, 2000),
            $card->passages,
            $card->quotes,
            $confidence,
        );

        return $id;
    }

    public function addQuery(string $pointId, string $query, string $scope, int $results): void
    {
        $this->queries[] = new SearchQuery(
            $pointId,
            PlainText::line($query, 500),
            PlainText::line($scope, 40),
            max(0, $results),
        );
    }

    /**
     * Drops searches already recorded for these points, so a refresh can run them again.
     *
     * @param list<string> $pointIds
     */
    public function dropQueries(array $pointIds): void
    {
        if ([] === $pointIds) {
            return;
        }
        $drop = array_fill_keys($pointIds, true);
        $kept = [];
        foreach ($this->queries as $query) {
            if (!isset($drop[$query->pointId])) {
                $kept[] = $query;
            }
        }
        $this->queries = $kept;
    }

    public function addLead(string $sourceId, string $fromId, string $pointId, string $anchor): void
    {
        $this->leads[] = new SourceLead(
            $sourceId,
            $fromId,
            $pointId,
            PlainText::line($anchor, 300),
        );
    }

    public function setModel(string $role, string $catalogKey): void
    {
        if (!in_array($role, ['sorter', 'reader', 'lead', 'writer', 'checker'], true)) {
            throw new \InvalidArgumentException('Unknown research role.');
        }
        $this->models[$role] = PlainText::line($catalogKey, 120);
    }

    /**
     * Per point: the findings, the kind of source, the date, and a line when
     * two sources disagree. Titles are one line of data, never instructions.
     */
    public function summaryForLead(int $maxChars): string
    {
        if ($maxChars < 1) {
            return '';
        }

        $text = '';
        $truncated = false;
        foreach ($this->summaryBlocks() as $block) {
            $next = '' === $text ? $block : $text."\n".$block;
            if (mb_strlen($next) > $maxChars) {
                $truncated = true;
                break;
            }
            $text = $next;
        }
        if (!$truncated) {
            return $text;
        }
        if (mb_strlen($text) + 1 <= $maxChars) {
            return $text.'…';
        }

        return rtrim(mb_substr($text, 0, max(1, $maxChars - 1))).'…';
    }

    /**
     * Grouped by plan point. Only quotes that were found in their passage.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function evidenceForWriter(): array
    {
        $grouped = [];
        foreach ($this->evidence as $card) {
            $quotes = [];
            foreach ($card->quotes as $quote) {
                if (!$quote->verified) {
                    continue;
                }
                $quotes[] = $quote->toArray();
            }
            $grouped[$card->pointId][] = [
                'id' => $card->id,
                'source' => $card->sourceId,
                'fact' => $card->fact,
                'passages' => $card->passages,
                'quotes' => $quotes,
            ];
        }

        return $grouped;
    }

    /**
     * @return list<Source>
     */
    public function unreadSources(): array
    {
        $unread = [];
        foreach ($this->sources as $source) {
            if (Source::FOUND === $source->status || Source::READING === $source->status) {
                $unread[] = $source;
            }
        }

        return $unread;
    }

    /**
     * @return list<SearchQuery>
     */
    public function queries(): array
    {
        return $this->queries;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'brief' => $this->brief?->toArray(),
            'plan' => [
                'points' => array_map(static fn (PlanPoint $point): array => $point->toArray(), array_values($this->points)),
                'outline' => $this->outline,
            ],
            'sources' => array_map(static fn (Source $source): array => $source->toArray(), array_values($this->sources)),
            'evidence' => array_map(static fn (EvidenceCard $card): array => $card->toArray(), array_values($this->evidence)),
            'leads' => array_map(static fn (SourceLead $lead): array => $lead->toArray(), $this->leads),
            'queries' => array_map(static fn (SearchQuery $query): array => $query->toArray(), $this->queries),
            'models' => $this->models,
            'report' => $this->report,
            'check' => $this->check,
            'next' => [
                'source' => $this->nextSource,
                'evidence' => $this->nextEvidence,
                'point' => $this->nextPoint,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $ledger = new self();
        if (isset($data['brief']) && is_array($data['brief'])) {
            $ledger->brief = ResearchBrief::fromArray($data['brief']);
        }
        $ledger->restorePlan($data['plan'] ?? []);
        $ledger->restoreSources($data['sources'] ?? []);
        $ledger->restoreEvidence($data['evidence'] ?? []);
        $ledger->restoreLeads($data['leads'] ?? []);
        $ledger->restoreQueries($data['queries'] ?? []);
        $ledger->restoreModels($data['models'] ?? []);
        $ledger->restoreCounters($data['next'] ?? []);
        if (is_string($data['report'] ?? null) && '' !== trim($data['report'])) {
            $ledger->report = trim($data['report']);
        }
        if (is_array($data['check'] ?? null)) {
            $ledger->setCheck(
                (int) ($data['check']['statements'] ?? 0),
                (int) ($data['check']['supported'] ?? 0),
                (int) ($data['check']['removed'] ?? 0),
            );
        }

        return $ledger;
    }

    /**
     * @return list<string>
     */
    private function summaryBlocks(): array
    {
        $blocks = [];
        if (null !== $this->brief) {
            $blocks[] = 'Goal: '.$this->brief->goal;
        }
        foreach ($this->points as $point) {
            $blocks[] = 'Point '.$point->id.' ('.$point->status.'): '.$point->title;
            foreach ($this->evidence as $card) {
                if ($card->pointId !== $point->id) {
                    continue;
                }
                $blocks[] = '- '.$this->findingLine($card);
            }
            if ($this->disagrees($point->id)) {
                $blocks[] = 'These sources disagree.';
            }
        }

        return $blocks;
    }

    private function findingLine(EvidenceCard $card): string
    {
        $source = $this->sources[$card->sourceId] ?? null;
        $where = $card->sourceId;
        if (null !== $source) {
            $where .= ' '.$source->kind;
            if (null !== $source->host) {
                $where .= ' '.$source->host;
            }
            if (null !== $source->details->published) {
                $where .= ' '.$source->details->published;
            }
        }

        return $where.': '.$card->fact;
    }

    private function disagrees(string $pointId): bool
    {
        $facts = [];
        foreach ($this->evidence as $card) {
            if ($card->pointId === $pointId) {
                $facts[mb_strtolower($card->fact)] = true;
            }
        }

        return count($facts) > 1;
    }

    private function dedupeKey(Source $source): ?string
    {
        if (Source::WEB === $source->kind) {
            return null === $source->url ? null : 'url:'.$source->url;
        }
        if (Source::FILE === $source->kind && null !== $source->fileId) {
            return 'file:'.$source->fileId;
        }
        if (null !== $source->url && '' !== $source->url) {
            return 'url:'.$source->url;
        }

        return null;
    }

    private function restorePlan(mixed $plan): void
    {
        if (!is_array($plan)) {
            return;
        }
        $points = $plan['points'] ?? [];
        if (is_array($points)) {
            foreach ($points as $point) {
                if (!is_array($point)) {
                    continue;
                }
                $id = (string) ($point['id'] ?? '');
                $status = (string) ($point['status'] ?? PlanPoint::OPEN);
                if (!in_array($status, PlanPoint::STATUSES, true)) {
                    $status = PlanPoint::OPEN;
                }
                $queries = $point['queries'] ?? [];
                $this->points[$id] = new PlanPoint(
                    $id,
                    PlainText::line((string) ($point['title'] ?? ''), 300),
                    $status,
                    PlainText::line((string) ($point['why'] ?? ''), 300),
                    $this->queriesOf(is_array($queries) ? $queries : [], self::MAX_POINT_QUERIES),
                );
            }
        }
        $outline = $plan['outline'] ?? [];
        if (is_array($outline)) {
            $this->setOutline(array_values(array_filter($outline, 'is_string')));
        }
    }

    private function restoreSources(mixed $rows): void
    {
        if (!is_array($rows)) {
            return;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $source = Source::fromArray($row);
            if ('' === $source->id || isset($this->sources[$source->id])) {
                continue;
            }
            $this->sources[$source->id] = $source;
            $key = $this->dedupeKey($source);
            if (null !== $key && !isset($this->byKey[$key])) {
                $this->byKey[$key] = $source->id;
            }
        }
    }

    private function restoreEvidence(mixed $rows): void
    {
        if (!is_array($rows)) {
            return;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $card = $this->cardFrom($row);
            if (null !== $card) {
                $this->evidence[$card->id] = $card;
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function cardFrom(array $row): ?EvidenceCard
    {
        $id = (string) ($row['id'] ?? '');
        if ('' === $id) {
            return null;
        }
        $passages = [];
        foreach (is_array($row['passages'] ?? null) ? $row['passages'] : [] as $passage) {
            if (is_string($passage)) {
                $passages[] = $passage;
            }
        }

        return new EvidenceCard(
            $id,
            (string) ($row['source'] ?? ''),
            (string) ($row['point'] ?? ''),
            PlainText::line((string) ($row['fact'] ?? ''), 2000),
            $passages,
            $this->quotesFrom($row['quotes'] ?? []),
            (string) ($row['confidence'] ?? 'medium'),
        );
    }

    /**
     * @return list<QuoteMatch>
     */
    private function quotesFrom(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }
        $quotes = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['text'] ?? null) || !is_string($row['passage'] ?? null)) {
                continue;
            }
            $quotes[] = new QuoteMatch($row['text'], $row['passage'], true === ($row['verified'] ?? false));
        }

        return $quotes;
    }

    private function restoreLeads(mixed $rows): void
    {
        if (!is_array($rows)) {
            return;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $this->leads[] = new SourceLead(
                (string) ($row['source'] ?? ''),
                (string) ($row['from'] ?? ''),
                (string) ($row['point'] ?? ''),
                PlainText::line((string) ($row['anchor'] ?? ''), 300),
            );
        }
    }

    private function restoreQueries(mixed $rows): void
    {
        if (!is_array($rows)) {
            return;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $this->queries[] = new SearchQuery(
                (string) ($row['point'] ?? ''),
                PlainText::line((string) ($row['q'] ?? ''), 500),
                PlainText::line((string) ($row['scope'] ?? ''), 40),
                max(0, (int) ($row['results'] ?? 0)),
            );
        }
    }

    private function restoreModels(mixed $models): void
    {
        if (!is_array($models)) {
            return;
        }
        foreach ($models as $role => $key) {
            if (!is_string($role) || !is_string($key)) {
                continue;
            }
            if (!in_array($role, ['sorter', 'reader', 'lead', 'writer', 'checker'], true)) {
                continue;
            }
            $this->models[$role] = PlainText::line($key, 120);
        }
    }

    private function restoreCounters(mixed $next): void
    {
        $source = 1;
        $evidence = 1;
        $point = 1;
        if (is_array($next)) {
            $source = max(1, (int) ($next['source'] ?? 1));
            $evidence = max(1, (int) ($next['evidence'] ?? 1));
            $point = max(1, (int) ($next['point'] ?? 1));
        }
        $this->nextSource = max($source, $this->highest($this->sources, 's') + 1);
        $this->nextEvidence = max($evidence, $this->highest($this->evidence, 'e') + 1);
        $this->nextPoint = max($point, $this->highest($this->points, 'p') + 1);
    }

    /**
     * @param array<string, mixed> $rows
     */
    private function highest(array $rows, string $prefix): int
    {
        $highest = 0;
        foreach (array_keys($rows) as $id) {
            if (str_starts_with($id, $prefix)) {
                $highest = max($highest, (int) substr($id, strlen($prefix)));
            }
        }

        return $highest;
    }

    /**
     * @param array<mixed> $queries
     *
     * @return list<array{q: string, language: string, scope: string}>
     */
    private function queriesOf(array $queries, int $max = 4): array
    {
        $clean = [];
        foreach ($queries as $query) {
            if (!is_array($query)) {
                continue;
            }
            $text = PlainText::line((string) ($query['q'] ?? ''), 200);
            if ('' === $text) {
                continue;
            }
            $language = strtolower(PlainText::line((string) ($query['language'] ?? ''), 8));
            $scope = (string) ($query['scope'] ?? 'web');
            if (!in_array($scope, ['web', 'files', 'both'], true)) {
                $scope = 'web';
            }
            $clean[] = [
                'q' => $text,
                'language' => '' === $language ? 'en' : $language,
                'scope' => $scope,
            ];
            if ($max === count($clean)) {
                break;
            }
        }

        return $clean;
    }
}
