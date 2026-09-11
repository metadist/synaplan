<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Bundle\BundleConfig;
use App\Entity\Config;
use App\Module\Gate\ModuleGateConfig;
use App\Repository\ConfigRepository;
use App\Service\Agent\AgentConfig;
use App\Service\Desktop\DesktopAgentConfig;
use App\Service\Document\DocumentToolsConfig;
use App\Service\Feature\FeatureFlagEnv;
use App\Service\Iam\IamConfig;
use App\Service\PlatformLink\PlatformLinksConfig;
use App\Service\SavedTask\SavedTaskConfig;
use App\Service\SavedTask\WorkflowsConfig;
use App\Service\Tool\ToolsConfig;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests for ConfigController (memory service check and runtime config).
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
     * Every wave feature flag the runtime payload exposes, with the global
     * BCONFIG row it must follow. Sharing and group policies are additionally
     * gated on groups, so those cases force the groups row ON first.
     *
     * @return iterable<string, array{string, string, string, list<array{string, string}>}>
     */
    public static function provideWaveFeatureFlags(): iterable
    {
        $groupsOn = [[IamConfig::CONFIG_GROUP, IamConfig::KEY_GROUPS_ENABLED]];

        yield 'saved tasks' => ['savedTasks', SavedTaskConfig::CONFIG_GROUP, SavedTaskConfig::KEY_ENABLED, []];
        yield 'desktop' => ['desktopAgentEnabled', DesktopAgentConfig::CONFIG_GROUP, DesktopAgentConfig::KEY_ENABLED, []];
        yield 'platform links' => ['platformLinksEnabled', PlatformLinksConfig::CONFIG_GROUP, PlatformLinksConfig::KEY_ENABLED, []];
        yield 'assistants' => ['agentsEnabled', AgentConfig::CONFIG_GROUP, AgentConfig::KEY_ENABLED, []];
        yield 'bundles' => ['bundleEnabled', BundleConfig::CONFIG_GROUP, BundleConfig::KEY_ENABLED, []];
        yield 'groups' => ['iamGroups', IamConfig::CONFIG_GROUP, IamConfig::KEY_GROUPS_ENABLED, []];
        yield 'sharing' => ['iamSharing', IamConfig::CONFIG_GROUP, IamConfig::KEY_SHARING_ENABLED, $groupsOn];
        yield 'group policies' => ['iamPolicies', IamConfig::CONFIG_GROUP, IamConfig::KEY_GROUP_POLICIES_ENABLED, $groupsOn];
        yield 'document tools' => ['documentToolsEnabled', DocumentToolsConfig::CONFIG_GROUP, DocumentToolsConfig::KEY_ENABLED, []];
        yield 'tool registry' => ['toolsRegistryEnabled', ToolsConfig::CONFIG_GROUP, ToolsConfig::KEY_REGISTRY_ENABLED, []];
        yield 'approvals' => ['toolsApprovalsEnabled', ToolsConfig::CONFIG_GROUP, ToolsConfig::KEY_APPROVALS_ENABLED, []];
        yield 'custom http tools' => ['toolsCustomHttpEnabled', ToolsConfig::CONFIG_GROUP, ToolsConfig::KEY_CUSTOM_HTTP_ENABLED, []];
        yield 'workflow builder' => ['workflowsBuilderEnabled', WorkflowsConfig::CONFIG_GROUP, WorkflowsConfig::KEY_BUILDER_ENABLED, []];
    }

    /**
     * The wave feature flags ship ON, so the runtime payload has to follow the
     * stored global row in both directions rather than a code default.
     *
     * @param list<array{string, string}> $prerequisites rows forced to '1' for the duration of the case
     */
    #[DataProvider('provideWaveFeatureFlags')]
    public function testRuntimeConfigFollowsTheStoredWaveFeatureFlag(string $feature, string $group, string $setting, array $prerequisites): void
    {
        $client = static::createClient();
        $restore = $this->rememberGlobalRows([[$group, $setting], ...$prerequisites]);

        try {
            foreach ($prerequisites as [$prerequisiteGroup, $prerequisiteSetting]) {
                $this->storeGlobalRow($prerequisiteGroup, $prerequisiteSetting, '1');
            }

            foreach (['0' => false, '1' => true] as $stored => $expected) {
                $this->storeGlobalRow($group, $setting, (string) $stored);

                $this->assertSame($expected, $this->fetchRuntimeFeature($client, $feature), sprintf('%s should be %s when the global row is "%s"', $feature, var_export($expected, true), $stored));
            }
        } finally {
            $restore();
        }
    }

    /**
     * FEATURE_<GROUP>_<SETTING> pins the flag for automated deployments and has
     * to beat the stored row in both directions — the SPA reads this payload,
     * so a pin the backend honours but the runtime config ignores would show
     * menus for a feature every API call then rejects.
     */
    public function testRuntimeConfigHonoursTheEnvironmentPinOverTheStoredRow(): void
    {
        $client = static::createClient();
        $envVar = FeatureFlagEnv::envVarFor(AgentConfig::CONFIG_GROUP, AgentConfig::KEY_ENABLED);
        $this->assertSame('FEATURE_AGENTS_ENABLED', $envVar);

        $restore = $this->rememberGlobalRows([[AgentConfig::CONFIG_GROUP, AgentConfig::KEY_ENABLED]]);
        $envWasSet = \array_key_exists($envVar, $_ENV);
        $previousEnv = $envWasSet ? $_ENV[$envVar] : null;

        try {
            $_ENV[$envVar] = 'false';
            $this->storeGlobalRow(AgentConfig::CONFIG_GROUP, AgentConfig::KEY_ENABLED, '1');
            $this->assertFalse($this->fetchRuntimeFeature($client, 'agentsEnabled'), 'a false pin must hide the feature although the row is ON');

            $_ENV[$envVar] = 'true';
            $this->storeGlobalRow(AgentConfig::CONFIG_GROUP, AgentConfig::KEY_ENABLED, '0');
            $this->assertTrue($this->fetchRuntimeFeature($client, 'agentsEnabled'), 'a true pin must show the feature although the row is OFF');
        } finally {
            if ($envWasSet) {
                $_ENV[$envVar] = $previousEnv;
            } else {
                unset($_ENV[$envVar]);
            }
            $restore();
        }
    }

    private function fetchRuntimeFeature(KernelBrowser $client, string $feature): bool
    {
        $client->request('GET', '/api/v1/config/runtime');
        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey($feature, $data['features']);
        $this->assertIsBool($data['features'][$feature]);

        return $data['features'][$feature];
    }

    private function storeGlobalRow(string $group, string $setting, string $value): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        static::getContainer()->get(ConfigRepository::class)->setValue(0, $group, $setting, $value);
        $em->flush();
        $em->clear();
    }

    /**
     * Snapshot the given global rows and return a closure that puts the table
     * back exactly — including deleting rows that did not exist before, so a
     * later test still sees the "no row" default.
     *
     * @param list<array{string, string}> $rows
     */
    private function rememberGlobalRows(array $rows): \Closure
    {
        $repository = static::getContainer()->get(ConfigRepository::class);
        $snapshot = [];
        foreach ($rows as [$group, $setting]) {
            $snapshot[$group.'.'.$setting] = [$group, $setting, $repository->getValue(0, $group, $setting)];
        }

        return function () use ($snapshot): void {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            $repository = static::getContainer()->get(ConfigRepository::class);
            foreach ($snapshot as [$group, $setting, $previous]) {
                if (null === $previous) {
                    $repository->deleteValue(0, $group, $setting);
                } else {
                    $repository->setValue(0, $group, $setting, $previous);
                }
            }
            $em->flush();
            $em->clear();
        };
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
