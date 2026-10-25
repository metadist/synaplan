<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\SignupCode;
use App\Entity\SignupCodeUse;
use App\Repository\SignupCodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Sign-up codes against the real schema (admission sign-up, AS1): the
 * normal form of what a person types, the hash lookup, and the atomic
 * redeem whose guard lives in the UPDATE predicate.
 */
final class SignupCodeRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SignupCodeRepository $codes;
    /** @var list<int> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->codes = static::getContainer()->get(SignupCodeRepository::class);
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        foreach ($this->createdIds as $id) {
            $conn->executeStatement('DELETE FROM BSIGNUP_CODE_USES WHERE BCODEID = ?', [$id]);
            $conn->executeStatement('DELETE FROM BSIGNUP_CODES WHERE BID = ?', [$id]);
        }
        parent::tearDown();
    }

    public function testGeneratedCodesUseTheUnambiguousAlphabetAndNormalizeFromWhatPeopleType(): void
    {
        $code = SignupCodeRepository::generateCode();

        self::assertSame(SignupCode::LENGTH, strlen($code));
        self::assertSame(SignupCode::LENGTH, strspn($code, SignupCode::ALPHABET), 'no 0, O, 1 or I');

        $typed = strtolower(substr($code, 0, 4)).' - '.substr($code, 4, 4)."\t".substr($code, 8);
        self::assertSame($code, SignupCodeRepository::normalize($typed));
        self::assertNull(SignupCodeRepository::normalize('too short'));
        self::assertNull(SignupCodeRepository::normalize('ABCDEFGHJKL0'), 'a zero is not in the alphabet');
        self::assertNull(SignupCodeRepository::normalize(str_repeat('A', 13)));
    }

    public function testOnlyARedeemableCodeIsFound(): void
    {
        [$plain, $live] = $this->persistCode(maxUses: 2);
        [$expiredPlain, $expired] = $this->persistCode(expires: time() - 60);
        [$revokedPlain, $revoked] = $this->persistCode(active: false);

        self::assertSame($live->getId(), $this->codes->findRedeemable($plain)?->getId());
        self::assertSame($live->getId(), $this->codes->findRedeemable(strtolower($plain))?->getId(), 'case does not matter');
        self::assertNull($this->codes->findRedeemable($expiredPlain));
        self::assertNull($this->codes->findRedeemable($revokedPlain));
        self::assertNull($this->codes->findRedeemable('NOPE'));
        self::assertNull($this->codes->findRedeemable(SignupCodeRepository::generateCode()), 'an unknown but well-formed code');
        self::assertNotNull($expired->getId());
        self::assertNotNull($revoked->getId());
    }

    public function testRedeemSpendsExactlyMaxUsesAndRecordsEveryUse(): void
    {
        [, $code] = $this->persistCode(maxUses: 2, level: 'TEAM');

        $first = $this->codes->redeem($code, 1001);
        $second = $this->codes->redeem($code, 1002);
        $third = $this->codes->redeem($code, 1003);
        $this->em->flush();

        self::assertInstanceOf(SignupCodeUse::class, $first);
        self::assertInstanceOf(SignupCodeUse::class, $second);
        self::assertNull($third, 'the guard in the UPDATE predicate refuses the third use');
        self::assertSame(2, $code->getUses());
        self::assertTrue($code->isExhausted());
        self::assertFalse($code->isRedeemable());

        $uses = $this->codes->usesOf($code);
        self::assertCount(2, $uses);
        self::assertSame('TEAM', $uses[0]->getLevel(), 'the tier at the moment of redemption is recorded');
        self::assertEqualsCanonicalizing([1001, 1002], array_map(static fn (SignupCodeUse $u) => $u->getUserId(), $uses));
    }

    public function testRedeemRefusesAnExpiredOrRevokedCodeEvenWhenTheEntityLooksFresh(): void
    {
        [, $code] = $this->persistCode();
        $this->em->getConnection()->executeStatement('UPDATE BSIGNUP_CODES SET BACTIVE = 0 WHERE BID = ?', [$code->getId()]);

        self::assertNull($this->codes->redeem($code, 2001), 'the database state decides, not the in-memory entity');
        self::assertSame(0, $code->getUses());
    }

    public function testUnlimitedCodesHaveNoCeiling(): void
    {
        [, $code] = $this->persistCode(maxUses: null);
        for ($i = 0; $i < 5; ++$i) {
            self::assertNotNull($this->codes->redeem($code, 3000 + $i));
        }
        $this->em->flush();

        self::assertSame(5, $code->getUses());
        self::assertFalse($code->isExhausted());
    }

    /**
     * @return array{0: string, 1: SignupCode}
     */
    private function persistCode(?int $maxUses = 1, ?int $expires = null, bool $active = true, string $level = SignupCode::LEVEL_MEMBER): array
    {
        $plain = SignupCodeRepository::generateCode();
        $code = (new SignupCode())
            ->setCodeHash(SignupCodeRepository::hash($plain))
            ->setPrefix(substr($plain, 0, SignupCode::PREFIX_LENGTH))
            ->setLabel('test '.$plain)
            ->setLevel($level)
            ->setMaxUses($maxUses)
            ->setExpires($expires)
            ->setActive($active)
            ->setCreatedBy(1);
        $this->codes->save($code);
        $this->createdIds[] = (int) $code->getId();

        return [$plain, $code];
    }
}
