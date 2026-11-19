<?php

declare(strict_types=1);

namespace App\Service\Run;

/**
 * Redis key names of one kind of run. Every name is built from a validated
 * run id, so a caller can never reach a key outside its own run. The shared
 * client adds its own prefix on top.
 */
final readonly class RunKeyspace
{
    private const PREFIX_PATTERN = '/^[a-z][a-z0-9-]{1,23}$/D';

    /**
     * @param non-empty-string $idPattern a full-match regular expression for the run ids of this kind
     */
    public function __construct(
        public string $prefix,
        private string $idPattern,
    ) {
        if (1 !== preg_match(self::PREFIX_PATTERN, $prefix)) {
            throw new \InvalidArgumentException('A run keyspace needs a short lowercase prefix.');
        }
        if (false === @preg_match($idPattern, '')) {
            throw new \InvalidArgumentException('A run keyspace needs a valid id pattern.');
        }
    }

    /** The keyspace of runs kept in `BRUNS`, addressed by their ULID. */
    public static function runs(): self
    {
        return new self('run', RunIds::PATTERN);
    }

    public function isValid(string $runId): bool
    {
        return 1 === preg_match($this->idPattern, $runId);
    }

    public function assert(string $runId): void
    {
        if (!$this->isValid($runId)) {
            throw new \InvalidArgumentException('This run could not be found.');
        }
    }

    public function state(string $runId): string
    {
        return $this->key('state', $runId);
    }

    public function events(string $runId): string
    {
        return $this->key('events', $runId);
    }

    public function eventSeq(string $runId): string
    {
        return $this->key('events', $runId).':seq';
    }

    public function claim(string $runId, int $seq): string
    {
        self::assertSeq($seq);

        return $this->key('claim', $runId).':'.$seq;
    }

    /** One sorted set of every lease, scored by when it runs out. */
    public function claims(): string
    {
        return $this->prefix.':claims';
    }

    public function claimMember(string $runId, int $seq, string $token): string
    {
        $this->assert($runId);
        self::assertSeq($seq);
        RunClaim::assertToken($token);

        return $runId.'|'.$seq.'|'.$token;
    }

    /**
     * @return array{runId: string, seq: int, token: string}|null
     */
    public function parseClaimMember(string $member): ?array
    {
        $parts = explode('|', $member, 3);
        if (3 !== \count($parts)) {
            return null;
        }
        [$runId, $seq, $token] = $parts;
        if (!$this->isValid($runId) || 1 !== preg_match('/^[1-9][0-9]{0,9}$/D', $seq) || !RunClaim::isToken($token)) {
            return null;
        }

        return ['runId' => $runId, 'seq' => (int) $seq, 'token' => $token];
    }

    /** Runs that still have work left. */
    public function active(): string
    {
        return $this->prefix.':active';
    }

    public function reap(string $runId): string
    {
        return $this->key('reap', $runId);
    }

    public function cancel(string $runId): string
    {
        return $this->key('cancel', $runId);
    }

    public function delivered(string $runId): string
    {
        return $this->key('delivered', $runId);
    }

    /** The key itself is hashed, so a caller's text never becomes part of a key name. */
    public function idempotency(int $userId, string $key): string
    {
        if ($userId < 1) {
            throw new \InvalidArgumentException('A run start needs its owner.');
        }

        return $this->prefix.':idem:'.$userId.':'.hash('sha256', $key);
    }

    private function key(string $name, string $runId): string
    {
        $this->assert($runId);

        return $this->prefix.':'.$name.':'.$runId;
    }

    private static function assertSeq(int $seq): void
    {
        if ($seq < 1) {
            throw new \InvalidArgumentException('A run unit needs a sequence number.');
        }
    }
}
