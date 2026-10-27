<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Service\Research\Run\Ledger\EvidenceLedger;
use App\Service\Research\Run\Ledger\ResearchBrief;
use App\Service\Research\Run\Ledger\Source;
use App\Service\Research\Run\Stage\CheckReport;
use App\Service\Research\Run\Stage\DraftSentences;

/**
 * A follow-up is a new run. It keeps the parent's sources, evidence and
 * point numbers, and it stays inside the parent's world. The parent's
 * report is kept apart as the prior report, so the new run never shows or
 * delivers the parent's text as its own.
 */
final readonly class ResearchFollowUp
{
    public const QUESTION = 'question';
    public const REVISE = 'revise';
    public const DEEPER = 'deeper';
    public const REFRESH = 'refresh';

    /** @var list<string> */
    public const KINDS = [self::QUESTION, self::REVISE, self::DEEPER, self::REFRESH];

    /** Plan points a deeper look takes from Not confirmed, so one long list cannot eat the whole budget. */
    private const MAX_DEEPER_POINTS = 8;

    private const LIST_ITEM = '/^\s*(?:[-*+•]|\d{1,3}[.)])\s+/u';

    public function __construct(
        public ResearchDepth $depth,
        public EvidenceLedger $ledger,
        public string $kind,
        public string $instruction,
        public ?string $priorReport,
        public string $unit,
        public ResearchStatus $status,
        public ?int $searchCap,
    ) {
    }

    public static function prepare(ResearchRunState $parent, string $kind, string $text): self
    {
        $prior = $parent->ledger->report();
        $ledger = EvidenceLedger::fromArray(['report' => null, 'check' => null] + $parent->ledger->toArray());

        return match ($kind) {
            self::QUESTION => self::question($ledger, $text, $prior),
            self::REVISE => self::writing($parent->ref->depth, $ledger, self::REVISE, $text, $prior),
            self::DEEPER => self::deeper($ledger, $text, $prior, $parent->ref->question),
            self::REFRESH => self::refresh($parent->ref->depth, $ledger, $text, $prior),
            default => throw new \InvalidArgumentException('Unknown follow-up: '.$kind),
        };
    }

    private static function question(EvidenceLedger $ledger, string $text, ?string $prior): self
    {
        $brief = $ledger->brief();
        if (null !== $brief) {
            $ledger->setBrief(new ResearchBrief(
                $text,
                $brief->language,
                $brief->output,
                $brief->audience,
                $brief->timeFrame,
                $brief->region,
                $brief->mustUse,
                $brief->mustAvoid,
            ));
        }
        $ledger->setOutline([]);
        if (FollowUpCoverage::covered($text, self::notes($ledger, $prior))) {
            return self::writing(ResearchDepth::Quick, $ledger, self::QUESTION, $text, $prior, 0);
        }
        self::keepRanQueries($ledger);
        $ledger->addPoint($text, 'Asked after the report.', [self::query($ledger, $text)]);

        return new self(
            ResearchDepth::Quick,
            $ledger,
            self::QUESTION,
            $text,
            $prior,
            null === $brief ? ResearchUnitPlan::BRIEF_PLAN : ResearchUnitPlan::ROUND,
            null === $brief ? ResearchStatus::Briefing : ResearchStatus::Gathering,
            1,
        );
    }

    private static function deeper(EvidenceLedger $ledger, string $text, ?string $prior, string $parentQuestion): self
    {
        $added = 0;
        foreach (self::notConfirmed($prior ?? '') as $line) {
            $ledger->addPoint($line, 'This was not confirmed.', [self::query($ledger, $line)]);
            ++$added;
        }
        if ('' !== $text) {
            $ledger->addPoint($text, 'Asked to look further.', [self::query($ledger, $text)]);
            ++$added;
        }
        if (0 === $added) {
            $ledger->addPoint($parentQuestion, 'Look further than the last report.', [self::query($ledger, $parentQuestion)]);
        }

        return new self(
            ResearchDepth::Thorough,
            $ledger,
            self::DEEPER,
            $text,
            $prior,
            ResearchUnitPlan::ROUND,
            ResearchStatus::Gathering,
            null,
        );
    }

    private static function refresh(ResearchDepth $depth, EvidenceLedger $ledger, string $text, ?string $prior): self
    {
        self::reopen($ledger);
        $instruction = 'Say what changed since the last report.';

        return new self(
            $depth,
            $ledger,
            self::REFRESH,
            '' === $text ? $instruction : $instruction."\n\n".$text,
            $prior,
            ResearchUnitPlan::ROUND,
            ResearchStatus::Gathering,
            null,
        );
    }

    private static function writing(ResearchDepth $depth, EvidenceLedger $ledger, string $kind, string $text, ?string $prior, ?int $searchCap = null): self
    {
        return new self($depth, $ledger, $kind, $text, $prior, ResearchUnitPlan::WRITE, ResearchStatus::Writing, $searchCap);
    }

    private static function notes(EvidenceLedger $ledger, ?string $prior): string
    {
        $parts = null === $prior ? [] : [$prior];
        foreach ($ledger->evidenceForWriter() as $cards) {
            foreach ($cards as $card) {
                if (is_string($card['fact'] ?? null)) {
                    $parts[] = $card['fact'];
                }
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Searches the parent planned and never ran are dropped, so the one
     * search of a question goes to the question.
     */
    private static function keepRanQueries(EvidenceLedger $ledger): void
    {
        $ran = [];
        foreach ($ledger->queries() as $query) {
            $ran[$query->pointId.'|'.$query->query] = true;
        }
        foreach ($ledger->points() as $point) {
            $kept = array_values(array_filter(
                $point->queries,
                static fn (array $query): bool => isset($ran[$point->id.'|'.$query['q']]),
            ));
            if (count($kept) !== count($point->queries)) {
                $ledger->renamePoint($point->id, $point->title, $kept);
            }
        }
    }

    /**
     * The lines under Not confirmed, without list markers or citation
     * markers. The heading is not an item; the closing count is already
     * left out of the section.
     *
     * @return list<string>
     */
    private static function notConfirmed(string $report): array
    {
        $lines = [];
        foreach (array_slice(preg_split('/\R/u', CheckReport::notConfirmedBody($report)) ?: [], 1) as $line) {
            if (1 === preg_match('/^\s*(?:#|\*\*)/u', $line)) {
                continue;
            }
            $item = (string) preg_replace(self::LIST_ITEM, '', $line);
            $item = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace(DraftSentences::MARKER, '', $item)));
            if ('' === $item || in_array($item, $lines, true)) {
                continue;
            }
            $lines[] = $item;
            if (self::MAX_DEEPER_POINTS === count($lines)) {
                break;
            }
        }

        return $lines;
    }

    private static function reopen(EvidenceLedger $ledger): void
    {
        $frame = $ledger->brief()?->timeFrame;
        $stale = [];
        foreach ($ledger->sources() as $source) {
            if (Source::READ === $source->status && self::older($source->details->published, $frame)) {
                $stale[$source->id] = true;
            }
        }
        foreach (array_keys($stale) as $id) {
            $ledger->markSource($id, Source::FOUND);
        }
        $points = [];
        foreach ($ledger->evidenceForWriter() as $pointId => $cards) {
            foreach ($cards as $card) {
                $source = $card['source'] ?? null;
                if (is_string($source) && isset($stale[$source]) && !in_array($pointId, $points, true)) {
                    $points[] = $pointId;
                }
            }
        }
        $ledger->dropQueries($points);
    }

    /**
     * A source is older than the brief's time frame when its date is before
     * the frame's start, or when it has no date. With no start, every read
     * source is checked again.
     *
     * @param array{from: ?string, to: ?string}|null $frame
     */
    private static function older(?string $published, ?array $frame): bool
    {
        if (null === $frame) {
            return true;
        }
        $edge = self::day($frame['from'] ?? null);
        $day = self::day($published);
        if (null === $edge || null === $day) {
            return true;
        }

        return $day < $edge;
    }

    private static function day(?string $value): ?string
    {
        if (!is_string($value) || 1 !== preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $match)) {
            return null;
        }

        return $match[1];
    }

    /**
     * @return array{q: string, language: string, scope: string}
     */
    private static function query(EvidenceLedger $ledger, string $text): array
    {
        $scope = 'web';
        foreach ($ledger->queries() as $query) {
            if (in_array($query->scope, ['web', 'files', 'both'], true)) {
                $scope = $query->scope;
                break;
            }
        }
        $brief = $ledger->brief();
        $language = null !== $brief ? $brief->language : 'en';

        return ['q' => $text, 'language' => $language, 'scope' => $scope];
    }
}
