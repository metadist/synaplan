<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Config;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Service\Iam\IamConfig;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ConfigControllerPolicyTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();
    }

    public function testLockedDefaultReturns409(): void
    {
        $this->setFlag(IamConfig::KEY_GROUPS_ENABLED, '1');
        $this->setFlag(IamConfig::KEY_GROUP_POLICIES_ENABLED, '1');
        $admin = $this->createUser('iam-policy-lock-admin@synaplan.internal', 'ADMIN');
        $member = $this->createUser('iam-policy-lock-member@synaplan.internal', 'NEW');

        static::getContainer()->get(ConfigRepository::class)
            ->setValue(0, 'DEFAULTMODEL', 'CHAT', 'anthropic:claude-sonnet-5:chat');
        $this->em->flush();

        $this->authenticateClient($this->client, $admin);
        $this->client->request('PATCH', '/api/v1/admin/config/locks', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['DEFAULTMODEL.CHAT' => true], \JSON_THROW_ON_ERROR));
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $this->authenticateClient($this->client, $member);
        $this->client->request('POST', '/api/v1/config/models/defaults', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['defaults' => ['CHAT' => 1]], \JSON_THROW_ON_ERROR));

        self::assertSame(Response::HTTP_CONFLICT, $this->client->getResponse()->getStatusCode());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('iam.settingLocked', $body['code'] ?? null);
    }

    public function testLockingKeyWithoutInstanceDefaultReturns422AndCreatesNoRow(): void
    {
        $this->setFlag(IamConfig::KEY_GROUPS_ENABLED, '1');
        $this->setFlag(IamConfig::KEY_GROUP_POLICIES_ENABLED, '1');
        $admin = $this->createUser('iam-policy-lock-missing-admin@synaplan.internal', 'ADMIN');

        $repo = static::getContainer()->get(ConfigRepository::class);
        $existing = $repo->findByOwnerGroupAndSetting(0, 'RATELIMITS', 'TIER');
        if ($existing instanceof Config) {
            $repo->remove($existing);
        }

        $this->authenticateClient($this->client, $admin);
        $this->client->request('PATCH', '/api/v1/admin/config/locks', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['RATELIMITS.TIER' => true], \JSON_THROW_ON_ERROR));

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('iam.noInstanceDefault', $body['code'] ?? null);
        self::assertNull($repo->findByOwnerGroupAndSetting(0, 'RATELIMITS', 'TIER'));
    }

    public function testUnlockingKeyWithoutInstanceDefaultIsNoOp(): void
    {
        $this->setFlag(IamConfig::KEY_GROUPS_ENABLED, '1');
        $this->setFlag(IamConfig::KEY_GROUP_POLICIES_ENABLED, '1');
        $admin = $this->createUser('iam-policy-unlock-missing-admin@synaplan.internal', 'ADMIN');

        $repo = static::getContainer()->get(ConfigRepository::class);
        $existing = $repo->findByOwnerGroupAndSetting(0, 'RATELIMITS', 'TIER');
        if ($existing instanceof Config) {
            $repo->remove($existing);
        }

        $this->authenticateClient($this->client, $admin);
        $this->client->request('PATCH', '/api/v1/admin/config/locks', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['RATELIMITS.TIER' => false], \JSON_THROW_ON_ERROR));

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($body['locks']['RATELIMITS.TIER'] ?? true);
        self::assertNull($repo->findByOwnerGroupAndSetting(0, 'RATELIMITS', 'TIER'));
    }

    private function setFlag(string $setting, string $value): void
    {
        static::getContainer()->get(ConfigRepository::class)
            ->setValue(0, IamConfig::CONFIG_GROUP, $setting, $value);
        $this->em->flush();
    }

    private function createUser(string $email, string $level): User
    {
        $existing = $this->em->getRepository(User::class)->findOneBy(['mail' => $email]);
        if ($existing instanceof User) {
            $existing->setUserLevel($level);
            $this->em->flush();

            return $existing;
        }

        $user = (new User())
            ->setMail($email)
            ->setType('WEB')
            ->setProviderId('iam-policy-'.uniqid())
            ->setUserLevel($level);
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
