<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Config;
use App\Module\Gate\ModuleGateConfig;
use App\Repository\ConfigRepository;
use App\Service\Agent\AgentConfig;
use App\Service\Document\DocumentToolsConfig;
use App\Service\PlatformLink\PlatformLinksConfig;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests für ConfigController (Memory Service Check Endpoint).
 */
final class ConfigControllerTest extends WebTestCase
{
    public function testMemoryServiceCheckEndpointIsPublic(): void
    {
        $client = static::createClient();

        // Should be accessible without authentication
        $client->request('GET', '/api/v1/config/memory-service/check');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testMemoryServiceCheckReturnsCorrectStructure(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/config/memory-service/check');

        $this->assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('available', $data);
        $this->assertArrayHasKey('configured', $data);
        $this->assertIsBool($data['available']);
        $this->assertIsBool($data['configured']);
    }

    public function testRuntimeConfigIncludesMemoryServiceFeature(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/config/runtime');

        $this->assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('features', $data);
        $this->assertArrayHasKey('memoryService', $data['features']);
        $this->assertIsBool($data['features']['memoryService']);
        $this->assertArrayHasKey('officeConvertEnabled', $data['features']);
        $this->assertFalse($data['features']['officeConvertEnabled']);
        foreach (['documentToolsEnabled', 'platformLinksEnabled', 'agentsEnabled'] as $feature) {
            $this->assertArrayHasKey($feature, $data['features']);
            $this->assertIsBool($data['features'][$feature]);
        }
    }

    /**
     * The wave feature flags ship ON, so the runtime payload has to follow the
     * stored global row in both directions rather than a code default.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideWaveFeatureFlags(): iterable
    {
        yield 'document tools' => ['documentToolsEnabled', DocumentToolsConfig::CONFIG_GROUP, DocumentToolsConfig::KEY_ENABLED];
        yield 'platform links' => ['platformLinksEnabled', PlatformLinksConfig::CONFIG_GROUP, PlatformLinksConfig::KEY_ENABLED];
        yield 'assistants' => ['agentsEnabled', AgentConfig::CONFIG_GROUP, AgentConfig::KEY_ENABLED];
    }

    #[DataProvider('provideWaveFeatureFlags')]
    public function testRuntimeConfigFollowsTheStoredWaveFeatureFlag(string $feature, string $group, string $setting): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $repository = static::getContainer()->get(ConfigRepository::class);

        $previous = $repository->getValue(0, $group, $setting);

        try {
            foreach (['0' => false, '1' => true] as $stored => $expected) {
                $repository->setValue(0, $group, $setting, (string) $stored);
                $em->flush();
                $em->clear();

                $client->request('GET', '/api/v1/config/runtime');
                $this->assertResponseIsSuccessful();
                $data = json_decode((string) $client->getResponse()->getContent(), true);
                $this->assertIsArray($data);
                $this->assertSame($expected, $data['features'][$feature], sprintf('%s should be %s when the global row is "%s"', $feature, var_export($expected, true), $stored));
            }
        } finally {
            if (null !== $previous) {
                $repository->setValue(0, $group, $setting, $previous);
                $em->flush();
            }
        }
    }

    /**
     * MOBILE-APP SEAM (App Review 5.1.2(i)): the app names its AI providers on
     * a consent screen that runs before sign-in, so the list has to reach an
     * anonymous client. Losing it here would leave the app disclosing nothing.
     */
    public function testRuntimeConfigNamesTheAiProvidersAnonymously(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/config/runtime');

        $this->assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('aiProviders', $data);
        $this->assertIsArray($data['aiProviders']);

        foreach ($data['aiProviders'] as $provider) {
            $this->assertIsString($provider);
            $this->assertNotSame('', $provider);
        }

        // The test provider serves the default chat model in this environment.
        // It is a fixture, not something a user's input can reach, so a
        // disclosure naming it would be wrong.
        $this->assertNotContains('test', array_map('strtolower', $data['aiProviders']));
    }

    public function testRuntimeConfigIsPublicAndFast(): void
    {
        $client = static::createClient();

        $startTime = microtime(true);
        $client->request('GET', '/api/v1/config/runtime');
        $duration = microtime(true) - $startTime;

        $this->assertResponseIsSuccessful();

        // Should be very fast (no slow health checks)
        $this->assertLessThan(0.5, $duration, 'Runtime config should respond in less than 500ms');
    }

    public function testRuntimeConfigTellsAnonymousClientsWhetherDemoLoginIsOffered(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/config/runtime');

        $this->assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('setup', $data);
        $this->assertIsArray($data['setup']);
        $this->assertArrayHasKey('demoLoginHint', $data['setup']);
        $this->assertIsBool($data['setup']['demoLoginHint']);
    }

    public function testRuntimeConfigTellsAnonymousClientsWhetherMailCanBeDelivered(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/config/runtime');

        $this->assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('auth', $data);
        $this->assertIsArray($data['auth']);
        $this->assertArrayHasKey('mailerConfigured', $data['auth']);
        $this->assertIsBool($data['auth']['mailerConfigured']);
    }

    /**
     * FM14: every declared module is listed with configured/gated booleans.
     * Seeded gates are off; flipping GATE_WHATSAPP is visible on the next request.
     */
    public function testRuntimeConfigReportsModuleConfiguredAndGatedStates(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/config/runtime');
        $this->assertResponseIsSuccessful();

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('modules', $data);
        $this->assertIsArray($data['modules']);
        $this->assertNotEmpty($data['modules']);

        foreach ($data['modules'] as $id => $state) {
            $this->assertIsString($id);
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $id);
            $this->assertIsArray($state);
            $this->assertArrayHasKey('configured', $state);
            $this->assertArrayHasKey('gated', $state);
            $this->assertIsBool($state['configured']);
            $this->assertIsBool($state['gated']);
        }

        $this->assertArrayHasKey('tika', $data['modules']);
        $this->assertArrayHasKey('whatsapp', $data['modules']);
        $this->assertFalse($data['modules']['whatsapp']['gated']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $row = $em->getRepository(Config::class)->findOneBy([
            'ownerId' => 0,
            'group' => ModuleGateConfig::GROUP,
            'setting' => ModuleGateConfig::settingFor('whatsapp'),
        ]);
        if (!$row instanceof Config) {
            $row = (new Config())
                ->setOwnerId(0)
                ->setGroup(ModuleGateConfig::GROUP)
                ->setSetting(ModuleGateConfig::settingFor('whatsapp'))
                ->setValue('0');
            $em->persist($row);
            $em->flush();
        }
        $previous = $row->getValue();
        $row->setValue('1');
        $em->flush();
        $em->clear();

        try {
            $client->request('GET', '/api/v1/config/runtime');
            $this->assertResponseIsSuccessful();
            $gated = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertIsArray($gated);
            $this->assertTrue($gated['modules']['whatsapp']['gated']);
            $this->assertSame(
                $data['modules']['whatsapp']['configured'],
                $gated['modules']['whatsapp']['configured'],
            );
        } finally {
            $restore = $em->getRepository(Config::class)->findOneBy([
                'ownerId' => 0,
                'group' => ModuleGateConfig::GROUP,
                'setting' => ModuleGateConfig::settingFor('whatsapp'),
            ]);
            if ($restore instanceof Config) {
                $restore->setValue($previous);
                $em->flush();
            }
        }
    }
}
