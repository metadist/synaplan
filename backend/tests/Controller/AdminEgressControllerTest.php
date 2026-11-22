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
 * Allow and Remove on Admin › Capacity › Outbound (AU33, journey J-AU-3):
 * only an administrator edits EGRESS.ALLOW, each change is audited, and a
 * rule that is not a destination is refused.
 */
final class AdminEgressControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private const PATH = '/api/v1/admin/egress/allow';
    private const RULE = 'capacity-allow-test.example.com';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string|false $previous = false;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->previous = $this->em->getConnection()->fetchOne(
            "SELECT BVALUE FROM BCONFIG WHERE BOWNERID = 0 AND BGROUP = 'EGRESS' AND BSETTING = 'ALLOW'",
        );
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        if (false === $this->previous) {
            $conn->executeStatement("DELETE FROM BCONFIG WHERE BOWNERID = 0 AND BGROUP = 'EGRESS' AND BSETTING = 'ALLOW'");
        } else {
            $conn->executeStatement(
                "UPDATE BCONFIG SET BVALUE = ? WHERE BOWNERID = 0 AND BGROUP = 'EGRESS' AND BSETTING = 'ALLOW'",
                [$this->previous],
            );
        }
        $conn->executeStatement("DELETE FROM BAUDITLOG WHERE BRESOURCEKIND = 'egress_rule' AND BRESOURCEID = ?", [self::RULE]);
        parent::tearDown();
    }

    public function testARegularUserIsRefused(): void
    {
        $this->authenticateClient($this->client, $this->createUser('egress-allow-member@synaplan.internal', 'NEW'));

        $this->send('POST', ['rule' => self::RULE]);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
        $this->send('DELETE', ['rule' => self::RULE]);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    public function testAllowThenRemoveEditsTheListAndAuditsBoth(): void
    {
        $admin = $this->createUser('egress-allow-admin@synaplan.internal', 'ADMIN');
        $this->authenticateClient($this->client, $admin);

        $this->send('POST', ['rule' => strtoupper(self::RULE)]);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $added = $this->json();
        self::assertTrue($added['success']);
        self::assertContains(self::RULE, $added['rules']);
        self::assertSame(1, $this->auditCount('egress.allow_added', $admin));

        $this->send('POST', ['rule' => self::RULE]);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->auditCount('egress.allow_added', $admin), 'allowing twice changes nothing');

        $this->send('DELETE', ['rule' => self::RULE]);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertNotContains(self::RULE, $this->json()['rules']);
        self::assertSame(1, $this->auditCount('egress.allow_removed', $admin));
    }

    public function testARuleThatIsNotADestinationIsRefused(): void
    {
        $this->authenticateClient($this->client, $this->createUser('egress-allow-admin@synaplan.internal', 'ADMIN'));

        $this->send('POST', ['rule' => 'not a host/at all']);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
        self::assertSame(['success' => false, 'error' => 'invalid_rule'], $this->json());

        $this->send('POST', []);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function send(string $method, array $body): void
    {
        $this->client->request($method, self::PATH, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body, \JSON_THROW_ON_ERROR));
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

    private function auditCount(string $action, User $actor): int
    {
        return $this->em->getRepository(AuditLogEntry::class)->count([
            'action' => $action,
            'resourceKind' => 'egress_rule',
            'resourceId' => self::RULE,
            'actorId' => $actor->getId(),
        ]);
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
            ->setProviderId('egress-allow-'.uniqid())
            ->setUserLevel($level);
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
