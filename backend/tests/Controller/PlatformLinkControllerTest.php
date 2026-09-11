<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ApiKey;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Security\ApiKeyScope;
use App\Service\PlatformLink\PlatformLinksConfig;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Partner-instance handshake: flag gating (C1), register/approve, exchange
 * (C3/C5), and disconnect.
 */
final class PlatformLinkControllerTest extends WebTestCase
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

    private function enableFlag(): void
    {
        static::getContainer()->get(ConfigRepository::class)
            ->setValue(0, PlatformLinksConfig::CONFIG_GROUP, PlatformLinksConfig::KEY_ENABLED, '1');
        $this->em->flush();
    }

    private function disableFlag(): void
    {
        static::getContainer()->get(ConfigRepository::class)
            ->setValue(0, PlatformLinksConfig::CONFIG_GROUP, PlatformLinksConfig::KEY_ENABLED, '0');
        $this->em->flush();
    }

    public function testAllRoutes404WhenFlagOff(): void
    {
        $this->disableFlag();
        $user = $this->createUser('pl-flag-off@synaplan.internal');
        $this->authenticateClient($this->client, $user);

        $this->postJson('/api/v1/platform-links/instances', [
            'client' => 'nextcloud',
            'host' => 'https://example.com',
            'redirect_uris' => ['https://example.com/cb'],
        ]);
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/v1/platform-links/instances/self');
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/v1/platform-links/instances/pi_aabbccddeeff/public');
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());

        $this->postJson('/api/v1/platform-links/codes', [
            'instance_id' => 'pi_aabbccddeeff',
            'external_id' => 'jdoe',
            'redirect_uri' => 'https://example.com/cb',
            'state' => 's',
        ]);
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());

        $this->client->getCookieJar()->clear();
        $this->postJson('/api/v1/platform-links/exchange', [
            'instance_id' => 'pi_aabbccddeeff',
            'instance_secret' => 'x',
            'code' => str_repeat('ab', 16),
        ]);
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());

        $this->authenticateClient($this->client, $user);
        $this->client->request('GET', '/api/v1/me/platform-links');
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());

        $this->client->request('DELETE', '/api/v1/me/platform-links/1');
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/v1/admin/platform-links/instances');
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testAdminRegisterIsActiveAndAnonymousIsPending(): void
    {
        $this->enableFlag();
        $admin = $this->createUser('pl-admin-reg@synaplan.internal', 'ADMIN');
        $this->authenticateClient($this->client, $admin);

        $this->postJson('/api/v1/platform-links/instances', $this->registerBody('example.com'));
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());
        $adminBody = $this->json();
        self::assertSame('active', $adminBody['status']);
        self::assertStringStartsWith('pi_', $adminBody['instance_id']);

        $this->client->request('GET', '/api/v1/platform-links/instances/'.$adminBody['instance_id'].'/public');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame('example.com', $this->json()['host']);

        $this->client->getCookieJar()->clear();
        $this->postJson('/api/v1/platform-links/instances', $this->registerBody('example.org'));
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());
        $anon = $this->json();
        self::assertSame('pending', $anon['status']);

        $this->client->request('GET', '/api/v1/platform-links/instances/'.$anon['instance_id'].'/public');
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());

        $this->authenticateClient($this->client, $admin);
        $this->client->request('POST', '/api/v1/admin/platform-links/instances/'.$anon['instance_id'].'/approve');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/v1/platform-links/instances/'.$anon['instance_id'].'/public');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
    }

    public function testExchangeHappyPathReplayForeignPendingAndWrongSecret(): void
    {
        $this->enableFlag();
        $admin = $this->createUser('pl-ex-admin@synaplan.internal', 'ADMIN');
        $user = $this->createUser('pl-ex-user@synaplan.internal');

        $this->authenticateClient($this->client, $admin);
        $this->postJson('/api/v1/platform-links/instances', $this->registerBody('example.com'));
        $instanceA = $this->json();

        $this->postJson('/api/v1/platform-links/instances', $this->registerBody('example.org'));
        $instanceB = $this->json();

        $this->client->getCookieJar()->clear();
        $this->postJson('/api/v1/platform-links/instances', $this->registerBody('example.net'));
        $pending = $this->json();
        self::assertSame('pending', $pending['status']);

        $this->authenticateClient($this->client, $user);
        $countBefore = $this->em->getRepository(User::class)->count([]);
        $mailBefore = $user->getMail();
        $detailsBefore = $user->getUserDetails();

        $this->postJson('/api/v1/platform-links/codes', [
            'instance_id' => $instanceA['instance_id'],
            'external_id' => 'jdoe',
            'redirect_uri' => 'https://example.com/apps/synaplan_integration/link/callback',
            'state' => 'nonce-1',
        ]);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $redirect = $this->json()['redirect'];
        self::assertStringContainsString('code=', $redirect);
        self::assertStringContainsString('state=nonce-1', $redirect);
        $code = $this->codeFromRedirect($redirect);

        $this->client->getCookieJar()->clear();
        $this->postJson('/api/v1/platform-links/exchange', [
            'instance_id' => $instanceA['instance_id'],
            'instance_secret' => $instanceA['instance_secret'],
            'code' => $code,
        ]);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $exchanged = $this->json();
        self::assertStringStartsWith('sk_', $exchanged['api_key']['key']);
        self::assertSame(ApiKeyScope::platformLinkScopes(), $exchanged['api_key']['scopes']);
        self::assertSame((int) $user->getId(), $exchanged['user']['id']);

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($user->getId());
        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame($countBefore, $this->em->getRepository(User::class)->count([]));
        self::assertSame($mailBefore, $reloaded->getMail());
        self::assertSame($detailsBefore, $reloaded->getUserDetails());

        $this->postJson('/api/v1/platform-links/exchange', [
            'instance_id' => $instanceA['instance_id'],
            'instance_secret' => $instanceA['instance_secret'],
            'code' => $code,
        ]);
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        $replayMessage = $this->json()['error'];

        $this->authenticateClient($this->client, $user);
        $this->postJson('/api/v1/platform-links/codes', [
            'instance_id' => $instanceA['instance_id'],
            'external_id' => 'jdoe',
            'redirect_uri' => 'https://example.com/apps/synaplan_integration/link/callback',
            'state' => 'nonce-2',
        ]);
        $foreignCode = $this->codeFromRedirect($this->json()['redirect']);
        $this->client->getCookieJar()->clear();
        $this->postJson('/api/v1/platform-links/exchange', [
            'instance_id' => $instanceB['instance_id'],
            'instance_secret' => $instanceB['instance_secret'],
            'code' => $foreignCode,
        ]);
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        self::assertSame($replayMessage, $this->json()['error']);

        $this->authenticateClient($this->client, $user);
        $this->postJson('/api/v1/platform-links/codes', [
            'instance_id' => $pending['instance_id'],
            'external_id' => 'jdoe',
            'redirect_uri' => 'https://example.net/apps/synaplan_integration/link/callback',
            'state' => 'nonce-3',
        ]);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());

        $this->client->getCookieJar()->clear();
        $this->postJson('/api/v1/platform-links/exchange', [
            'instance_id' => $pending['instance_id'],
            'instance_secret' => $pending['instance_secret'],
            'code' => str_repeat('cd', 16),
        ]);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());

        $this->postJson('/api/v1/platform-links/exchange', [
            'instance_id' => $instanceA['instance_id'],
            'instance_secret' => 'wrong-secret',
            'code' => str_repeat('ef', 16),
        ]);
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());

        $key = $exchanged['api_key']['key'];
        $this->client->request('GET', '/api/v1/auth/me', server: ['HTTP_X_API_KEY' => $key]);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/api/v1/admin/users', server: ['HTTP_X_API_KEY' => $key]);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());

        $this->authenticateClient($this->client, $user);
        $this->client->request('GET', '/api/v1/me/platform-links');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $links = $this->json()['links'];
        self::assertCount(1, $links);

        $this->client->request('GET', '/api/v1/apikeys');
        $listed = $this->json()['api_keys'];
        $linked = null;
        foreach ($listed as $row) {
            if (($row['id'] ?? null) === $exchanged['api_key']['id']) {
                $linked = $row;
            }
        }
        self::assertIsArray($linked);
        self::assertSame('nextcloud', $linked['linked_platform']['client']);

        $this->client->request('DELETE', '/api/v1/me/platform-links/'.$links[0]['id']);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/v1/auth/me', server: ['HTTP_X_API_KEY' => $key]);
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->em->getRepository(ApiKey::class)->find($exchanged['api_key']['id']));
    }

    /**
     * The same external id linked again by a different Synaplan account moves
     * the link: the row is re-owned, so the previous owner can no longer see
     * or disconnect it (which would revoke the new owner's key).
     */
    public function testRelinkingAnExternalIdMovesTheLinkToTheNewOwner(): void
    {
        $this->enableFlag();
        $admin = $this->createUser('pl-move-admin@synaplan.internal', 'ADMIN');
        $first = $this->createUser('pl-move-first@synaplan.internal');
        $second = $this->createUser('pl-move-second@synaplan.internal');

        $this->authenticateClient($this->client, $admin);
        $this->postJson('/api/v1/platform-links/instances', $this->registerBody('move.example'));
        $instance = $this->json();

        $firstLink = $this->link($instance, $first, 'shared-uid', 'nonce-move-1');
        $secondLink = $this->link($instance, $second, 'shared-uid', 'nonce-move-2');

        // The first owner's key is gone and the link is no longer listed for them.
        self::assertNull($this->em->getRepository(ApiKey::class)->find($firstLink['api_key']['id']));
        $this->authenticateClient($this->client, $first);
        $this->client->request('GET', '/api/v1/me/platform-links');
        $lostLinks = $this->json()['links'];
        self::assertSame([], $lostLinks);

        // The new owner sees exactly one link, with the still-valid key.
        $this->authenticateClient($this->client, $second);
        $this->client->request('GET', '/api/v1/me/platform-links');
        $links = $this->json()['links'];
        self::assertIsArray($links);
        self::assertCount(1, $links);
        self::assertSame((int) $second->getId(), $secondLink['user']['id']);

        // The previous owner cannot disconnect the link they lost.
        $this->authenticateClient($this->client, $first);
        $this->client->request('DELETE', '/api/v1/me/platform-links/'.$links[0]['id']);
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/v1/auth/me', server: ['HTTP_X_API_KEY' => $secondLink['api_key']['key']]);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Issues a link code as $user and exchanges it as the instance.
     *
     * @param array{instance_id: string, instance_secret: string} $instance
     *
     * @return array<string, mixed> the exchange response
     */
    private function link(array $instance, User $user, string $externalId, string $state): array
    {
        $this->authenticateClient($this->client, $user);
        $this->postJson('/api/v1/platform-links/codes', [
            'instance_id' => $instance['instance_id'],
            'external_id' => $externalId,
            'redirect_uri' => 'https://move.example/apps/synaplan_integration/link/callback',
            'state' => $state,
        ]);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $code = $this->codeFromRedirect($this->json()['redirect']);

        $this->client->getCookieJar()->clear();
        $this->postJson('/api/v1/platform-links/exchange', [
            'instance_id' => $instance['instance_id'],
            'instance_secret' => $instance['instance_secret'],
            'code' => $code,
        ]);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return $this->json();
    }

    /**
     * @return array{client: string, host: string, redirect_uris: list<string>}
     */
    private function registerBody(string $host): array
    {
        return [
            'client' => 'nextcloud',
            'host' => 'https://'.$host,
            'redirect_uris' => ['https://'.$host.'/apps/synaplan_integration/link/callback'],
        ];
    }

    private function codeFromRedirect(string $redirect): string
    {
        $query = parse_url($redirect, \PHP_URL_QUERY);
        self::assertIsString($query);
        parse_str($query, $params);
        self::assertIsString($params['code'] ?? null);

        return (string) $params['code'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postJson(string $uri, array $payload): void
    {
        $this->client->request('POST', $uri, server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode($payload));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded, 'Response was not JSON: '.$this->client->getResponse()->getContent());

        return $decoded;
    }

    private function createUser(string $email, string $level = 'NEW'): User
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
            ->setProviderId('platform-link-'.uniqid())
            ->setUserLevel($level);
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
