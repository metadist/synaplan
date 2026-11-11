<?php

declare(strict_types=1);

namespace App\Service\Iam;

use App\Entity\User;
use App\Entity\UserLevelGrant;
use App\Repository\UserLevelGrantRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The only writer of {@see User::setUserLevel()} for an account that has a
 * level. Channel identities (ANONYMOUS, and the WhatsApp step back to NEW)
 * stay outside this service.
 *
 * An unmanaged user — a unit-test double that was never persisted — keeps
 * its grants in memory so the same rules run without a database.
 */
final readonly class LevelGrantService
{
    /** @var \WeakMap<User, array<string, array{level: string, ref: ?string, created: int, createdBy: ?int}>> */
    private \WeakMap $memory;

    public function __construct(
        private EntityManagerInterface $em,
        private UserLevelGrantRepository $grants,
        private LegacyLevelGrants $legacy,
    ) {
        $this->memory = new \WeakMap();
    }

    public function replace(User $user, string $source, string $level, ?string $reference, ?int $actorId): void
    {
        $level = strtoupper($level);
        if (!LevelGrantSource::known($source) || !AccountLevel::known($level) || 'NEW' === $level) {
            throw new \InvalidArgumentException('A grant needs a known source and a level above New.');
        }

        $this->mutate($user, function (array $rows) use ($user, $source, $level, $reference, $actorId): array {
            $rows[$source] = [
                'level' => $level,
                'ref' => $reference,
                'created' => $rows[$source]['created'] ?? time(),
                'createdBy' => $actorId,
            ];
            $this->store($user, $source, $rows[$source]);

            return $rows;
        });
    }

    public function remove(User $user, string $source): void
    {
        $this->mutate($user, function (array $rows) use ($user, $source): array {
            unset($rows[$source]);
            $this->deleteStored($user, $source);

            return $rows;
        });
    }

    /**
     * What an administrator set on People → Users. NEW removes that grant
     * instead of storing a New grant. A level other than Admin also takes
     * back a bootstrap grant. The directory grant is left for the identity
     * provider to remove.
     *
     * @return array{kind: string, level: string, source: ?string, removed: ?string}|null
     */
    public function assignFromAdministrator(User $user, string $requested, ?int $actorId): ?array
    {
        $requested = strtoupper($requested);
        if (!AccountLevel::known($requested)) {
            throw new \InvalidArgumentException('Unknown level.');
        }
        $removed = $this->current($user)[LevelGrantSource::ADMIN]['level'] ?? null;

        $this->mutate($user, function (array $rows) use ($user, $requested, $actorId): array {
            $next = $this->choose($rows, $requested);
            foreach ([LevelGrantSource::BOOTSTRAP, LevelGrantSource::ADMIN] as $source) {
                if (isset($rows[$source]) && !isset($next[$source])) {
                    $this->deleteStored($user, $source);
                }
            }
            if (isset($next[LevelGrantSource::ADMIN])) {
                $next[LevelGrantSource::ADMIN]['createdBy'] = $actorId;
                $this->store($user, LevelGrantSource::ADMIN, $next[LevelGrantSource::ADMIN]);
            }

            return $next;
        });

        $rows = $this->managed($user)
            ? $this->index($this->grants->forUser((int) $user->getId()))
            : ($this->memory[$user] ?? []);
        if ('NEW' === $requested) {
            if (null === $removed) {
                return $this->keptNotice($requested, $rows);
            }
            $winner = $this->winner($rows);

            return [
                'kind' => 'removed',
                'removed' => $removed,
                'level' => $user->getUserLevel(),
                'source' => $winner['source'] ?? null,
            ];
        }

        return $this->keptNotice($requested, $rows);
    }

    public function project(User $user, string $requested): string
    {
        $rows = $this->choose($this->current($user), strtoupper($requested));
        $highest = AccountLevel::highest(array_column($rows, 'level'));

        return $highest ?? 'NEW';
    }

    public function projectWithout(User $user, string $source): string
    {
        $rows = $this->current($user);
        unset($rows[$source]);
        $highest = AccountLevel::highest(array_column($rows, 'level'));

        return $highest ?? ('ANONYMOUS' === $user->getUserLevel() ? 'ANONYMOUS' : 'NEW');
    }

    /**
     * The stored administrator grant only. A planned reconstruction does not
     * count: deleting a grant that was never written must not invent the others.
     */
    public function storedAdminLevel(User $user): ?string
    {
        if (!$this->managed($user)) {
            $admin = ($this->memory[$user] ?? [])[LevelGrantSource::ADMIN] ?? null;

            return is_array($admin) ? $admin['level'] : null;
        }

        return $this->grants->findOne((int) $user->getId(), LevelGrantSource::ADMIN)?->getLevel();
    }

    /**
     * @return array{kind: string, removed: string, level: string, source: ?string}
     */
    public function drop(User $user, string $source): array
    {
        $removed = $this->current($user)[$source]['level'] ?? 'NEW';
        $this->remove($user, $source);
        $rows = $this->managed($user)
            ? $this->index($this->grants->forUser((int) $user->getId()))
            : ($this->memory[$user] ?? []);
        $winner = $this->winner($rows);

        return [
            'kind' => 'removed',
            'removed' => $removed,
            'level' => $user->getUserLevel(),
            'source' => $winner['source'] ?? null,
        ];
    }

    /**
     * @return list<array{source: string, level: string, created: string, reference: ?string}>
     */
    public function views(User $user): array
    {
        if (!$this->managed($user)) {
            $views = [];
            foreach ($this->memory[$user] ?? [] as $source => $row) {
                $views[] = $this->view((string) $source, $row['level'], $row['created'], $row['ref']);
            }

            return $this->sortViews($views);
        }

        return $this->viewsFor([(int) $user->getId()])[(int) $user->getId()] ?? [];
    }

    /**
     * Writes the legacy plan once. A second call, and a second run of the
     * command, finds the rows and changes nothing.
     */
    public function reconstruct(User $user): bool
    {
        if (!$this->managed($user)) {
            if (isset($this->memory[$user])) {
                return false;
            }
            $plan = $this->legacy->plan($user, false);
            if ([] === $plan->grants) {
                return false;
            }
            $this->memory[$user] = $this->normalize($plan->bySource());
            $this->applyLevel($user, $this->memory[$user]);

            return true;
        }
        if ($this->grants->countFor((int) $user->getId()) > 0) {
            return false;
        }
        $plan = $this->legacy->plan($user, true);
        if ([] === $plan->grants) {
            return false;
        }
        foreach ($plan->bySource() as $source => $row) {
            $this->store($user, $source, $row + ['createdBy' => null]);
        }
        $this->applyLevel($user, $plan->bySource());
        $this->em->flush();

        return true;
    }

    public function hasGrants(User $user): bool
    {
        return $this->managed($user) && $this->grants->countFor((int) $user->getId()) > 0;
    }

    public function plan(User $user): LegacyGrantPlan
    {
        return $this->legacy->plan($user, $this->managed($user));
    }

    /**
     * @param list<int> $userIds
     *
     * @return array<int, list<array{source: string, level: string, created: string, reference: ?string}>>
     */
    public function viewsFor(array $userIds): array
    {
        $grouped = [];
        foreach ($this->grants->forUsers($userIds) as $userId => $entities) {
            $grouped[$userId] = [];
            foreach ($entities as $entity) {
                $grouped[$userId][] = $this->view($entity->getSource(), $entity->getLevel(), $entity->getCreated(), $entity->getReference());
            }
            $grouped[$userId] = $this->sortViews($grouped[$userId]);
        }

        return $grouped;
    }

    /**
     * @param array<string, array{level: string, ref?: ?string, created?: int}> $rows
     *
     * @return array{kind: string, level: string, source: ?string, removed: null}|null
     */
    public function keptNotice(string $requested, array $rows): ?array
    {
        $effective = AccountLevel::highest(array_column($rows, 'level')) ?? 'NEW';
        $winner = $this->winner($rows);
        if (AccountLevel::rank($effective) <= AccountLevel::rank($requested)) {
            return null;
        }
        if (null === $winner || LevelGrantSource::ADMIN === $winner['source']) {
            return null;
        }

        return ['kind' => 'kept', 'level' => $effective, 'source' => $winner['source'], 'removed' => null];
    }

    /**
     * @param callable(array<string, array{level: string, ref: ?string, created: int, createdBy: ?int}>): array<string, array{level: string, ref: ?string, created: int, createdBy: ?int}> $change
     */
    private function mutate(User $user, callable $change): void
    {
        if (!$this->managed($user)) {
            $rows = $change($this->remembered($user));
            $this->memory[$user] = $rows;
            $this->applyLevel($user, $rows);

            return;
        }

        $rows = $change($this->ensureLegacy($user));
        $this->applyLevel($user, $rows);
        $this->em->flush();
    }

    /**
     * @return array<string, array{level: string, ref: ?string, created: int, createdBy: ?int}>
     */
    private function current(User $user): array
    {
        if (!$this->managed($user)) {
            return $this->memory[$user] ?? $this->normalize($this->legacy->plan($user, false)->bySource());
        }
        if ($this->grants->countFor((int) $user->getId()) > 0) {
            return $this->index($this->grants->forUser((int) $user->getId()));
        }

        return $this->normalize($this->legacy->plan($user, true)->bySource());
    }

    /**
     * @return array<string, array{level: string, ref: ?string, created: int, createdBy: ?int}>
     */
    private function remembered(User $user): array
    {
        return $this->memory[$user] ?? $this->normalize($this->legacy->plan($user, false)->bySource());
    }

    /**
     * @return array<string, array{level: string, ref: ?string, created: int, createdBy: ?int}>
     */
    private function ensureLegacy(User $user): array
    {
        $existing = $this->grants->forUser((int) $user->getId());
        if ([] !== $existing) {
            return $this->index($existing);
        }
        $rows = $this->normalize($this->legacy->plan($user, true)->bySource());
        foreach ($rows as $source => $row) {
            $this->store($user, $source, $row);
        }

        return $rows;
    }

    /**
     * @param array<string, array{level: string, ref?: ?string, created?: int, createdBy?: ?int}> $rows
     *
     * @return array<string, array{level: string, ref: ?string, created: int, createdBy: ?int}>
     */
    private function choose(array $rows, string $requested): array
    {
        $rows = $this->normalize($rows);
        if ('ADMIN' !== $requested) {
            unset($rows[LevelGrantSource::BOOTSTRAP]);
        }
        if ('NEW' === $requested) {
            unset($rows[LevelGrantSource::ADMIN]);

            return $rows;
        }
        $rows[LevelGrantSource::ADMIN] = [
            'level' => $requested,
            'ref' => null,
            'created' => $rows[LevelGrantSource::ADMIN]['created'] ?? time(),
            'createdBy' => $rows[LevelGrantSource::ADMIN]['createdBy'] ?? null,
        ];

        return $rows;
    }

    /**
     * @param array{level: string, ref: ?string, created: int, createdBy: ?int} $row
     */
    private function store(User $user, string $source, array $row): void
    {
        if (!$this->managed($user)) {
            return;
        }
        $entity = $this->stored($user, $source) ?? new UserLevelGrant((int) $user->getId(), $source, $row['level']);
        $entity->setLevel($row['level']);
        $entity->setReference($row['ref']);
        $entity->setCreated($row['created']);
        $entity->setCreatedBy($row['createdBy']);
        $this->em->persist($entity);
    }

    private function deleteStored(User $user, string $source): void
    {
        if (!$this->managed($user)) {
            return;
        }
        $entity = $this->stored($user, $source);
        if (null !== $entity) {
            $this->em->remove($entity);
        }
    }

    private function stored(User $user, string $source): ?UserLevelGrant
    {
        $userId = (int) $user->getId();
        foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof UserLevelGrant && $entity->getUserId() === $userId && $entity->getSource() === $source) {
                return $entity;
            }
        }

        return $this->grants->findOne($userId, $source);
    }

    /**
     * @param array<string, array{level: string, ref?: ?string, created?: int, createdBy?: ?int}> $rows
     */
    private function applyLevel(User $user, array $rows): void
    {
        $highest = AccountLevel::highest(array_column($rows, 'level'));
        if (null === $highest) {
            $highest = 'ANONYMOUS' === $user->getUserLevel() ? 'ANONYMOUS' : 'NEW';
        }
        if ($user->getUserLevel() !== $highest) {
            $user->setUserLevel($highest);
        }
    }

    /**
     * @param list<UserLevelGrant> $entities
     *
     * @return array<string, array{level: string, ref: ?string, created: int, createdBy: ?int}>
     */
    private function index(array $entities): array
    {
        $rows = [];
        foreach ($entities as $entity) {
            $rows[$entity->getSource()] = [
                'level' => $entity->getLevel(),
                'ref' => $entity->getReference(),
                'created' => $entity->getCreated(),
                'createdBy' => $entity->getCreatedBy(),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, array{level: string, ref?: ?string, created?: int, createdBy?: ?int}> $rows
     *
     * @return array<string, array{level: string, ref: ?string, created: int, createdBy: ?int}>
     */
    private function normalize(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $source => $row) {
            $normalized[$source] = [
                'level' => strtoupper($row['level']),
                'ref' => $row['ref'] ?? null,
                'created' => $row['created'] ?? time(),
                'createdBy' => $row['createdBy'] ?? null,
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string, array{level: string, ref?: ?string, created?: int}> $rows
     *
     * @return array{source: string, level: string}|null
     */
    private function winner(array $rows): ?array
    {
        $best = null;
        foreach ($rows as $source => $row) {
            $candidate = ['source' => (string) $source, 'level' => strtoupper($row['level'])];
            if (null === $best
                || AccountLevel::rank($candidate['level']) > AccountLevel::rank($best['level'])
                || (AccountLevel::rank($candidate['level']) === AccountLevel::rank($best['level'])
                    && LevelGrantSource::displayRank($candidate['source']) < LevelGrantSource::displayRank($best['source']))
            ) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * @param list<array{source: string, level: string, created: string, reference: ?string}> $views
     *
     * @return list<array{source: string, level: string, created: string, reference: ?string}>
     */
    private function sortViews(array $views): array
    {
        usort(
            $views,
            static fn (array $left, array $right): int => LevelGrantSource::displayRank($left['source']) <=> LevelGrantSource::displayRank($right['source']),
        );

        return $views;
    }

    /**
     * @return array{source: string, level: string, created: string, reference: ?string}
     */
    private function view(string $source, string $level, int $created, ?string $reference): array
    {
        if (LevelGrantSource::IAP === $source || (null !== $reference && strlen($reference) > 64)) {
            $reference = null;
        }

        return [
            'source' => $source,
            'level' => $level,
            'created' => gmdate('c', $created),
            'reference' => $reference,
        ];
    }

    private function managed(User $user): bool
    {
        return null !== $user->getId() && $this->em->contains($user);
    }
}
