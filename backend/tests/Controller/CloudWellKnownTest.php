<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\ConfigRepository;
use App\Service\PlatformLink\PlatformLinksConfig;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The two open documents a cloud trusts Synaplan's notifications with: the
 * configuration naming the issuer, and the JWK Set.
 */
final class CloudWellKnownTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->setFlag(true);
    }

    public function testTheSigningKeysAreOpenAsAJwkSet(): void
    {
        $this->client->request('GET', '/.well-known/jwks.json');
        self::assertResponseIsSuccessful();
        $body = $this->json();
        self::assertNotEmpty($body['keys']);
        $key = $body['keys'][0];
        self::assertSame(['OKP', 'Ed25519', 'sig', 'EdDSA'], [$key['kty'], $key['crv'], $key['use'], $key['alg']]);
        $rsa = array_values(array_filter($body['keys'], static fn (array $k): bool => 'RSA' === $k['kty']));
        self::assertCount(1, $rsa, 'the access token key');
        self::assertSame(['sig', 'RS256'], [$rsa[0]['use'], $rsa[0]['alg']]);
        self::assertSame(32, strlen((string) base64_decode(strtr($key['x'], '-_', '+/'), true)));
        self::assertNotSame('', $key['kid']);
    }

    public function testTheConfigurationNamesThePublicIssuerAndTheKeys(): void
    {
        $this->client->request('GET', '/.well-known/synaplan-configuration');
        self::assertResponseIsSuccessful();
        $issuer = rtrim((string) $_SERVER['APP_URL'], '/');
        $body = $this->json();
        self::assertSame([$issuer, $issuer.'/.well-known/jwks.json'], [$body['issuer'], $body['jwks_uri']]);
        self::assertContains('deliveries', $body['capabilities']);
    }

    public function testNothingIsPublishedWhilePlatformLinksAreOff(): void
    {
        $this->setFlag(false);

        foreach (['/.well-known/jwks.json', '/.well-known/synaplan-configuration'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, $path);
        }
    }

    private function setFlag(bool $enabled): void
    {
        static::getContainer()->get(ConfigRepository::class)
            ->setValue(0, PlatformLinksConfig::CONFIG_GROUP, PlatformLinksConfig::KEY_ENABLED, $enabled ? '1' : '0');
        static::getContainer()->get('doctrine')->getManager()->flush();
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($body);

        return $body;
    }
}
