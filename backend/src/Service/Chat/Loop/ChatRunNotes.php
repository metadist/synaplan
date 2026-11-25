<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

/**
 * The notes of a chat run (03 §1.5): what the older part of the transcript
 * held, once it was folded away. The model writes them in the compaction
 * call; the loop checks every field, caps it, and adds the file and message
 * IDs it saw itself, so a later `read_document` or `conversation_search`
 * still finds what the run looked at.
 *
 * Notes hold text only. A picture is a file ID and a description; anything
 * that looks like inline image data is removed.
 */
final readonly class ChatRunNotes
{
    public const PLAN_DONE = 'done';
    public const PLAN_DOING = 'doing';
    public const PLAN_OPEN = 'open';
    public const PLAN_STATES = [self::PLAN_DONE, self::PLAN_DOING, self::PLAN_OPEN];

    public const MAX_PLAN = 5;
    public const MAX_FACTS = 40;
    public const MAX_ITEMS = 20;
    public const MAX_FILES = 40;
    public const MAX_MESSAGES = 60;

    private const TEXT = 400;
    private const GOAL = 600;
    private const NAME = 120;
    private const REF = 80;

    /**
     * @param list<array{text: string, state: string}> $plan
     * @param list<array{text: string, ref?: string}>  $facts
     * @param list<string>                             $decisions
     * @param list<string>                             $open
     * @param list<array{id: int, name: string, what: string}> $files
     * @param list<array{q: string, a: string}>        $asked
     * @param list<int>                                $messages message IDs of this chat the run has seen
     */
    public function __construct(
        public string $goal,
        public array $plan = [],
        public array $facts = [],
        public array $decisions = [],
        public array $open = [],
        public array $files = [],
        public array $asked = [],
        public array $messages = [],
    ) {
    }

    /**
     * Notes as the model wrote them, checked field by field; null when the
     * answer is not notes at all.
     */
    public static function read(mixed $data): ?self
    {
        if (!\is_array($data) || !\is_string($data['goal'] ?? null) || '' === trim($data['goal'])) {
            return null;
        }
        $plan = [];
        foreach (self::rows($data['plan'] ?? null) as $item) {
            $text = self::text($item['text'] ?? null, self::TEXT);
            if ('' !== $text) {
                $state = \in_array($item['state'] ?? null, self::PLAN_STATES, true) ? (string) $item['state'] : self::PLAN_OPEN;
                $plan[] = ['text' => $text, 'state' => $state];
            }
        }
        $facts = [];
        foreach (self::rows($data['facts'] ?? null) as $item) {
            $text = self::text($item['text'] ?? null, self::TEXT);
            if ('' === $text) {
                continue;
            }
            $ref = self::text($item['ref'] ?? null, self::REF);
            $facts[] = '' === $ref ? ['text' => $text] : ['text' => $text, 'ref' => $ref];
        }
        $files = [];
        foreach (self::rows($data['files'] ?? null) as $item) {
            $id = self::id($item['id'] ?? null);
            if (null !== $id && !self::hasFile($files, $id)) {
                $files[] = ['id' => $id, 'name' => self::text($item['name'] ?? null, self::NAME), 'what' => self::text($item['what'] ?? null, self::TEXT)];
            }
        }
        $asked = [];
        foreach (self::rows($data['asked'] ?? null) as $item) {
            $q = self::text($item['q'] ?? null, self::TEXT);
            if ('' !== $q) {
                $asked[] = ['q' => $q, 'a' => self::text($item['a'] ?? null, self::TEXT)];
            }
        }
        $messages = [];
        foreach (\is_array($data['messages'] ?? null) ? $data['messages'] : [] as $id) {
            $id = self::id($id);
            if (null !== $id && !\in_array($id, $messages, true)) {
                $messages[] = $id;
            }
        }

        return new self(
            self::text($data['goal'], self::GOAL),
            \array_slice($plan, 0, self::MAX_PLAN),
            \array_slice($facts, -self::MAX_FACTS),
            \array_slice(self::strings($data['decisions'] ?? null), -self::MAX_ITEMS),
            \array_slice(self::strings($data['open'] ?? null), 0, self::MAX_ITEMS),
            \array_slice($files, -self::MAX_FILES),
            \array_slice($asked, -self::MAX_ITEMS),
            \array_slice($messages, -self::MAX_MESSAGES),
        );
    }

    /**
     * These notes plus the IDs the loop saw in the part that was folded away.
     * An ID the model already noted keeps the model's name and description.
     *
     * @param array<int, string> $files    file ID => name, as the tool results named it
     * @param list<int>          $messages
     */
    public function withSeen(array $files, array $messages): self
    {
        $known = $this->files;
        foreach ($files as $id => $name) {
            if ($id > 0 && !self::hasFile($known, $id)) {
                $known[] = ['id' => $id, 'name' => self::text($name, self::NAME), 'what' => ''];
            }
        }
        $seen = $this->messages;
        foreach ($messages as $id) {
            if ($id > 0 && !\in_array($id, $seen, true)) {
                $seen[] = $id;
            }
        }

        return new self($this->goal, $this->plan, $this->facts, $this->decisions, $this->open, \array_slice($known, -self::MAX_FILES), $this->asked, \array_slice($seen, -self::MAX_MESSAGES));
    }

    /** @return array<string, mixed> */
    public function encode(): array
    {
        return [
            'goal' => $this->goal,
            'plan' => $this->plan,
            'facts' => $this->facts,
            'decisions' => $this->decisions,
            'open' => $this->open,
            'files' => $this->files,
            'asked' => $this->asked,
            'messages' => $this->messages,
        ];
    }

    public function json(): string
    {
        return json_encode($this->encode(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** The notes as the model reads them in the system prompt. */
    public function render(): string
    {
        $lines = ['Goal: '.$this->goal];
        if ([] !== $this->plan) {
            $lines[] = 'Plan:';
            foreach ($this->plan as $item) {
                $lines[] = '- ['.$item['state'].'] '.$item['text'];
            }
        }
        self::section($lines, 'Facts', array_map(static fn (array $f): string => $f['text'].(isset($f['ref']) ? ' (ref '.$f['ref'].')' : ''), $this->facts));
        self::section($lines, 'Decisions', $this->decisions);
        self::section($lines, 'Still open', $this->open);
        self::section($lines, 'Files (open them again with read_document or analyze_image)', array_map(
            static fn (array $f): string => 'file_id '.$f['id'].('' !== $f['name'] ? ' · '.$f['name'] : '').('' !== $f['what'] ? ' · '.$f['what'] : ''),
            $this->files,
        ));
        self::section($lines, 'Asked and answered', array_map(static fn (array $q): string => $q['q'].' → '.$q['a'], $this->asked));
        if ([] !== $this->messages) {
            $lines[] = 'Messages of this chat already read (open them again with conversation_search around): '.implode(', ', $this->messages);
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $lines
     * @param list<string> $items
     */
    private static function section(array &$lines, string $title, array $items): void
    {
        if ([] === $items) {
            return;
        }
        $lines[] = $title.':';
        foreach ($items as $item) {
            $lines[] = '- '.$item;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $raw): array
    {
        $out = [];
        foreach (\is_array($raw) ? $raw : [] as $row) {
            if (\is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function strings(mixed $raw): array
    {
        $out = [];
        foreach (\is_array($raw) ? $raw : [] as $item) {
            $text = self::text($item, self::TEXT);
            if ('' !== $text) {
                $out[] = $text;
            }
        }

        return $out;
    }

    /** One line of text, no inline image data, no control characters, cut to $max. */
    private static function text(mixed $raw, int $max): string
    {
        if (!\is_string($raw) && !\is_int($raw) && !\is_float($raw)) {
            return '';
        }
        $text = (string) preg_replace('#data:[a-z]+/[a-z0-9.+-]+;base64,[A-Za-z0-9+/=\s]*#i', '[image data removed]', (string) $raw);
        $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', mb_scrub($text, 'UTF-8')));

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }

    private static function id(mixed $raw): ?int
    {
        if (\is_string($raw) && ctype_digit($raw)) {
            $raw = (int) $raw;
        }

        return \is_int($raw) && $raw > 0 ? $raw : null;
    }

    /** @param list<array{id: int, name: string, what: string}> $files */
    private static function hasFile(array $files, int $id): bool
    {
        foreach ($files as $file) {
            if ($file['id'] === $id) {
                return true;
            }
        }

        return false;
    }
}
