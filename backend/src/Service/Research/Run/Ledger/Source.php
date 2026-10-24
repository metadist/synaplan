<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Ledger;

final readonly class Source
{
    public const WEB = 'web';
    public const FILE = 'file';
    public const KNOWLEDGE = 'knowledge';

    public const FOUND = 'found';
    public const READING = 'reading';
    public const READ = 'read';
    public const BLOCKED = 'blocked';

    /** @var list<string> */
    public const STATUSES = [self::FOUND, self::READING, self::READ, self::BLOCKED];

    public function __construct(
        public string $id,
        public string $kind,
        public string $title,
        public string $status,
        public ?string $url,
        public ?int $fileId,
        public ?string $host,
        public SourceDetails $details,
    ) {
    }

    public static function web(string $url, string $title, ?string $published = null, ?string $sourceClass = null): self
    {
        $key = CanonicalUrl::key($url);
        if (null === $key) {
            throw new \InvalidArgumentException('A web source needs a valid address.');
        }

        return new self(
            '',
            self::WEB,
            self::title($title, CanonicalUrl::host($url) ?? 'Untitled source'),
            self::FOUND,
            $key,
            null,
            CanonicalUrl::host($url),
            new SourceDetails(self::published($published), self::optional($sourceClass, 40)),
        );
    }

    public static function file(int $fileId, string $title): self
    {
        if ($fileId < 1) {
            throw new \InvalidArgumentException('A file source needs a file.');
        }

        return new self(
            '',
            self::FILE,
            self::title($title, 'Untitled source'),
            self::FOUND,
            null,
            $fileId,
            null,
            new SourceDetails(),
        );
    }

    public function withStatus(string $status, ?string $reason, ?string $readBy = null): self
    {
        return new self(
            $this->id,
            $this->kind,
            $this->title,
            $status,
            $this->url,
            $this->fileId,
            $this->host,
            new SourceDetails(
                $this->details->published,
                $this->details->sourceClass,
                null === $reason ? null : PlainText::line($reason, 300),
                null === $readBy ? $this->details->readBy : PlainText::line($readBy, 120),
                $this->details->origin,
                $this->details->fromId,
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'title' => $this->title,
            'url' => $this->url,
            'host' => $this->host,
            'file_id' => $this->fileId,
            'published' => $this->details->published,
            'class' => $this->details->sourceClass,
            'status' => $this->status,
            'reason' => $this->details->reason,
            'read_by' => $this->details->readBy,
            'origin' => $this->details->origin,
            'from' => $this->details->fromId,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $kind = (string) ($data['kind'] ?? '');
        if (!in_array($kind, [self::WEB, self::FILE, self::KNOWLEDGE], true)) {
            throw new \InvalidArgumentException('A source kind is not recognised.');
        }
        $status = (string) ($data['status'] ?? self::FOUND);
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('A source status is not recognised.');
        }

        return new self(
            (string) ($data['id'] ?? ''),
            $kind,
            PlainText::line((string) ($data['title'] ?? ''), 300),
            $status,
            is_string($data['url'] ?? null) ? $data['url'] : null,
            is_numeric($data['file_id'] ?? null) ? (int) $data['file_id'] : null,
            is_string($data['host'] ?? null) ? $data['host'] : null,
            new SourceDetails(
                self::optional(is_string($data['published'] ?? null) ? $data['published'] : null, 40),
                self::optional(is_string($data['class'] ?? null) ? $data['class'] : null, 40),
                self::optional(is_string($data['reason'] ?? null) ? $data['reason'] : null, 300),
                self::optional(is_string($data['read_by'] ?? null) ? $data['read_by'] : null, 120),
                self::optional(is_string($data['origin'] ?? null) ? $data['origin'] : null, 40),
                self::optional(is_string($data['from'] ?? null) ? $data['from'] : null, 20),
            ),
        );
    }

    private static function title(string $title, string $fallback): string
    {
        $clean = PlainText::line($title, 300);

        return '' === $clean ? $fallback : $clean;
    }

    private static function published(?string $published): ?string
    {
        return self::optional($published, 40);
    }

    private static function optional(?string $value, int $max): ?string
    {
        if (null === $value) {
            return null;
        }
        $clean = PlainText::line($value, $max);

        return '' === $clean ? null : $clean;
    }
}
