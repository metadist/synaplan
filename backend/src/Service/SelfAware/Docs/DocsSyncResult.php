<?php

declare(strict_types=1);

namespace App\Service\SelfAware\Docs;

final readonly class DocsSyncResult
{
    /**
     * @param list<array{slug: string, action: string, chunks: int, message: string}> $rows
     */
    public function __construct(
        public string $status,
        public int $changed,
        public int $unchanged,
        public int $removed,
        public int $failed,
        public array $rows,
        public string $reason = '',
    ) {
    }

    public static function skipped(string $reason): self
    {
        return new self('skipped', 0, 0, 0, 0, [], $reason);
    }

    public static function failed(string $reason): self
    {
        return new self('failed', 0, 0, 0, 0, [], $reason);
    }

    public function isFailed(): bool
    {
        return 'failed' === $this->status;
    }
}
