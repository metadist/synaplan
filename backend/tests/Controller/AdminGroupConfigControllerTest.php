<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Service\Iam\IamConfig;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class AdminGroupConfigControllerTest extends WebTestCase
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

    public function test404WhenFlagOff(): void
    {
        $this->setFlag(IamConfig::KEY_GROUPS_ENABLED, '1');
        $this->setFlag(IamConfig::KEY_GROUP_POLICIES_ENABLED, '0');
        $admin = $this->createAdmin('iam-policy-off@synaplan.internal');
        $this->authenticateClient($this->client, $admin);
        $this->client->request('POST', '/api/v1/admin/groups', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['name' => 'Support Off'], \JSON_THROW_ON_ERROR));
        $groupId = json_decode((string) $this->client->getResponse()->getContent(), true)['group']['id'] ?? 0;

        $this->client->request('GET', '/api/v1/admin/groups/'.$groupId.'/config');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testReturnsSettingsOnly(): void
    {
        $this->setFlag(IamConfig::KEY_GROUPS_ENABLED, '1');
        $this->setFlag(IamConfig::KEY_GROUP_POLICIES_ENABLED, '1');
        $admin = $this->createAdmin('iam-policy-on@synaplan.internal');
        $this->authenticateClient($this->client, $admin);
        $this->client->request('POST', '/api/v1/admin/groups', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['name' => 'Support On'], \JSON_THROW_ON_ERROR));
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $groupId = $payload['group']['id'];

        $this->client->request('GET', '/api/v1/admin/groups/'.$groupId.'/config');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('settings', $body);
        self::assertArrayHasKey('DEFAULTMODEL.CHAT', $body['settings']);
        self::assertArrayHasKey('value', $body['settings']['DEFAULTMODEL.CHAT']);
        self::assertArrayHasKey('source', $body['settings']['DEFAULTMODEL.CHAT']);
        self::assertArrayHasKey('locked', $body['settings']['DEFAULTMODEL.CHAT']);
        self::assertArrayNotHasKey('content', $body);
        self::assertArrayNotHasKey('messages', $body);
    }

    private function setFlag(string $setting, string $value): void
    {
        static::getContainer()->get(ConfigRepository::class)
            ->setValue(0, IamConfig::CONFIG_GROUP, $setting, $value);
        $this->em->flush();
    }

    private function createAdmin(string $email): User
    {
        $existing = $this->em->getRepository(User::class)->findOneBy(['mail' => $email]);
        if ($existing instanceof User) {
            $existing->setUserLevel('ADMIN');
            $this->em->flush();

            return $existing;
        }

        $user = (new User())
            ->setMail($email)
            ->setType('WEB')
            ->setProviderId('iam-policy-'.uniqid())
            ->setUserLevel('ADMIN');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
