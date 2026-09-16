<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class AdminModelsImportEndpointControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private const PROVIDER_ID = 'itest-ctrl/import-model';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->deleteTestRows();
    }

    protected function tearDown(): void
    {
        $this->deleteTestRows();
        parent::tearDown();
    }

    public function testPreviewRequiresAdmin(): void
    {
        $user = $this->createUser('models-import-user@synaplan.internal', 'NEW');
        $this->authenticateClient($this->client, $user);
        $this->postJson('/api/v1/admin/models/import/endpoint/preview', ['source' => 'ollama']);

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    public function testPreviewMissingSourceIs400(): void
    {
        $this->loginAdmin();
        $this->postJson('/api/v1/admin/models/import/endpoint/preview', ['probe' => false]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
    }

    public function testPreviewUnknownEndpointIs404(): void
    {
        $this->loginAdmin();
        $this->postJson('/api/v1/admin/models/import/endpoint/preview', ['source' => 'openai_compatible:ghost']);

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testPreviewUnknownSourcePrefixIs404(): void
    {
        $this->loginAdmin();
        $this->postJson('/api/v1/admin/models/import/endpoint/preview', ['source' => 'huggingface:foo']);

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testOllamaPreviewReturnsContract(): void
    {
        $this->loginAdmin();
        $this->postJson('/api/v1/admin/models/import/endpoint/preview', ['source' => 'ollama']);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $body = $this->json();
        self::assertSame('ollama', $body['source']);
        self::assertArrayHasKey('rows', $body);
        self::assertArrayHasKey('endpointOk', $body);
        self::assertIsBool($body['endpointOk']);
        self::assertArrayHasKey('error', $body);
    }

    public function testApplyUnknownEndpointIs404(): void
    {
        $this->loginAdmin();
        $this->postJson('/api/v1/admin/models/import/endpoint/apply', [
            'source' => 'openai_compatible:ghost',
            'rows' => [['providerId' => self::PROVIDER_ID, 'tags' => ['chat']]],
        ]);

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testApplyCreatesThenIdempotent(): void
    {
        $this->loginAdmin();
        $payload = [
            'source' => 'ollama',
            'rows' => [['providerId' => self::PROVIDER_ID, 'name' => 'Import Test', 'tags' => ['chat']]],
        ];

        $this->postJson('/api/v1/admin/models/import/endpoint/apply', $payload);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $first = $this->json();
        self::assertSame(1, $first['created']);
        self::assertSame(0, $first['skipped']);

        $this->postJson('/api/v1/admin/models/import/endpoint/apply', $payload);
        $second = $this->json();
        self::assertSame(0, $second['created']);
        self::assertSame(1, $second['skipped']);
        self::assertSame('exists', $second['rows'][0]['status']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postJson(string $uri, array $payload): void
    {
        $this->client->request('POST', $uri, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function loginAdmin(): void
    {
        $this->authenticateClient($this->client, $this->createUser('models-import-admin@synaplan.internal', 'ADMIN'));
    }

    private function createUser(string $email, string $level): \App\Entity\User
    {
        $existing = $this->em->getRepository(\App\Entity\User::class)->findOneBy(['mail' => $email]);
        if ($existing instanceof \App\Entity\User) {
            $existing->setUserLevel($level);
            $this->em->flush();

            return $existing;
        }

        $user = (new \App\Entity\User())
            ->setMail($email)
            ->setType('WEB')
            ->setProviderId('models-import-'.uniqid())
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

    private function deleteTestRows(): void
    {
        $this->em->createQuery('DELETE FROM App\Entity\Model m WHERE m.providerId = :pid')
            ->setParameter('pid', self::PROVIDER_ID)
            ->execute();
        $this->em->clear();
    }
}
