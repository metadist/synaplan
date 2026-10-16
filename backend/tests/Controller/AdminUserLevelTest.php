<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditLogEntry;
use App\Entity\User;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * #2059 — changing an account level needs a server-side self-change block, a
 * last-administrator guard, and an audit row. (The confirmation dialog and the
 * select revert on cancel/failure live in UsersTab.vue.).
 */
final class AdminUserLevelTest extends WebTestCase
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

    public function testNonAdminCannotChangeLevels(): void
    {
        $user = $this->createUser('level-nonauthor@synaplan.internal', 'NEW');
        $target = $this->createUser('level-nonauthor-target@synaplan.internal', 'NEW');
        $this->authenticateClient($this->client, $user);

        $this->patchLevel((int) $target->getId(), 'PRO');

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    public function testAdminCannotChangeOwnLevel(): void
    {
        // A second admin exists so this hits the self-change block (403),
        // not the last-administrator guard (409).
        $this->createUser('level-self-other@synaplan.internal', 'ADMIN');
        $admin = $this->createUser('level-self@synaplan.internal', 'ADMIN');
        $this->authenticateClient($this->client, $admin);

        $this->patchLevel((int) $admin->getId(), 'PRO');

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
        self::assertSame('ADMIN', $this->freshLevel($admin));
    }

    public function testCannotDemoteTheLastAdministrator(): void
    {
        $lastAdmin = $this->createUser('level-last-admin@synaplan.internal', 'ADMIN');
        $this->authenticateClient($this->client, $lastAdmin);

        // Demoting the only administrator is necessarily a self-demotion; it
        // must report the operational reason. Other admins are demoted for the
        // duration of the request and restored afterwards so the shared suite
        // database is left untouched.
        $demoted = [];
        foreach ($this->em->getRepository(User::class)->findBy(['userLevel' => 'ADMIN']) as $admin) {
            if ($admin instanceof User && $admin->getId() !== $lastAdmin->getId()) {
                $admin->setUserLevel('NEW');
                $demoted[] = $admin;
            }
        }
        $this->em->flush();

        try {
            $this->patchLevel((int) $lastAdmin->getId(), 'PRO');

            self::assertSame(Response::HTTP_CONFLICT, $this->client->getResponse()->getStatusCode());
            self::assertSame('ADMIN', $this->freshLevel($lastAdmin));
        } finally {
            foreach ($demoted as $admin) {
                $admin->setUserLevel('ADMIN');
            }
            $this->em->flush();
        }
    }

    public function testAdminCanDemoteAnotherAdminWhenOthersRemain(): void
    {
        $actor = $this->createUser('level-demoter-actor@synaplan.internal', 'ADMIN');
        $target = $this->createUser('level-demoter-target@synaplan.internal', 'ADMIN');
        $this->createUser('level-demoter-spare@synaplan.internal', 'ADMIN');
        $this->authenticateClient($this->client, $actor);

        $this->patchLevel((int) $target->getId(), 'PRO');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame('PRO', $this->freshLevel($target));
    }

    public function testLevelChangeWritesAnAuditRow(): void
    {
        $admin = $this->createUser('level-audit-admin@synaplan.internal', 'ADMIN');
        $target = $this->createUser('level-audit-target@synaplan.internal', 'NEW');
        $this->authenticateClient($this->client, $admin);

        $this->patchLevel((int) $target->getId(), 'PRO');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame('PRO', $this->freshLevel($target));

        $entry = $this->em->getRepository(AuditLogEntry::class)->findOneBy(
            ['action' => 'admin.user_level_change', 'resourceId' => (string) $target->getId()],
            ['id' => 'DESC']
        );
        self::assertInstanceOf(AuditLogEntry::class, $entry);
        self::assertSame((int) $admin->getId(), $entry->getActorId());
        self::assertSame('NEW', $entry->getSubject()['old_level'] ?? null);
        self::assertSame('PRO', $entry->getSubject()['new_level'] ?? null);
    }

    private function patchLevel(int $id, string $level): void
    {
        $this->client->request(
            'PATCH',
            '/api/v1/admin/users/'.$id.'/level',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['level' => $level])
        );
    }

    private function freshLevel(User $user): string
    {
        $this->em->refresh($user);

        return $user->getUserLevel();
    }

    private function createUser(string $email, string $level): User
    {
        $existing = $this->em->getRepository(User::class)->findOneBy(['mail' => $email]);
        if ($existing instanceof User) {
            if ($existing->getUserLevel() !== $level) {
                $existing->setUserLevel($level);
                $this->em->flush();
            }

            return $existing;
        }

        $user = (new User())
            ->setMail($email)
            ->setType('WEB')
            ->setProviderId('level-test-'.uniqid())
            ->setUserLevel($level);
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
