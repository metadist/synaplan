<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use Psr\Clock\ClockInterface;

/**
 * Redis state for one research run: the ledger, the checkpoint, and the claim
 * that fences it. A unit writes through `checkpoint` while it holds the claim.
 * `save` is for opening a run, before any worker has claimed it.
 *
 * Every key of a run expires with the run, so deleting a person through the
 * owner index reaches all of them.
 *
 * Nothing here is reachable until a later step turns the research module on.
 */
final readonly class ResearchStateStore
{
    /** A reader never sees more than its context window, so more text is only memory. */
    private const MAX_SOURCE_BYTES = 4 * 1024 * 1024;

    public function __construct(
        private ResearchRunKeys $keys,
        private ClockInterface $clock,
        private int $ttlHours = 48,
    ) {
        if ($ttlHours < 1 || $ttlHours > 168) {
            throw new \InvalidArgumentException('Research state is kept between 1 and 168 hours.');
        }
    }

    public function save(ResearchRunState $state): void
    {
        $state->assertShape();
        $now = $this->now();
        $state->updatedAt = $now;
        $state->expiresAt = $now->modify('+'.$this->ttlHours.' hours');
        $this->keys->set(ResearchKey::run($state->ref->id), $state->encode(), $this->ttlSeconds());
        $this->syncMembership($state);
    }

    public function load(string $runId): ?ResearchRunState
    {
        ResearchIds::assert($runId);
        $json = $this->keys->get(ResearchKey::run($runId));
        if (null === $json || '' === $json) {
            return null;
        }

        return ResearchRunState::decode($json);
    }

    public function expectedSeq(string $runId): int
    {
        $state = $this->load($runId);
        if (null === $state) {
            throw new ResearchRunNotFound($runId);
        }

        return $state->expectedSeq;
    }

    public function claim(string $runId, int $seq, int $leaseMs): ?string
    {
        ResearchIds::assert($runId);
        if ($seq < 1) {
            throw new \InvalidArgumentException('A research unit needs a sequence number.');
        }
        if ($leaseMs < 1) {
            throw new \InvalidArgumentException('A research claim needs a lease.');
        }

        $token = bin2hex(random_bytes(16));
        $claimed = $this->keys->claim(
            ResearchKey::claim($runId, $seq),
            $token,
            $leaseMs,
            ResearchKey::CLAIMS,
            $this->nowMs() + $leaseMs,
            ResearchKey::claimMember($runId, $seq, $token),
        );

        return $claimed ? $token : null;
    }

    /**
     * Write the run and release the claim, only while $token still holds the
     * claim for the unit that is in progress. A lost lease leaves the stored
     * run untouched.
     */
    public function checkpoint(ResearchRunState $state, string $token): bool
    {
        $state->assertShape();
        $seq = $state->expectedSeq;
        $now = $this->now();
        $expires = $now->modify('+'.$this->ttlHours.' hours');
        $payload = $state->encode($seq + 1, $seq, $now, $expires);
        $written = $this->keys->compareAndSet(
            ResearchKey::claim($state->ref->id, $seq),
            $token,
            ResearchKey::run($state->ref->id),
            $payload,
            $this->ttlSeconds(),
        );
        if (!$written) {
            return false;
        }

        $state->checkpointSeq = $seq;
        $state->expectedSeq = $seq + 1;
        $state->updatedAt = $now;
        $state->expiresAt = $expires;
        $state->assertShape();
        $this->keys->zRem(ResearchKey::CLAIMS, ResearchKey::claimMember($state->ref->id, $seq, $token));
        $this->syncMembership($state);

        return true;
    }

    public function release(string $runId, int $seq, string $token): void
    {
        ResearchIds::assert($runId);
        $this->keys->deleteIfEquals(ResearchKey::claim($runId, $seq), $token);
        $this->keys->zRem(ResearchKey::CLAIMS, ResearchKey::claimMember($runId, $seq, $token));
    }

    /**
     * Claims whose lease is over and whose unit was not checkpointed.
     *
     * @return list<array{runId: string, seq: int, token: string}>
     */
    public function expiredClaims(): array
    {
        $due = [];
        foreach ($this->keys->zRangeByScore(ResearchKey::CLAIMS, $this->nowMs()) as $member) {
            $parsed = $this->parseClaim($member);
            if (null === $parsed) {
                $this->keys->zRem(ResearchKey::CLAIMS, $member);
                continue;
            }
            $state = $this->load($parsed['runId']);
            if (null === $state || $state->checkpointSeq >= $parsed['seq']) {
                $this->keys->zRem(ResearchKey::CLAIMS, $member);
                continue;
            }
            $due[] = $parsed;
        }

        return $due;
    }

    public function activeCount(int $userId, ?ResearchDepth $depth = null): int
    {
        $count = 0;
        foreach ($this->liveMembers(ResearchKey::activeUser($userId), true) as $member) {
            if (null === $depth || str_starts_with($member, $depth->value.':')) {
                ++$count;
            }
        }

        return $count;
    }

    public function activeTotal(): int
    {
        return count($this->liveMembers(ResearchKey::ACTIVE, false));
    }

    public function sourceText(string $runId, string $sourceId): ?string
    {
        ResearchIds::assert($runId);
        $this->assertSourceId($sourceId);

        return $this->keys->get(ResearchKey::text($runId, $sourceId));
    }

    /**
     * @throws ResearchRunNotFound when the run expired or was deleted meanwhile
     */
    public function putSourceText(string $runId, string $sourceId, string $text): void
    {
        ResearchIds::assert($runId);
        $this->assertSourceId($sourceId);
        if (strlen($text) > self::MAX_SOURCE_BYTES) {
            throw new \InvalidArgumentException('That source is too large to keep for this research.');
        }
        if (!$this->keys->putSourceText($runId, $sourceId, $text)) {
            throw new ResearchRunNotFound($runId);
        }
    }

    public function deleteUser(int $userId): int
    {
        $runIds = $this->keys->members(ResearchKey::owner($userId));
        foreach ($runIds as $runId) {
            if (ResearchIds::isValid($runId)) {
                $this->delete($runId);
            }
        }
        $this->keys->delete(ResearchKey::owner($userId));
        $this->keys->delete(ResearchKey::activeUser($userId));

        return count($runIds);
    }

    public function delete(string $runId): void
    {
        ResearchIds::assert($runId);
        $state = $this->load($runId);
        $this->keys->delete(ResearchKey::run($runId));
        $this->dropClaims($runId);
        foreach ($this->keys->members(ResearchKey::textIndex($runId)) as $sourceId) {
            $this->keys->delete(ResearchKey::text($runId, $sourceId));
        }
        $this->keys->delete(ResearchKey::textIndex($runId));
        $this->keys->delete(ResearchKey::events($runId));
        $this->keys->delete(ResearchKey::eventSeq($runId));
        if (null !== $state) {
            $this->keys->removeMember(ResearchKey::owner($state->ref->userId), $runId);
            $this->keys->removeMember(ResearchKey::activeUser($state->ref->userId), $state->ref->depth->value.':'.$runId);
        }
        $this->keys->removeMember(ResearchKey::ACTIVE, $runId);
    }

    private function syncMembership(ResearchRunState $state): void
    {
        $runId = $state->ref->id;
        $userId = $state->ref->userId;
        $member = $state->ref->depth->value.':'.$runId;
        $ttl = $this->ttlSeconds();
        $this->keys->addMember(ResearchKey::owner($userId), $runId);
        $this->keys->expire(ResearchKey::owner($userId), $ttl);
        $this->keys->expire(ResearchKey::events($runId), $ttl);
        $this->keys->expire(ResearchKey::eventSeq($runId), $ttl);
        $this->keys->expire(ResearchKey::textIndex($runId), $ttl);
        if ($state->status->isTerminal()) {
            $this->keys->removeMember(ResearchKey::activeUser($userId), $member);
            $this->keys->removeMember(ResearchKey::ACTIVE, $runId);

            return;
        }
        $this->keys->addMember(ResearchKey::activeUser($userId), $member);
        $this->keys->expire(ResearchKey::activeUser($userId), $ttl);
        $this->keys->addMember(ResearchKey::ACTIVE, $runId);
        $this->keys->expire(ResearchKey::ACTIVE, $ttl);
    }

    /**
     * @return list<string>
     */
    private function liveMembers(string $key, bool $userSet): array
    {
        $live = [];
        foreach ($this->keys->members($key) as $member) {
            $runId = $userSet ? $this->runIdFromMember($member) : $member;
            $valid = ResearchIds::isValid($runId);
            $state = $valid ? $this->load($runId) : null;
            if (null === $state || $state->status->isTerminal()) {
                $this->keys->removeMember($key, $member);
                if ($userSet && $valid) {
                    $this->keys->removeMember(ResearchKey::ACTIVE, $runId);
                }
                continue;
            }
            $live[] = $member;
        }

        return $live;
    }

    private function runIdFromMember(string $member): string
    {
        $pos = strpos($member, ':');
        if (false === $pos) {
            return $member;
        }

        return substr($member, $pos + 1);
    }

    private function dropClaims(string $runId): void
    {
        foreach ($this->keys->zRangeByScore(ResearchKey::CLAIMS, PHP_INT_MAX) as $member) {
            if (!str_starts_with($member, $runId.'|')) {
                continue;
            }
            $this->keys->zRem(ResearchKey::CLAIMS, $member);
            $parsed = $this->parseClaim($member);
            if (null !== $parsed) {
                $this->keys->delete(ResearchKey::claim($runId, $parsed['seq']));
            }
        }
    }

    /**
     * @return array{runId: string, seq: int, token: string}|null
     */
    private function parseClaim(string $member): ?array
    {
        $parts = explode('|', $member, 3);
        if (3 !== count($parts)) {
            return null;
        }
        [$runId, $seq, $token] = $parts;
        if (!ResearchIds::isValid($runId)) {
            return null;
        }
        if (1 !== preg_match('/^[1-9][0-9]*$/', $seq) || 1 !== preg_match('/^[0-9a-f]{32}$/', $token)) {
            return null;
        }

        return ['runId' => $runId, 'seq' => (int) $seq, 'token' => $token];
    }

    private function assertSourceId(string $sourceId): void
    {
        if (1 !== preg_match('/^s[1-9][0-9]*$/', $sourceId)) {
            throw new \InvalidArgumentException('That source is not in this research.');
        }
    }

    private function nowMs(): int
    {
        return (int) $this->clock->now()->format('Uv');
    }

    private function now(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@'.$this->clock->now()->getTimestamp()))->setTimezone(new \DateTimeZone('UTC'));
    }

    private function ttlSeconds(): int
    {
        return $this->ttlHours * 3600;
    }
}
