<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

/**
 * Why a run ended. Clients translate `code` and `params`; `text()` is the
 * English sentence for a client that has no translation yet.
 *
 * A role name that is not one of ours becomes "selected", so a stray value
 * cannot leak into the sentence.
 */
final readonly class ResearchOutcome
{
    public const COMPLETE = 'complete';
    public const COMPLETE_PARTIAL = 'complete_partial';
    public const COMPLETE_SNIPPETS_ONLY = 'complete_snippets_only';
    public const STOPPED_BY_USER = 'stopped_by_user';
    public const STOPPED_BUDGET = 'stopped_budget';
    public const STOPPED_LIMIT = 'stopped_limit';
    public const STOPPED_WAITING = 'stopped_waiting';
    public const FAILED_NO_SOURCES = 'failed_no_sources';
    public const FAILED_NOTHING_FOUND = 'failed_nothing_found';
    public const FAILED_ROLE_UNAVAILABLE = 'failed_role_unavailable';
    public const FAILED_MODEL_ERROR = 'failed_model_error';
    public const FAILED_INTERRUPTED = 'failed_interrupted';
    public const STOPPED_BY_ADMIN = 'stopped_by_admin';
    public const ANSWERED_WITHOUT_RESEARCH = 'answered_without_research';

    /** @var list<string> */
    public const CODES = [
        self::ANSWERED_WITHOUT_RESEARCH,
        self::COMPLETE,
        self::COMPLETE_PARTIAL,
        self::COMPLETE_SNIPPETS_ONLY,
        self::STOPPED_BY_USER,
        self::STOPPED_BUDGET,
        self::STOPPED_LIMIT,
        self::STOPPED_WAITING,
        self::FAILED_NO_SOURCES,
        self::FAILED_NOTHING_FOUND,
        self::FAILED_ROLE_UNAVAILABLE,
        self::FAILED_MODEL_ERROR,
        self::FAILED_INTERRUPTED,
        self::STOPPED_BY_ADMIN,
    ];

    /** @var array<string, string> */
    private const ROLES = [
        'reader' => 'reading',
        'sorter' => 'sorting',
        'lead' => 'planning',
        'writer' => 'writing',
        'checker' => 'fact-checking',
        'reading' => 'reading',
        'sorting' => 'sorting',
        'planning' => 'planning',
        'writing' => 'writing',
        'fact-checking' => 'fact-checking',
        'selected' => 'selected',
    ];

    /**
     * @param array<string, int|string> $params
     */
    public function __construct(
        public string $code,
        public array $params = [],
    ) {
        if (!in_array($code, self::CODES, true)) {
            throw new \InvalidArgumentException('Unknown research outcome.');
        }
    }

    public function text(): string
    {
        return match ($this->code) {
            self::COMPLETE => sprintf(
                'Done: %d statements, %d backed by a source.',
                $this->whole('statements'),
                $this->whole('supported'),
            ),
            self::COMPLETE_PARTIAL => sprintf(
                'Done with gaps: %d of %d points could not be confirmed by a source, so the answer leaves them out. Look deeper checks them again.',
                $this->whole('open'),
                $this->whole('points'),
            ),
            self::COMPLETE_SNIPPETS_ONLY => 'No page could be opened, so this report is based on search summaries only. It says so where it matters.',
            self::STOPPED_BY_USER => 'Stopped. No new work was started after you pressed Stop; a step that was already underway may still count. What was found so far is kept.',
            self::STOPPED_BUDGET => sprintf(
                'Stopped because your monthly AI budget is used up. The report covers %d of %d points.',
                $this->whole('covered'),
                $this->whole('points'),
            ),
            self::STOPPED_LIMIT => sprintf(
                'Stopped because this research reached its limit for this depth. What was found covers %d of %d points.',
                $this->whole('covered'),
                $this->whole('points'),
            ),
            self::STOPPED_WAITING => 'Stopped because the plan was not confirmed within a day. Start it again when you are ready.',
            self::FAILED_NO_SOURCES => 'There was nothing to research: turn on the web or add files to this project.',
            self::FAILED_NOTHING_FOUND => 'The sources that were searched held nothing that answers this question. Try rewording it or add files to this project.',
            self::FAILED_ROLE_UNAVAILABLE => sprintf(
                'The %s model of this project is not available, and no other model in this project can stand in. Nothing was charged.',
                $this->role(),
            ),
            self::FAILED_MODEL_ERROR => sprintf(
                'The %s model stopped answering. Nothing else was charged; try again or pick another model.',
                $this->role(),
            ),
            self::FAILED_INTERRUPTED => 'The research was interrupted on the server. Start it again — the sources found so far are kept here.',
            self::STOPPED_BY_ADMIN => 'Research was turned off on your Synaplan workspace. What was found so far is kept.',
            self::ANSWERED_WITHOUT_RESEARCH => 'Answered without the extra look. Nothing was searched for this question.',
            default => 'The research stopped. Start it again if you still need the answer.',
        };
    }

    /**
     * The numbers a translated sentence fills in. The role is a key of
     * `research.role.*`, never the raw stored value.
     *
     * @return array{statements: int, supported: int, open: int, points: int, covered: int, role: string}
     */
    public function placeholders(): array
    {
        return [
            'statements' => $this->whole('statements'),
            'supported' => $this->whole('supported'),
            'open' => $this->whole('open'),
            'points' => $this->whole('points'),
            'covered' => $this->whole('covered'),
            'role' => $this->role(),
        ];
    }

    /**
     * @return array{code: string, params: array<string, int|string>, text: string}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'params' => $this->params,
            'text' => $this->text(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $code = $data['code'] ?? '';
        if (!is_string($code)) {
            throw new \InvalidArgumentException('Unknown research outcome.');
        }

        return new self($code, self::paramsOf($data['params'] ?? []));
    }

    /**
     * @return array<string, int|string>
     */
    private static function paramsOf(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $params = [];
        foreach ($raw as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (is_int($value)) {
                $params[$key] = $value;
            } elseif (is_string($value)) {
                $params[$key] = $value;
            } elseif (is_float($value)) {
                $params[$key] = (int) $value;
            }
        }

        return $params;
    }

    private function whole(string $key): int
    {
        $value = $this->params[$key] ?? 0;
        if (!is_numeric($value)) {
            return 0;
        }

        return max(0, (int) $value);
    }

    private function role(): string
    {
        $role = $this->params['role'] ?? 'selected';
        if (!is_string($role)) {
            return 'selected';
        }

        return self::ROLES[$role] ?? 'selected';
    }
}
