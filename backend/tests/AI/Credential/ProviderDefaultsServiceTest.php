<?php

declare(strict_types=1);

namespace App\Tests\AI\Credential;

use App\AI\Credential\ProviderDefaultsService;
use App\AI\Credential\ProviderKeyStore;
use App\Entity\Config;
use App\Model\ModelCatalog;
use App\Repository\ConfigRepository;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ProviderDefaultsServiceTest extends TestCase
{
    /** @var array<string, string> keyed by "owner|group|setting" */
    private array $written = [];

    private ConfigRepository&Stub $configRepository;
    private ArrayAdapter $cache;
    private ProviderDefaultsService $service;

    protected function setUp(): void
    {
        $this->written = [];

        $this->configRepository = $this->createStub(ConfigRepository::class);
        $this->configRepository->method('setValue')->willReturnCallback(
            function (int $ownerId, string $group, string $setting, string $value): Config {
                $this->written[$ownerId.'|'.$group.'|'.$setting] = $value;

                return (new Config())->setOwnerId($ownerId)->setGroup($group)->setSetting($setting)->setValue($value);
            }
        );

        $this->cache = new ArrayAdapter();
        $this->service = new ProviderDefaultsService($this->configRepository, $this->cache, new NullLogger());
    }

    /**
     * Locks the mapping promised in the class docblock: every catalog key in
     * PROVIDER_DEFAULTS must resolve to exactly one ModelCatalog entry. Fails
     * on catalog drift (renamed/removed models) at test time instead of at
     * apply time in an operator's install.
     */
    public function testEveryRecommendedDefaultResolvesInTheModelCatalog(): void
    {
        $providers = [...ProviderKeyStore::SUPPORTED_PROVIDERS, 'ollama'];
        foreach ($providers as $provider) {
            self::assertTrue(ProviderDefaultsService::supports($provider), sprintf('No recommended defaults defined for supported provider "%s"', $provider));

            $defaults = $this->service->getRecommendedDefaults($provider);

            self::assertNotEmpty($defaults);
            self::assertArrayHasKey('CHAT', $defaults, sprintf('Provider "%s" must at least bind a CHAT default', $provider));
            foreach ($defaults as $capability => $bid) {
                self::assertGreaterThan(0, $bid, sprintf('%s/%s resolved to an invalid BID', $provider, $capability));
            }
        }
    }

    public function testAutoApplyNoopsWhenCurrentDefaultIsAvailable(): void
    {
        $this->configRepository->method('getValue')->willReturn('anthropic');

        $applied = $this->service->autoApplyBestAvailable([
            'anthropic' => true,
            'groq' => true,
        ]);

        self::assertNull($applied);
        self::assertSame([], $this->written);
    }

    public function testAutoApplyPicksFirstAvailableInPreferenceOrder(): void
    {
        $this->configRepository->method('getValue')->willReturn('anthropic');

        $applied = $this->service->autoApplyBestAvailable([
            'anthropic' => false,
            'openai' => false,
            'groq' => true,
            'google' => true,
        ]);

        self::assertSame('groq', $applied);
        self::assertSame('groq', $this->written['0|ai|default_chat_provider'] ?? null);
    }

    public function testAutoApplyDoesNothingWhenNoProviderIsAvailable(): void
    {
        $this->configRepository->method('getValue')->willReturn('anthropic');

        // A reachable-but-empty Ollama must arrive here as false — otherwise the
        // install would silently bind CHAT to a model that was never pulled.
        $applied = $this->service->autoApplyBestAvailable([
            'anthropic' => false,
            'groq' => false,
            'ollama' => false,
        ]);

        self::assertNull($applied);
        self::assertSame([], $this->written, 'nothing may be written when no provider can serve chat');
    }

    public function testAutoApplyFallsThroughToOllama(): void
    {
        $this->configRepository->method('getValue')->willReturn('anthropic');

        $applied = $this->service->autoApplyBestAvailable([
            'anthropic' => false,
            'ollama' => true,
        ]);

        self::assertSame('ollama', $applied);
        self::assertSame('ollama', $this->written['0|ai|default_chat_provider'] ?? null);
    }

    /**
     * The test/E2E environment routes chat at the TestProvider on purpose, and a
     * dummy GROQ_API_KEY makes Groq look "available" (isAvailable() only checks
     * that a key string exists). Auto-apply must not hijack that: it broke the
     * Ollama and WhatsApp E2E suites by silently repointing chat at Groq, which
     * then answered "invalid api key".
     */
    public function testAutoApplyNeverOverridesTheTestProviderDefault(): void
    {
        $this->configRepository->method('getValue')->willReturn('test');

        $applied = $this->service->autoApplyBestAvailable(['test' => false, 'groq' => true], 'test');

        self::assertNull($applied);
        self::assertSame([], $this->written);
    }

    /**
     * A deliberately chosen local default must survive a transient outage — one
     * request while Ollama restarts may not permanently repoint the install at a
     * cloud provider.
     */
    public function testAutoApplyNeverOverridesAKeylessDefaultThatIsMomentarilyUnavailable(): void
    {
        $this->configRepository->method('getValue')->willReturn('ollama');

        $applied = $this->service->autoApplyBestAvailable(['ollama' => false, 'groq' => true], 'ollama');

        self::assertNull($applied);
        self::assertSame([], $this->written);
    }

    /**
     * Guards the case where the provider flag and the CHAT binding disagree: an
     * admin picked an Ollama chat model while the flag still says anthropic.
     * The explicit binding wins — nothing is rewritten.
     */
    public function testAutoApplyRespectsAnExplicitKeylessChatBindingOverTheProviderFlag(): void
    {
        $this->configRepository->method('getValue')->willReturn('anthropic');

        $applied = $this->service->autoApplyBestAvailable(['anthropic' => false, 'groq' => true], 'ollama');

        self::assertNull($applied);
        self::assertSame([], $this->written);
    }

    /**
     * The Tier 3 target case: the seeded Anthropic default with no Anthropic key.
     */
    public function testAutoApplyRepairsAKeyedCloudDefaultWithoutAKey(): void
    {
        $this->configRepository->method('getValue')->willReturn('anthropic');

        $applied = $this->service->autoApplyBestAvailable(['anthropic' => false, 'groq' => true], 'anthropic');

        self::assertSame('groq', $applied);
        self::assertSame('groq', $this->written['0|ai|default_chat_provider'] ?? null);
    }

    public function testUnknownProviderIsRejected(): void
    {
        self::assertFalse(ProviderDefaultsService::supports('not-a-provider'));

        $this->expectException(\InvalidArgumentException::class);
        $this->service->getRecommendedDefaults('not-a-provider');
    }

    public function testApplyGlobalDefaultsWritesBindingsProviderFlagAndClearsCache(): void
    {
        $item = $this->cache->getItem('model_config_probe');
        $item->set('cached');
        $this->cache->save($item);

        $applied = $this->service->applyGlobalDefaults('groq');

        foreach ($applied as $capability => $bid) {
            self::assertSame((string) $bid, $this->written['0|DEFAULTMODEL|'.$capability] ?? null, sprintf('capability %s must be written as a global default', $capability));
        }
        self::assertSame('groq', $this->written['0|ai|default_chat_provider'] ?? null);
        self::assertFalse($this->cache->getItem('model_config_probe')->isHit(), 'model_config cache must be invalidated');
    }

    public function testApplyDoesNotTouchUnrelatedCapabilities(): void
    {
        $this->service->applyGlobalDefaults('groq');

        self::assertArrayNotHasKey('0|DEFAULTMODEL|VECTORIZE', $this->written, 'VECTORIZE (local embeddings) must keep its current value');
    }

    /**
     * A recommended default is applied unattended by
     * `app:provider:apply-defaults --auto` at container start, so a binding that
     * points at a deactivated catalog row hands a fresh install a model its own
     * UI refuses to offer. Resolving to a unique BID is not enough.
     */
    public function testEveryRecommendedDefaultPointsAtAnActiveCatalogEntry(): void
    {
        $byId = $this->catalogById();

        foreach ([...ProviderKeyStore::SUPPORTED_PROVIDERS, 'ollama'] as $provider) {
            foreach ($this->service->getRecommendedDefaults($provider) as $capability => $bid) {
                self::assertSame(
                    1,
                    $byId[$bid]['active'],
                    sprintf('%s/%s recommends BID %d, which is not active in the catalog', $provider, $capability, $bid)
                );
            }
        }
    }

    /**
     * A CHAT recommendation is what a user actually talks to, so it must be a
     * model the provider can answer with rather than a media or embedding row.
     * Cheap structural check; it cannot tell whether the model still exists
     * upstream — only a live provider query can.
     */
    public function testRecommendedChatDefaultsAreChatCapable(): void
    {
        $byId = $this->catalogById();

        foreach ([...ProviderKeyStore::SUPPORTED_PROVIDERS, 'ollama'] as $provider) {
            $defaults = $this->service->getRecommendedDefaults($provider);

            foreach (['CHAT', 'TOOLS', 'ANALYZE', 'SORT', 'PLAN', 'SUMMARIZE'] as $capability) {
                if (!isset($defaults[$capability])) {
                    continue;
                }

                self::assertSame(
                    'chat',
                    $byId[$defaults[$capability]]['tag'],
                    sprintf('%s/%s must recommend a chat-tagged model', $provider, $capability)
                );
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function catalogById(): array
    {
        $byId = [];
        foreach (ModelCatalog::all() as $model) {
            $byId[(int) $model['id']] = $model;
        }

        return $byId;
    }
}
