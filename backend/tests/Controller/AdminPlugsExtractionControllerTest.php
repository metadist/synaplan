<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

final class AdminPlugsExtractionControllerTest extends WebTestCase
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

    public function testNonAdminIsForbidden(): void
    {
        $user = $this->createUser('plugs-extraction-user@synaplan.internal', 'NEW');
        $this->authenticateClient($this->client, $user);
        $this->client->request('GET', '/api/v1/admin/plugs/extraction');

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    public function testAdminGetReturnsAdaptersAndDefaultChains(): void
    {
        $this->loginAdmin();
        $this->client->request('GET', '/api/v1/admin/plugs/extraction');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $body = $this->json();
        self::assertArrayHasKey('adapters', $body);
        self::assertArrayHasKey('chains', $body);
        self::assertSame(['pdf'], $body['quality']['applyTo'] ?? null);
        self::assertSame(
            ['structured_office', 'office_convert', 'tika', 'pdf_vision'],
            $body['chains']['document'] ?? null,
        );
        $keys = array_column($body['adapters'], 'key');
        self::assertContains('docling', $keys);
        self::assertContains('tika', $keys);
    }

    public function testPutEmptyChainReturns422(): void
    {
        $this->loginAdmin();
        $this->client->request(
            'PUT',
            '/api/v1/admin/plugs/extraction/chains',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['chains' => ['document' => []]], JSON_THROW_ON_ERROR),
        );

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
        $body = $this->json();
        self::assertStringContainsString('must not be empty', (string) ($body['error'] ?? ''));
    }

    public function testPutUnknownAdapterReturns422(): void
    {
        $this->loginAdmin();
        $this->client->request(
            'PUT',
            '/api/v1/admin/plugs/extraction/chains',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['chains' => ['document' => ['not-a-real-adapter']]], JSON_THROW_ON_ERROR),
        );

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
        $body = $this->json();
        self::assertStringContainsString('Unknown extractor key', (string) ($body['error'] ?? ''));
    }

    public function testPutKnownChainAndRestoreDefault(): void
    {
        $this->loginAdmin();
        $this->client->request(
            'PUT',
            '/api/v1/admin/plugs/extraction/chains',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['chains' => ['document' => ['docling', 'tika', 'pdf_vision']]], JSON_THROW_ON_ERROR),
        );

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $body = $this->json();
        self::assertSame(['docling', 'tika', 'pdf_vision'], $body['chains']['document'] ?? null);

        $this->client->request(
            'PUT',
            '/api/v1/admin/plugs/extraction/chains',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'chains' => ['document' => ['structured_office', 'office_convert', 'tika', 'pdf_vision']],
            ], JSON_THROW_ON_ERROR),
        );
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
    }

    public function testPostTestReturnsAttempts(): void
    {
        $this->loginAdmin();
        $tmp = tempnam(sys_get_temp_dir(), 'plugs-test-');
        self::assertNotFalse($tmp);
        file_put_contents($tmp, "Hello extraction probe\n");
        $upload = new UploadedFile($tmp, 'sample.md', 'text/markdown', null, true);

        $this->client->request('POST', '/api/v1/admin/plugs/extraction/test', files: ['file' => $upload]);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $body = $this->json();
        self::assertArrayHasKey('attempts', $body);
        self::assertArrayHasKey('preview', $body);
        self::assertArrayHasKey('strategy', $body);
        @unlink($tmp);
    }

    private function loginAdmin(): void
    {
        $admin = $this->createUser('plugs-extraction-admin@synaplan.internal', 'ADMIN');
        $this->authenticateClient($this->client, $admin);
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
            ->setProviderId('plugs-extraction-'.uniqid())
            ->setUserLevel($level);
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }
}
