<?php

declare(strict_types=1);

namespace App\Tests\Controller\AI;

use App\AI\Catalog\ProviderCatalog;
use App\Tests\Trait\AuthenticatedTestTrait;
use App\Tests\Trait\CreatesTestEntities;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `GET /api/v1/ai/providers`: every signed-in user reads the public subset
 * of the catalog, nothing secret travels, and the exported spec names no
 * provider list anywhere a provider name is a path parameter.
 */
final class ProviderCatalogControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;
    use CreatesTestEntities;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
    }

    public function testASignedOutVisitorIsRefused(): void
    {
        $this->client->request('GET', '/api/v1/ai/providers');

        self::assertResponseStatusCodeSame(401);
    }

    public function testASignedInUserReadsTheCatalogInDisplayOrderWithoutSecrets(): void
    {
        $user = $this->createUser('provider-catalog')->setUserLevel('NEW');
        $this->em->flush();
        $this->authenticateClient($this->client, $user);

        $this->client->request('GET', '/api/v1/ai/providers');

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $catalog = static::getContainer()->get(ProviderCatalog::class);
        \assert($catalog instanceof ProviderCatalog);

        self::assertSame($catalog->names(), array_column($body['providers'], 'name'), 'keyed providers first, in Admin → AI keys order');
        $groq = $body['providers'][0];
        self::assertSame('groq', $groq['name']);
        self::assertSame('Groq', $groq['displayName']);
        self::assertSame(['chat', 'vision', 'speech_to_text'], $groq['capabilities']);
        self::assertSame('simple-icons:groq', $groq['icon']);
        self::assertSame('GroqIcon', $groq['iconComponent']);
        self::assertSame('circle-flags:us', $groq['flag']);
        self::assertFalse($groq['local']);
        self::assertSame('groq', $groq['helpKey']);
        self::assertSame('https://console.groq.com/keys', $groq['helpUrl']);
        self::assertSame(['GROQ_API_KEY'], $groq['helpFields']);
        self::assertSame(['store' => 'platform', 'envVar' => 'GROQ_API_KEY', 'secretEnvVar' => null, 'consoleUrl' => 'https://console.groq.com/keys', 'userCredential' => 'none', 'servesChat' => true], $groq['key']);

        $byName = array_column($body['providers'], null, 'name');
        self::assertNull($byName['ollama']['key']);
        self::assertTrue($byName['ollama']['local']);
        self::assertSame(['OLLAMA_BASE_URL'], $byName['ollama']['helpFields']);
        self::assertSame('gateway', $byName['anthropic']['key']['userCredential']);
        self::assertSame('personal', $byName['higgsfield']['key']['userCredential']);
        self::assertSame('HIGGSFIELD_API_SECRET', $byName['higgsfield']['key']['secretEnvVar']);
        self::assertSame('endpoint_registry', $byName['openaicompatible']['key']['store']);

        $raw = (string) $this->client->getResponse()->getContent();
        foreach (['validation', 'endpoints', 'Bearer', 'https://api.groq.com'] as $secretShape) {
            self::assertStringNotContainsString($secretShape, $raw, "the public subset carries no {$secretShape}");
        }
    }

    public function testTheExportedSpecNamesNoProviderList(): void
    {
        $user = $this->createUser('provider-catalog-spec')->setUserLevel('ADMIN');
        $this->em->flush();
        $this->authenticateClient($this->client, $user);

        $this->client->request('GET', '/api/doc.json');
        self::assertResponseIsSuccessful();
        $spec = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        foreach (['/api/v1/admin/provider-keys/{provider}', '/api/v1/messages-gateway/keys/{provider}'] as $path) {
            foreach ($spec['paths'][$path] ?? [] as $operation) {
                foreach ($operation['parameters'] ?? [] as $parameter) {
                    if ('provider' === ($parameter['name'] ?? null)) {
                        self::assertArrayNotHasKey('enum', $parameter['schema'] ?? [], "{$path} keeps no hand-kept provider list");
                    }
                }
            }
        }
    }
}
