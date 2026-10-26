<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SignupCode;
use App\Entity\SignupCodeUse;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SignupCode>
 */
class SignupCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SignupCode::class);
    }

    /**
     * Generate a code string from the unambiguous alphabet (no 0/O/1/I).
     */
    public static function generateCode(): string
    {
        $alphabet = SignupCode::ALPHABET;
        $max = strlen($alphabet) - 1;
        $code = '';
        for ($i = 0; $i < SignupCode::LENGTH; ++$i) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }

    /**
     * Canonical form of what a person typed: upper case, no spaces or dashes.
     * Returns null when the result is not a possible code, so no hash is
     * computed and no row is looked up for garbage.
     */
    public static function normalize(string $input): ?string
    {
        $code = strtoupper(preg_replace('/[\s-]+/', '', $input) ?? '');
        if (SignupCode::LENGTH !== strlen($code)) {
            return null;
        }
        if (SignupCode::LENGTH !== strspn($code, SignupCode::ALPHABET)) {
            return null;
        }

        return $code;
    }

    public static function hash(string $code): string
    {
        return hash('sha256', $code);
    }

    /**
     * The code a person typed, if it exists and may still be redeemed.
     * Expired, exhausted, revoked and unknown codes all answer null; the
     * caller must not tell them apart to the outside.
     */
    public function findRedeemable(string $input, ?int $now = null): ?SignupCode
    {
        $code = self::normalize($input);
        if (null === $code) {
            return null;
        }
        $entity = $this->findOneBy(['codeHash' => self::hash($code)]);

        return $entity instanceof SignupCode && $entity->isRedeemable($now) ? $entity : null;
    }

    /**
     * Spend one use atomically. The guard is in the UPDATE's predicate, so two
     * nodes redeeming the last use at once cannot both succeed (Galera-safe
     * without a lock). Returns the use row on success, null when the code
     * was no longer redeemable at that instant; the caller treats null
     * exactly like an invalid code.
     */
    public function redeem(SignupCode $code, int $userId, ?int $now = null): ?SignupCodeUse
    {
        $now ??= time();
        $conn = $this->getEntityManager()->getConnection();
        $affected = $conn->executeStatement(
            'UPDATE BSIGNUP_CODES
                SET BUSES = BUSES + 1
              WHERE BID = :id
                AND BACTIVE = 1
                AND (BMAXUSES IS NULL OR BUSES < BMAXUSES)
                AND (BEXPIRES IS NULL OR BEXPIRES > :now)',
            ['id' => $code->getId(), 'now' => $now],
        );
        if (1 !== $affected) {
            return null;
        }

        $uses = (int) $conn->fetchOne('SELECT BUSES FROM BSIGNUP_CODES WHERE BID = :id', ['id' => $code->getId()]);
        $code->syncUses($uses);

        $use = new SignupCodeUse((int) $code->getId(), $userId, $code->getLevel(), $now);
        $this->getEntityManager()->persist($use);

        return $use;
    }

    /**
     * @return list<SignupCodeUse>
     */
    public function usesOf(SignupCode $code): array
    {
        /** @var list<SignupCodeUse> $uses */
        $uses = $this->getEntityManager()->getRepository(SignupCodeUse::class)
            ->findBy(['codeId' => (int) $code->getId()], ['usedAt' => 'DESC']);

        return $uses;
    }

    /**
     * @return list<SignupCode>
     */
    public function findAllNewestFirst(): array
    {
        /** @var list<SignupCode> $codes */
        $codes = $this->findBy([], ['created' => 'DESC']);

        return $codes;
    }

    public function save(SignupCode $code, bool $flush = true): void
    {
        $this->getEntityManager()->persist($code);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
