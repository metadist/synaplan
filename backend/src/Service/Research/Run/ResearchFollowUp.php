<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Service\Research\Run\Ledger\EvidenceLedger;
use App\Service\Research\Run\Ledger\ResearchBrief;
use App\Service\Research\Run\Ledger\Source;
use App\Service\Research\Run\Stage\CheckReport;

/**
 * A follow-up is a new run. It keeps the parent's sources, evidence and
 * point numbers, and it does not gain a source the parent did not have.
 */
final readonly class ResearchFollowUp
{
    public const QUESTION = 'question';
    public const REVISE = 'revise';
    public const DEEPER = 'deeper';
    public const REFRESH = 'refresh';

    /** @var list<string> */
    public const KINDS = [self::QUESTION, self::REVISE, self::DEEPER, self::REFRESH];

    public function __construct(
        public ResearchDepth $depth,
        public EvidenceLedger $ledger,
        public string $kind,
        public string $instruction,
        public ?string $priorReport,
        public string $unit,
        public ResearchStatus $status,
        public bool $oneSearch,
    ) {
    }

    public static function prepare(ResearchRunState $parent, string $kind, string $text): self
    {
        $prior = $parent->ledger->report();
        $ledger = EvidenceLedger::fromArray(['report' => null, 'check' => null] + $parent->ledger->toArray());

        return match ($kind) {
            self::QUESTION => self::question($ledger, $text, $prior),
            self::REVISE => self::writing($parent->ref->depth, $ledger, self::REVISE, $text, $prior),
            self::DEEPER => self::deeper($ledger, $text, $prior),
            self::REFRESH => self::refresh($parent->ref->depth, $ledger, $prior),
            default => throw new \InvalidArgumentException('Unknown follow-up.'),
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
        if (self::hasNotes($ledger, $prior)) {
            return self::writing(ResearchDepth::Quick, $ledger, self::QUESTION, $text, $prior);
        }
        if ([] !== $ledger->points()) {
            $ledger->addPoint($text, 'The notes had nothing on this question.', [self::query($ledger, $text)]);
        }

        return new self(
            ResearchDepth::Quick,
            $ledger,
            self::QUESTION,
            $text,
            $prior,
            ResearchUnitPlan::BRIEF_PLAN,
            ResearchStatus::Briefing,
            true,
        );
    }

    private static function deeper(EvidenceLedger $ledger, string $text, ?string $prior): self
    {
        if (0 === self::pointsFromNotConfirmed($ledger, $prior)) {
            $ledger->addPoint($text, 'Look further than the last report.', [self::query($ledger, $text)]);
        }

        return new self(
            ResearchDepth::Thorough,
            $ledger,
            self::DEEPER,
            $text,
            $prior,
            ResearchUnitPlan::ROUND,
            ResearchStatus::Gathering,
            false,
        );
    }

    private static function refresh(ResearchDepth $depth, EvidenceLedger $ledger, ?string $prior): self
    {
        self::reopen($ledger);

        return new self(
            $depth,
            $ledger,
            self::REFRESH,
            'Say what changed since the last report.',
            $prior,
            ResearchUnitPlan::ROUND,
            ResearchStatus::Gathering,
            false,
        );
    }

    private static function writing(ResearchDepth $depth, EvidenceLedger $ledger, string $kind, string $text, ?string $prior): self
    {
        return new self($depth, $ledger, $kind, $text, $prior, ResearchUnitPlan::WRITE, ResearchStatus::Writing, false);
    }

    private static function hasNotes(EvidenceLedger $ledger, ?string $prior): bool
    {
        return [] !== $ledger->evidenceForWriter() || (null !== $prior && '' !== trim($prior));
    }

    private static function pointsFromNotConfirmed(EvidenceLedger $ledger, ?string $prior): int
    {
        $body = CheckReport::notConfirmedBody($prior ?? '');
        if ('' === $body) {
            return 0;
        }
        $added = 0;
        foreach (preg_split('/\R/u', $body) ?: [] as $line) {
            $line = trim(ltrim(trim($line), '-'));
            if ('' === $line || str_starts_with($line, '## ')) {
                continue;
            }
            $ledger->addPoint($line, 'This was not confirmed.', [self::query($ledger, $line)]);
            ++$added;
        }

        return $added;
    }

    private static function reopen(EvidenceLedger $ledger): void
    {
        $frame = $ledger->brief()?->timeFrame;
        $stale = [];
        foreach ($ledger->sources() as $source) {
            if (Source::READ === $source->status && self::older($source->details->published, $frame)) {
                $stale[] = $source->id;
            }
        }
        foreach ($stale as $id) {
            $ledger->markSource($id, Source::FOUND);
        }
        /** @var array<string, true> $points */
        $points = [];
        foreach ($ledger->evidenceForWriter() as $pointId => $cards) {
            foreach ($cards as $card) {
                $source = $card['source'] ?? null;
                if (is_string($source) && in_array($source, $stale, true)) {
                    $points[$pointId] = true;
                }
            }
        }
        $ledger->dropQueries(array_keys($points));
    }

    /**
     * A source is older than the brief's time frame when its date is before
     * the frame, or when it has no date. With no frame, every read source
     * is checked again.
     *
     * @param array{from: ?string, to: ?string}|null $frame
     */
    private static function older(?string $published, ?array $frame): bool
    {
        if (null === $frame) {
            return true;
        }
        $edge = self::day($frame['from'] ?? null) ?? self::day($frame['to'] ?? null);
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
