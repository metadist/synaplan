<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\AI\Credential\HiggsfieldCredentialResolver;
use App\Entity\User;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration tests for HiggsfieldCredentialController.
 *
 * The encryption/storage details are unit-tested in
 * HiggsfieldCredentialResolverTest; here we pin the HTTP contract the frontend
 * Zod schemas validate:
 *   - auth gate (401 without a session)
 *   - GET shape (masked, never leaks the secret)
 *   - PUT validation (both fields required, mask placeholders rejected)
 *   - PUT → GET → DELETE round-trip toggles has_user_credentials
 */
class HiggsfieldCredentialControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private const BASE = '/api/v1/ai-providers/higgsfield/credentials';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private string $accessToken;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get('doctrine')->getManager();

        $this->user = new User();
        $this->user->setMail('higgsfield-cred-'.bin2hex(random_bytes(6)).'@test.synaplan.com');
        $this->user->setPw(password_hash('Test1234!', PASSWORD_BCRYPT));
        $this->user->setUserLevel('NEW');
        $this->user->setProviderId('local');
        $this->user->setCreated(date('YmdHis'));

        $this->em->persist($this->user);
        $this->em->flush();

        $this->accessToken = $this->authenticateClient($this->client, $this->user);
    }

    protected function tearDown(): void
    {
        // Clear any per-user credentials this test wrote to BCONFIG so the row
        // does not bleed into other tests / re-runs.
        if (isset($this->user) && $this->em->isOpen()) {
            try {
                /** @var HiggsfieldCredentialResolver $resolver */
                $resolver = $this->client->getContainer()->get(HiggsfieldCredentialResolver::class);
                $resolver->clearUserCredentials((int) $this->user->getId());
            } catch (\Throwable) {
                // best-effort cleanup
            }

            $managed = $this->em->find(User::class, $this->user->getId());
            if ($managed) {
                $this->em->remove($managed);
                $this->em->flush();
            }
        }

        self::ensureKernelShutdown();
        parent::tearDown();
    }

    public function testGetReturns401WhenUnauthenticated(): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', self::BASE);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testGetReturnsDocumentedShapeForUserWithoutCredentials(): void
    {
        $this->client->request(
            'GET',
            self::BASE,
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$this->accessToken],
        );

        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        foreach (['has_platform_credentials', 'has_user_credentials', 'user_api_key_masked', 'effective_source'] as $key) {
            $this->assertArrayHasKey($key, $body);
        }
        $this->assertIsBool($body['has_user_credentials']);
        $this->assertFalse($body['has_user_credentials'], 'A fresh user has no personal key');
        $this->assertContains($body['effective_source'], ['user', 'platform', 'none']);
        // The secret must never be returned, and with no user key the mask is empty.
        $this->assertSame('', $body['user_api_key_masked']);
    }

    public function testPutRejectsMissingApiSecret(): void
    {
        $this->client->request(
            'PUT',
            self::BASE,
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->accessToken,
            ],
            json_encode(['api_key' => 'hf_pub_only'], JSON_THROW_ON_ERROR),
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('api_secret is required', $body['error']);
    }

    public function testPutRejectsMaskPlaceholderValue(): void
    {
        $this->client->request(
            'PUT',
            self::BASE,
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->accessToken,
            ],
            json_encode(['api_key' => 'hf_p****', 'api_secret' => 'hf_s****'], JSON_THROW_ON_ERROR),
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('api_key is required', $body['error']);
    }

    public function testPutThenGetThenDeleteRoundTrip(): void
    {
        // PUT: store a personal pair.
        $this->client->request(
            'PUT',
            self::BASE,
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->accessToken,
            ],
            json_encode([
                'api_key' => 'hf_pub_integrationtestkey',
                'api_secret' => 'hf_sec_integrationtestsecret',
            ], JSON_THROW_ON_ERROR),
        );
        $this->assertResponseIsSuccessful();
        $put = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertTrue($put['success']);
        $this->assertTrue($put['has_user_credentials']);
        $this->assertNotSame('', $put['user_api_key_masked']);
        // The raw secret must never round-trip back to the client.
        $this->assertStringNotContainsString('integrationtestsecret', json_encode($put, JSON_THROW_ON_ERROR));

        // GET: now reports a personal key as the effective source.
        $this->client->request(
            'GET',
            self::BASE,
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$this->accessToken],
        );
        $this->assertResponseIsSuccessful();
        $get = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertTrue($get['has_user_credentials']);
        $this->assertSame('user', $get['effective_source']);
        $this->assertStringContainsString('*', $get['user_api_key_masked']);

        // DELETE: drops the personal key.
        $this->client->request(
            'DELETE',
            self::BASE,
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$this->accessToken],
        );
        $this->assertResponseIsSuccessful();
        $delete = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertTrue($delete['success']);
        $this->assertFalse($delete['has_user_credentials']);
    }
}
