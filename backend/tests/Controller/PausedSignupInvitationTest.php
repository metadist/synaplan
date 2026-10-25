<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\SignupCode;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\SignupCodeRepository;
use App\Service\Auth\RegistrationConfig;
use App\Service\Auth\SignupPauseSwitch;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The pause stops strangers, not people an administrator invited: in
 * admission mode a valid invitation code still opens an account while
 * sign-up is paused, at the code's level, and spends one use. Everything
 * else stays refused.
 */
final class PausedSignupInvitationTest extends WebTestCase
{
    private const DOMAIN = '@paused-invite.synaplan.internal';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    /** @var list<int> */
    private array $codeIds = [];

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->setConfig(SignupPauseSwitch::CONFIG_GROUP, SignupPauseSwitch::KEY_PAUSED, 'true');
        $this->setConfig(RegistrationConfig::CONFIG_GROUP, RegistrationConfig::KEY_MODE, RegistrationConfig::MODE_ADMISSION);
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM BCONFIG WHERE BOWNERID = 0 AND ((BGROUP = ? AND BSETTING = ?) OR (BGROUP = ? AND BSETTING = ?))', [
            SignupPauseSwitch::CONFIG_GROUP, SignupPauseSwitch::KEY_PAUSED,
            RegistrationConfig::CONFIG_GROUP, RegistrationConfig::KEY_MODE,
        ]);
        foreach ($this->codeIds as $id) {
            $conn->executeStatement('DELETE FROM BSIGNUP_CODE_USES WHERE BCODEID = ?', [$id]);
            $conn->executeStatement('DELETE FROM BSIGNUP_CODES WHERE BID = ?', [$id]);
        }
        $conn->executeStatement('DELETE FROM BVERIFICATION_TOKENS WHERE BUID IN (SELECT BID FROM BUSER WHERE BMAIL LIKE ?)', ['%'.self::DOMAIN]);
        $conn->executeStatement('DELETE FROM BUSER WHERE BMAIL LIKE ?', ['%'.self::DOMAIN]);
        parent::tearDown();
    }

    public function testAValidCodeOpensAnAccountAtItsLevelWhileSignUpIsPaused(): void
    {
        [$plain, $codeId] = $this->code('PRO', 7);

        $this->register('invited'.self::DOMAIN, strtolower($plain));

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['mail' => 'invited'.self::DOMAIN]);
        self::assertInstanceOf(User::class, $user);
        self::assertSame('PRO', $user->getUserLevel());
        $code = $this->em->getRepository(SignupCode::class)->find($codeId);
        self::assertInstanceOf(SignupCode::class, $code);
        self::assertSame(1, $code->getUses());
    }

    public function testWithoutAValidCodeThePauseStillRefuses(): void
    {
        $this->register('stranger'.self::DOMAIN, null);
        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $this->client->getResponse()->getStatusCode());
        self::assertSame('SIGNUP_PAUSED', $this->json()['code']);

        $this->register('guesser'.self::DOMAIN, SignupCodeRepository::generateCode());
        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $this->client->getResponse()->getStatusCode());
        self::assertSame('SIGNUP_PAUSED', $this->json()['code'], 'an unknown code does not get past the pause');

        [$revoked] = $this->code('PRO', 7, active: false);
        $this->register('revoked'.self::DOMAIN, $revoked);
        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $this->client->getResponse()->getStatusCode());

        self::assertSame(0, $this->em->getRepository(User::class)->count(['mail' => 'stranger'.self::DOMAIN]));
    }

    public function testInOpenModeACodeDoesNotGetPastThePause(): void
    {
        $this->setConfig(RegistrationConfig::CONFIG_GROUP, RegistrationConfig::KEY_MODE, RegistrationConfig::MODE_OPEN);
        [$plain] = $this->code('PRO', 7);

        $this->register('open-mode'.self::DOMAIN, $plain);

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $this->client->getResponse()->getStatusCode());
        self::assertSame('SIGNUP_PAUSED', $this->json()['code']);
    }

    private function register(string $email, ?string $code): void
    {
        $body = ['email' => $email, 'password' => 'Invited123!'];
        if (null !== $code) {
            $body['code'] = $code;
        }
        $this->client->request('POST', '/api/v1/auth/register', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function code(string $level, int $maxUses, bool $active = true): array
    {
        $plain = SignupCodeRepository::generateCode();
        $code = (new SignupCode())
            ->setCodeHash(SignupCodeRepository::hash($plain))
            ->setPrefix(substr($plain, 0, SignupCode::PREFIX_LENGTH))
            ->setLabel('Paused invitation test')
            ->setLevel($level)
            ->setMaxUses($maxUses)
            ->setActive($active)
            ->setCreatedBy(1);
        static::getContainer()->get(SignupCodeRepository::class)->save($code);
        $this->codeIds[] = (int) $code->getId();

        return [$plain, (int) $code->getId()];
    }

    private function setConfig(string $group, string $key, string $value): void
    {
        static::getContainer()->get(ConfigRepository::class)->setValue(0, $group, $key, $value);
        $this->em->flush();
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($payload);

        return $payload;
    }
}
