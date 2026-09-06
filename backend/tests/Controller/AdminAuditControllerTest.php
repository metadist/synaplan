<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditLogEntry;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Service\Iam\IamConfig;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class AdminAuditControllerTest extends WebTestCase
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

    public function test404WhenGroupsOff(): void
    {
        $this->setGroupsFlag('0');
        $admin = $this->createAdmin('iam-audit-off@synaplan.internal');
        $this->authenticateClient($this->client, $admin);

        $this->client->request('GET', '/api/v1/admin/audit');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testListsMetadataOnlyNewestFirst(): void
    {
        $this->setGroupsFlag('1');
        $admin = $this->createAdmin('iam-audit-on@synaplan.internal');
        $older = new AuditLogEntry();
        $older->setActorId((int) $admin->getId());
        $older->setAction('share.grant');
        $older->setResourceKind('conversation');
        $older->setResourceId('1');
        $older->setSubject(['permission' => 'use']);
        $newer = new AuditLogEntry();
        $newer->setActorId((int) $admin->getId());
        $newer->setAction('impersonation.start');
        $newer->setResourceKind('user');
        $newer->setResourceId((string) $admin->getId());
        $newer->setSubject(['targetUserId' => 9]);
        $this->em->persist($older);
        $this->em->flush();
        $this->em->persist($newer);
        $this->em->flush();

        $this->authenticateClient($this->client, $admin);
        $this->client->request('GET', '/api/v1/admin/audit?limit=10');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertArrayHasKey('entries', $payload);
        $actions = array_column($payload['entries'], 'action');
        self::assertContains('impersonation.start', $actions);
        self::assertContains('share.grant', $actions);
        foreach ($payload['entries'] as $entry) {
            self::assertArrayNotHasKey('content', $entry);
            self::assertArrayHasKey('subject', $entry);
        }
    }

    private function setGroupsFlag(string $value): void
    {
        static::getContainer()->get(ConfigRepository::class)
            ->setValue(0, IamConfig::CONFIG_GROUP, IamConfig::KEY_GROUPS_ENABLED, $value);
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
            ->setProviderId('iam-test-'.uniqid())
            ->setUserLevel('ADMIN');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
