<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\SignupCode;
use App\Entity\SignupCodeUse;
use App\Entity\User;
use App\Repository\SignupCodeRepository;
use App\Repository\UserRepository;
use App\Service\Iam\AuditLogWriter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What an administrator does with sign-up codes: issue one, show it again
 * to copy it, change its label, limit or expiry, revoke or restore it, and
 * see who used it. Revoking keeps
 * the row and its uses; the people who signed up keep their accounts.
 * Every write and its audit row commit together or not at all.
 */
final readonly class SignupCodeAdminService
{
    public const VALIDITY_FOREVER = 'forever';
    public const VALIDITY_WEEK = 'week';
    public const VALIDITY_FIVE_MONTHS = 'fiveMonths';
    public const VALIDITIES = [self::VALIDITY_FOREVER, self::VALIDITY_WEEK, self::VALIDITY_FIVE_MONTHS];

    public const STATE_ACTIVE = 'active';
    public const STATE_EXPIRED = 'expired';
    public const STATE_USED_UP = 'used_up';
    public const STATE_REVOKED = 'revoked';

    public const LABEL_MAX_LENGTH = 120;
    public const DEFAULT_MAX_USES = 1;

    private const RESOURCE_KIND = 'signup_code';
    private const GENERATE_ATTEMPTS = 5;

    public function __construct(
        private SignupCodeRepository $codes,
        private UserRepository $users,
        private AuditLogWriter $audit,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(?int $now = null): array
    {
        $codes = $this->codes->findAllNewestFirst();
        $emails = $this->emailsOf(array_map(static fn (SignupCode $code): int => $code->getCreatedBy(), $codes));

        return array_map(fn (SignupCode $code): array => $this->present($code, $emails, $now), $codes);
    }

    /**
     * $maxUses: a whole number of uses, or null for no limit.
     *
     * @return array{code: string, signupCode: array<string, mixed>}
     */
    public function create(User $actor, string $label, string $level, string $validity, mixed $maxUses = self::DEFAULT_MAX_USES, string $ip = '', ?int $now = null): array
    {
        $now ??= time();
        $code = new SignupCode();
        $code->setLabel($this->validLabel($label));
        $code->setLevel($this->validLevel($level));
        $code->setMaxUses($this->validMaxUses($this->nullableInt($maxUses, 'maxUses')));
        $code->setExpires($this->expiryFor($validity, $now));
        $code->setCreatedBy((int) $actor->getId());

        $plain = $this->uniqueCode();
        $code->setCodeHash(SignupCodeRepository::hash($plain));
        $code->setCode($plain);
        $code->setPrefix(substr($plain, 0, SignupCode::PREFIX_LENGTH));
        $this->em->wrapInTransaction(function () use ($code, $actor, $ip): void {
            $this->codes->save($code);
            $this->audit->record((int) $actor->getId(), 'signup_code.created', self::RESOURCE_KIND, (string) $code->getId(), [
                'prefix' => $code->getPrefix(),
                'level' => $code->getLevel(),
                'maxUses' => $code->getMaxUses(),
                'expiresAt' => $code->getExpires(),
            ], $ip);
        });

        return [
            'code' => self::display($plain),
            'signupCode' => $this->present($code, [(int) $actor->getId() => $actor->getMail()], $now),
        ];
    }

    /**
     * Applies only the keys present in $changes: `label`, `maxUses` (null =
     * no limit), `expiresAt` (Unix seconds, null = never) and `active`.
     *
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    public function update(User $actor, int $id, array $changes, string $ip = '', ?int $now = null): array
    {
        $code = $this->find($id);
        if (array_key_exists('label', $changes)) {
            $code->setLabel($this->validLabel(is_string($changes['label']) ? $changes['label'] : ''));
        }
        if (array_key_exists('maxUses', $changes)) {
            $code->setMaxUses($this->validMaxUses($this->nullableInt($changes['maxUses'], 'maxUses')));
        }
        if (array_key_exists('expiresAt', $changes)) {
            $code->setExpires($this->nullableInt($changes['expiresAt'], 'expiresAt'));
        }
        if (array_key_exists('active', $changes)) {
            if (!is_bool($changes['active'])) {
                throw SignupCodeAdminException::invalid('active must be true or false.');
            }
            $code->setActive($changes['active']);
        }
        $this->em->wrapInTransaction(function () use ($code, $actor, $id, $changes, $ip): void {
            $this->codes->save($code);
            $this->audit->record((int) $actor->getId(), 'signup_code.updated', self::RESOURCE_KIND, (string) $id, [
                'prefix' => $code->getPrefix(),
                'changed' => array_values(array_intersect(['label', 'maxUses', 'expiresAt', 'active'], array_keys($changes))),
            ], $ip);
        });

        return $this->present($code, $this->emailsOf([$code->getCreatedBy()]), $now);
    }

    /**
     * Nobody can use the code any more; its row and its uses stay.
     *
     * @return array<string, mixed>
     */
    public function revoke(User $actor, int $id, string $ip = '', ?int $now = null): array
    {
        $code = $this->find($id);
        $code->setActive(false);
        $this->em->wrapInTransaction(function () use ($code, $actor, $id, $ip): void {
            $this->codes->save($code);
            $this->audit->record((int) $actor->getId(), 'signup_code.revoked', self::RESOURCE_KIND, (string) $id, [
                'prefix' => $code->getPrefix(),
                'uses' => $code->getUses(),
            ], $ip);
        });

        return $this->present($code, $this->emailsOf([$code->getCreatedBy()]), $now);
    }

    /**
     * The code in groups of four for the administrator to copy. Each look is
     * an audit row. A code created before codes were kept has none.
     *
     * @throws SignupCodeAdminException
     */
    public function reveal(User $actor, int $id, string $ip = ''): string
    {
        $code = $this->find($id);
        $plain = $code->getCode();
        if (null === $plain) {
            throw SignupCodeAdminException::notStored($id);
        }
        $this->audit->record((int) $actor->getId(), 'signup_code.shown', self::RESOURCE_KIND, (string) $id, [
            'prefix' => $code->getPrefix(),
        ], $ip);

        return self::display($plain);
    }

    /**
     * The accounts that signed up with this code, newest first. An erased
     * account has no row here any more; the code's counter still counts it.
     *
     * @return list<array{id: int, userId: int, email: string|null, level: string, usedAt: int}>
     */
    public function uses(int $id): array
    {
        $uses = $this->codes->usesOf($this->find($id));
        $emails = $this->emailsOf(array_map(static fn (SignupCodeUse $use): int => $use->getUserId(), $uses));

        return array_map(static fn (SignupCodeUse $use): array => [
            'id' => (int) $use->getId(),
            'userId' => $use->getUserId(),
            'email' => $emails[$use->getUserId()] ?? null,
            'level' => $use->getLevel(),
            'usedAt' => $use->getUsedAt(),
        ], $uses);
    }

    /** Groups of four for reading aloud; redemption ignores the dashes. */
    public static function display(string $code): string
    {
        return implode('-', str_split($code, SignupCode::PREFIX_LENGTH));
    }

    public static function stateOf(SignupCode $code, ?int $now = null): string
    {
        return match (true) {
            !$code->isActive() => self::STATE_REVOKED,
            $code->isExpired($now) => self::STATE_EXPIRED,
            $code->isExhausted() => self::STATE_USED_UP,
            default => self::STATE_ACTIVE,
        };
    }

    /**
     * @param array<int, string> $emails
     *
     * @return array<string, mixed>
     */
    private function present(SignupCode $code, array $emails, ?int $now): array
    {
        return [
            'id' => (int) $code->getId(),
            'prefix' => $code->getPrefix(),
            'label' => $code->getLabel(),
            'level' => $code->getLevel(),
            'maxUses' => $code->getMaxUses(),
            'uses' => $code->getUses(),
            'expiresAt' => $code->getExpires(),
            'active' => $code->isActive(),
            'codeStored' => null !== $code->getCode(),
            'state' => self::stateOf($code, $now),
            'createdAt' => $code->getCreated(),
            'createdById' => $code->getCreatedBy(),
            'createdByEmail' => $emails[$code->getCreatedBy()] ?? null,
        ];
    }

    private function find(int $id): SignupCode
    {
        $code = $this->codes->find($id);
        if (!$code instanceof SignupCode) {
            throw SignupCodeAdminException::notFound($id);
        }

        return $code;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, string>
     */
    private function emailsOf(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ([] === $ids) {
            return [];
        }
        $emails = [];
        foreach ($this->users->findBy(['id' => $ids]) as $user) {
            $emails[(int) $user->getId()] = $user->getMail();
        }

        return $emails;
    }

    private function uniqueCode(): string
    {
        for ($attempt = 0; $attempt < self::GENERATE_ATTEMPTS; ++$attempt) {
            $plain = SignupCodeRepository::generateCode();
            if (null === $this->codes->findOneBy(['codeHash' => SignupCodeRepository::hash($plain)])) {
                return $plain;
            }
        }

        throw new \RuntimeException(sprintf('Could not generate an unused sign-up code in %d attempts.', self::GENERATE_ATTEMPTS));
    }

    private function validLabel(string $label): string
    {
        $label = trim($label);
        if ('' === $label || mb_strlen($label) > self::LABEL_MAX_LENGTH) {
            throw SignupCodeAdminException::invalid(sprintf('A label of 1 to %d characters is required.', self::LABEL_MAX_LENGTH));
        }

        return $label;
    }

    private function validLevel(string $level): string
    {
        if (!in_array($level, SignupCode::LEVELS, true)) {
            throw SignupCodeAdminException::invalid(sprintf('Unknown level "%s"; expected one of %s.', $level, implode(', ', SignupCode::LEVELS)));
        }

        return $level;
    }

    private function validMaxUses(?int $maxUses): ?int
    {
        if (null !== $maxUses && $maxUses < 1) {
            throw SignupCodeAdminException::invalid('maxUses must be at least 1, or null for no limit.');
        }

        return $maxUses;
    }

    private function expiryFor(string $validity, int $now): ?int
    {
        return match ($validity) {
            self::VALIDITY_FOREVER => null,
            self::VALIDITY_WEEK => (new \DateTimeImmutable('@'.$now))->modify('+7 days')->getTimestamp(),
            self::VALIDITY_FIVE_MONTHS => (new \DateTimeImmutable('@'.$now))->modify('+5 months')->getTimestamp(),
            default => throw SignupCodeAdminException::invalid(sprintf('Unknown validity "%s"; expected one of %s.', $validity, implode(', ', self::VALIDITIES))),
        };
    }

    private function nullableInt(mixed $value, string $field): ?int
    {
        if (null === $value || is_int($value)) {
            return $value;
        }

        throw SignupCodeAdminException::invalid(sprintf('%s must be a whole number or null.', $field));
    }
}
