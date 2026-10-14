<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Entity\User;
use App\Service\Auth\TokenService;
use App\Tests\Characterization\Support\CleanupSnapshot;
use App\Tests\Characterization\Support\PayloadShape;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Key-and-type contract of GET /api/v1/config/runtime.
 *
 * Three profiles, matching the cleanup V2 plan:
 *   - anonymous: the public payload the test kernel (CI's env) actually boots
 *   - authenticated: the same install with the fixture user signed in
 *   - oidc: REGISTRATION_ENABLED and GUEST_CHAT_ENABLED pinned off, which is
 *     how an SSO-only install hides local sign-up and the guest trial
 *
 * A second kernel for `.env.minimal` is not booted here. Sidecar and billing
 * switches are container bindings, so they cannot be flipped inside this
 * process; the anonymous profile is the suite's env, and the OIDC profile is
 * the operator pin the plan calls out. Values that move with the database
 * (provider health, plugin list) are not part of the shape.
 *
 * Record with UPDATE_CLEANUP_V2_SNAPSHOTS=1.
 */
final class RuntimeConfigCharacterizationTest extends WebTestCase
{
    private const SNAPSHOT = __DIR__.'/__snapshots__/runtime_config.json';

    public function testRuntimePayloadShapeMatchesTheThreeProfiles(): void
    {
        $client = static::createClient();

        $actual = [
            'anonymous' => $this->profile($client, null),
            'authenticated' => $this->profile($client, $this->fixtureUser($client)),
            'oidc' => $this->withEnv([
                'REGISTRATION_ENABLED' => 'false',
                'GUEST_CHAT_ENABLED' => 'false',
            ], fn (): array => $this->profile($client, null)),
        ];

        self::assertFalse($actual['oidc']['signals']['auth.registrationEnabled']);
        self::assertFalse($actual['oidc']['signals']['auth.guestChatEnabled']);
        self::assertTrue($actual['anonymous']['signals']['auth.registrationEnabled']);
        self::assertTrue($actual['anonymous']['signals']['auth.guestChatEnabled']);
        self::assertContains('chatReady', $actual['authenticated']['setupKeys']);
        self::assertNotContains('chatReady', $actual['anonymous']['setupKeys']);

        CleanupSnapshot::assertSame(self::SNAPSHOT, $actual, 'runtime config');
    }

    /**
     * @return array{shape: array<string, mixed>|string, signals: array<string, bool>, setupKeys: list<string>}
     */
    private function profile(KernelBrowser $client, ?User $user): array
    {
        $server = [];
        if (null !== $user) {
            $tokens = $client->getContainer()->get(TokenService::class);
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$tokens->generateAccessToken($user);
        }
        $client->getCookieJar()->clear();
        $client->request('GET', '/api/v1/config/runtime', server: $server);
        self::assertResponseIsSuccessful();

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        unset($payload['unavailableProviders']);

        /** @var array<string, mixed> $auth */
        $auth = $payload['auth'];
        /** @var array<string, mixed> $billing */
        $billing = $payload['billing'];
        /** @var array<string, mixed> $setup */
        $setup = $payload['setup'];

        return [
            'shape' => PayloadShape::of($payload),
            'signals' => [
                'auth.registrationEnabled' => (bool) $auth['registrationEnabled'],
                'auth.guestChatEnabled' => (bool) $auth['guestChatEnabled'],
                'billing.enabled' => (bool) $billing['enabled'],
            ],
            'setupKeys' => array_keys($setup),
        ];
    }

    private function fixtureUser(KernelBrowser $client): User
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['mail' => 'demo@synaplan.com']);
        if (!$user instanceof User) {
            self::markTestSkipped('Fixture user demo@synaplan.com not found. Run fixtures first.');
        }

        return $user;
    }

    /**
     * @param array<string, string> $vars
     *
     * @return array{shape: array<string, mixed>|string, signals: array<string, bool>, setupKeys: list<string>}
     */
    private function withEnv(array $vars, callable $fn): array
    {
        $backup = [];
        foreach ($vars as $key => $value) {
            $backup[$key] = $_ENV[$key] ?? false;
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv($key.'='.$value);
        }

        try {
            /** @var array{shape: array<string, mixed>|string, signals: array<string, bool>, setupKeys: list<string>} $result */
            $result = $fn();

            return $result;
        } finally {
            foreach ($backup as $key => $previous) {
                if (false === $previous) {
                    unset($_ENV[$key], $_SERVER[$key]);
                    putenv($key);
                } else {
                    $_ENV[$key] = $previous;
                    $_SERVER[$key] = $previous;
                    putenv($key.'='.$previous);
                }
            }
        }
    }
}
