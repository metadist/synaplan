<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditLogEntry;
use App\Entity\SignupCode;
use App\Entity\User;
use App\Repository\SignupCodeRepository;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin › People › Sign-up codes (admission sign-up, AS3): only an
 * administrator issues codes, the plain code leaves the server once, a patch
 * changes only what it sends, and revoking keeps the code and its uses.
 */
final class AdminSignupCodeControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private const PATH = '/api/v1/admin/signup-codes';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    /** @var list<int> */
    private array $codeIds = [];

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        foreach ($this->codeIds as $id) {
            $conn->executeStatement('DELETE FROM BSIGNUP_CODE_USES WHERE BCODEID = ?', [$id]);
            $conn->executeStatement('DELETE FROM BSIGNUP_CODES WHERE BID = ?', [$id]);
        }
        parent::tearDown();
    }

    public function testARegularUserIsRefused(): void
    {
        $user = $this->createUser('signup-codes-member@synaplan.internal', 'NEW');
        $this->authenticateClient($this->client, $user);

        $this->client->request('GET', self::PATH);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());

        $this->send('POST', self::PATH, ['label' => 'x', 'level' => 'NEW', 'validity' => 'forever']);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    public function testCreateReturnsTheCodeOnceAndTheListShowsOnlyItsPrefix(): void
    {
        $admin = $this->loginAdmin();

        $created = $this->create(['label' => 'Pilot customers', 'level' => 'TEAM', 'validity' => 'forever', 'maxUses' => 5]);

        self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $created['code']);
        $plain = str_replace('-', '', $created['code']);
        $item = $created['signupCode'];
        self::assertSame(substr($plain, 0, 4), $item['prefix']);
        self::assertSame('Pilot customers', $item['label']);
        self::assertSame('TEAM', $item['level']);
        self::assertSame(5, $item['maxUses']);
        self::assertSame(0, $item['uses']);
        self::assertNull($item['expiresAt']);
        self::assertSame('active', $item['state']);
        self::assertSame($admin->getId(), $item['createdById']);
        self::assertSame($admin->getMail(), $item['createdByEmail']);

        $stored = $this->em->getRepository(SignupCode::class)->find($item['id']);
        self::assertInstanceOf(SignupCode::class, $stored);
        self::assertSame(hash('sha256', $plain), $stored->getCodeHash(), 'only the hash is stored');
        self::assertTrue($this->redeemable($created['code']), 'the shown form redeems');

        $this->client->request('GET', self::PATH);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $raw = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString($plain, $raw);
        self::assertStringNotContainsString($created['code'], $raw);
        $listed = array_values(array_filter($this->json()['codes'], static fn (array $c): bool => $c['id'] === $item['id']));
        self::assertCount(1, $listed);
        self::assertArrayNotHasKey('code', $listed[0]);
        self::assertSame(1, $this->auditCount('signup_code.created', $item['id']));
    }

    public function testValidityPresetsSetTheExpiry(): void
    {
        $this->loginAdmin();
        $before = time();

        $week = $this->create(['label' => 'Week', 'level' => 'NEW', 'validity' => 'week'])['signupCode'];
        $months = $this->create(['label' => 'Months', 'level' => 'NEW', 'validity' => 'fiveMonths'])['signupCode'];

        self::assertGreaterThanOrEqual($before + 7 * 86400, $week['expiresAt']);
        self::assertLessThanOrEqual(time() + 7 * 86400, $week['expiresAt']);
        $fiveMonths = (new \DateTimeImmutable('@'.$before))->modify('+5 months')->getTimestamp();
        self::assertGreaterThanOrEqual($fiveMonths, $months['expiresAt']);
        self::assertLessThanOrEqual($fiveMonths + (time() - $before), $months['expiresAt']);
        self::assertSame(1, $week['maxUses'], 'one use unless the administrator says otherwise');
    }

    public function testCreateRefusesAnInvalidRequest(): void
    {
        $this->loginAdmin();

        foreach ([
            ['label' => '', 'level' => 'NEW', 'validity' => 'forever'],
            ['label' => 'x', 'level' => 'ADMIN', 'validity' => 'forever'],
            ['label' => 'x', 'level' => 'NEW', 'validity' => 'tomorrow'],
            ['label' => 'x', 'level' => 'NEW', 'validity' => 'forever', 'maxUses' => 0],
            ['label' => str_repeat('x', 121), 'level' => 'NEW', 'validity' => 'forever'],
        ] as $body) {
            $this->send('POST', self::PATH, $body);
            self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode(), json_encode($body) ?: '');
            self::assertSame('SIGNUP_CODE_REQUEST_INVALID', $this->json()['code']);
        }
    }

    public function testPatchChangesOnlyTheKeysItSends(): void
    {
        $this->loginAdmin();
        $item = $this->create(['label' => 'Before', 'level' => 'PRO', 'validity' => 'week', 'maxUses' => 3])['signupCode'];

        $this->send('PATCH', self::PATH.'/'.$item['id'], ['label' => 'After', 'maxUses' => null]);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $patched = $this->json()['signupCode'];
        self::assertSame('After', $patched['label']);
        self::assertNull($patched['maxUses'], 'null means no limit');
        self::assertSame($item['expiresAt'], $patched['expiresAt'], 'expiry untouched');
        self::assertSame('PRO', $patched['level']);

        $this->send('PATCH', self::PATH.'/'.$item['id'], ['expiresAt' => null]);
        self::assertNull($this->json()['signupCode']['expiresAt']);

        $this->send('PATCH', self::PATH.'/'.$item['id'], ['expiresAt' => time() - 60]);
        self::assertSame('expired', $this->json()['signupCode']['state']);

        $this->send('PATCH', self::PATH.'/'.$item['id'], ['maxUses' => 0]);
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        $this->send('PATCH', self::PATH.'/'.$item['id'], ['active' => 'yes']);
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
    }

    public function testRevokeKeepsTheCodeAndItsUsesAndCanBeUndone(): void
    {
        $this->loginAdmin();
        $created = $this->create(['label' => 'Revoke me', 'level' => 'TEAM', 'validity' => 'forever', 'maxUses' => 5]);
        $item = $created['signupCode'];
        $member = $this->createUser('signup-codes-used@synaplan.internal', 'TEAM');
        $codes = static::getContainer()->get(SignupCodeRepository::class);
        $code = $codes->find($item['id']);
        self::assertInstanceOf(SignupCode::class, $code);
        self::assertNotNull($codes->redeem($code, (int) $member->getId()));
        $this->em->flush();

        $this->client->request('DELETE', self::PATH.'/'.$item['id']);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $revoked = $this->json()['signupCode'];
        self::assertSame('revoked', $revoked['state']);
        self::assertFalse($revoked['active']);
        self::assertSame(1, $revoked['uses']);
        self::assertFalse($this->redeemable($created['code']), 'nobody can use it any more');
        self::assertSame(1, $this->auditCount('signup_code.revoked', $item['id']));

        $this->client->request('GET', self::PATH.'/'.$item['id'].'/uses');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $uses = $this->json()['uses'];
        self::assertCount(1, $uses);
        self::assertSame($member->getId(), $uses[0]['userId']);
        self::assertSame('signup-codes-used@synaplan.internal', $uses[0]['email']);
        self::assertSame('TEAM', $uses[0]['level']);

        $this->send('PATCH', self::PATH.'/'.$item['id'], ['active' => true]);
        self::assertSame('active', $this->json()['signupCode']['state']);
        self::assertTrue($this->redeemable($created['code']), 'restored');
    }

    public function testAMalformedBodyIsRefusedAndChangesNothing(): void
    {
        $this->loginAdmin();
        $item = $this->create(['label' => 'Body', 'level' => 'NEW', 'validity' => 'forever'])['signupCode'];

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->sendRaw('PATCH', self::PATH.'/'.$item['id'], '{"active":false'));
        self::assertSame('SIGNUP_CODE_REQUEST_INVALID', $this->json()['code']);
        self::assertSame(0, $this->auditCount('signup_code.updated', $item['id']));
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->sendRaw('PATCH', self::PATH.'/'.$item['id'], '["active"]'));
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->sendRaw('POST', self::PATH, 'not json'));

        $this->client->request('GET', self::PATH);
        $listed = array_values(array_filter($this->json()['codes'], static fn (array $c): bool => $c['id'] === $item['id']));
        self::assertSame('active', $listed[0]['state'], 'nothing was applied');
    }

    public function testAnUnknownCodeIsNotFound(): void
    {
        $this->loginAdmin();

        $this->client->request('DELETE', self::PATH.'/999999999');
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
        self::assertSame('SIGNUP_CODE_NOT_FOUND', $this->json()['code']);

        $this->client->request('GET', self::PATH.'/999999999/uses');
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    private function redeemable(string $code): bool
    {
        $doctrine = static::getContainer()->get('doctrine');
        $doctrine->getManager()->clear();

        return null !== static::getContainer()->get(SignupCodeRepository::class)->findRedeemable($code);
    }

    private function loginAdmin(): User
    {
        $admin = $this->createUser('signup-codes-admin@synaplan.internal', 'ADMIN');
        $this->authenticateClient($this->client, $admin);

        return $admin;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{code: string, signupCode: array<string, mixed>}
     */
    private function create(array $body): array
    {
        $this->send('POST', self::PATH, $body);
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $payload = $this->json();
        $this->codeIds[] = (int) $payload['signupCode']['id'];

        return $payload;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function send(string $method, string $path, array $body): void
    {
        $this->client->request($method, $path, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function sendRaw(string $method, string $path, string $body): int
    {
        $this->client->request($method, $path, [], [], ['CONTENT_TYPE' => 'application/json'], $body);

        return $this->client->getResponse()->getStatusCode();
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

    private function auditCount(string $action, int $codeId): int
    {
        return $this->em->getRepository(AuditLogEntry::class)->count([
            'action' => $action,
            'resourceKind' => 'signup_code',
            'resourceId' => (string) $codeId,
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
            ->setProviderId('signup-codes-'.uniqid())
            ->setUserLevel($level);
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
