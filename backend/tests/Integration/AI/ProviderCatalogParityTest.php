<?php

declare(strict_types=1);

namespace App\Tests\Integration\AI;

use App\AI\Catalog\Attribute\AsAiProvider;
use App\AI\Catalog\DependencyInjection\ProviderCatalogPass;
use App\AI\Catalog\DescribesProviderInterface;
use App\AI\Catalog\ModelListing;
use App\AI\Catalog\ProviderCapability;
use App\AI\Catalog\ProviderCatalog;
use App\AI\Catalog\ProviderDescriptor;
use App\AI\Catalog\ReasoningMatch;
use App\AI\Credential\ProviderDefaultsService;
use App\AI\Credential\ProviderKeyCatalog;
use App\AI\Credential\ProviderKeyStore;
use App\AI\Exception\ProviderException;
use App\AI\Health\Probe\PlatformKeyModelListProbe;
use App\AI\Messages\MessagesGateway;
use App\AI\Messages\Translator\ChatCompletionsUpstreams;
use App\AI\Messages\Translator\OpenAiMessagesTranslator;
use App\AI\Provider\ReasoningLevelCatalog;
use App\AI\Service\ProviderRegistry;
use App\AI\StructuredOutput\StructuredOutputCapability;
use App\AI\Tool\CatalogToolUse;
use App\AI\ToolCalling\ToolCallingCapability;
use App\Command\SyncModelPricesCommand;
use App\Controller\AdminProviderKeysController;
use App\Controller\MessagesGatewayController;
use App\Model\ModelCatalog;
use App\Service\Admin\SystemConfig\Tab\AiTab;
use App\Service\Egress\EgressBuiltinDestinations;
use App\Service\Voice\VoiceLiveConcurrency;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Every name-keyed provider table of `03_contracts.md` §2.3.1 (T1–T23),
 * rebuilt from the provider descriptors and compared with today's constant
 * or method, entry by entry. AU19 proves the descriptors are complete; AU20
 * switches each consumer onto the catalog and keeps this test green while
 * the constant disappears. A provider the registry knows and the catalog
 * does not fails here (T21), it is never skipped.
 */
final class ProviderCatalogParityTest extends KernelTestCase
{
    private ProviderCatalog $catalog;
    private ProviderRegistry $registry;

    protected function setUp(): void
    {
        self::bootKernel();
        $catalog = self::getContainer()->get(ProviderCatalog::class);
        \assert($catalog instanceof ProviderCatalog);
        $this->catalog = $catalog;
        $registry = self::getContainer()->get(ProviderRegistry::class);
        \assert($registry instanceof ProviderRegistry);
        $this->registry = $registry;
    }

    public function testEveryRegisteredProviderDescribesItselfWithItsOwnName(): void
    {
        $seen = 0;
        foreach (ProviderRegistry::CAPABILITIES as $capability) {
            foreach ($this->registry->getProvidersForCapability($capability) as $name => $provider) {
                self::assertTrue($this->catalog->has($name), $provider::class.' has no descriptor in the catalog');
                $descriptor = $this->catalog->get($name);
                self::assertSame($provider->getName(), $descriptor->name, $provider::class.' descriptor name differs from getName()');
                self::assertSame($provider->getDisplayName(), $descriptor->displayName, $provider::class.' descriptor display name differs from getDisplayName()');
                if ($provider instanceof DescribesProviderInterface) {
                    self::assertEquals($descriptor, $provider::descriptor(), $provider::class.' describes itself differently from the catalog');
                }
                ++$seen;
            }
        }
        self::assertGreaterThan(30, $seen);
        self::assertCount(22, $this->catalog->all(), 'twenty-one adapters and the key-only ElevenLabs row');
    }

    /** T1 — the store answers from the catalog */
    public function testKeyStoreSupportsExactlyTheKeyedDescriptors(): void
    {
        $store = self::getContainer()->get(ProviderKeyStore::class);
        \assert($store instanceof ProviderKeyStore);
        foreach ($this->catalog->all() as $descriptor) {
            self::assertSame($descriptor->hasPlatformKey(), $store->supports($descriptor->name), $descriptor->name);
        }
        self::assertFalse($store->supports('not-a-provider'));
        self::assertCount(15, $this->catalog->withKey());
    }

    /** T2 — the key catalog is a projection of the key specs */
    public function testKeyCatalogEntriesEqualTheKeySpecsInOrder(): void
    {
        $keyCatalog = new ProviderKeyCatalog($this->catalog);
        self::assertSame($keyCatalog->providerNames(), self::names($this->catalog->withKey()), 'Admin → AI keys order');
        self::assertSame(['groq', 'openai', 'anthropic', 'google', 'mistral', 'trustedtokens', 'a2agent', 'huggingface', 'meta', 'cerebras', 'xai', 'perplexity', 'thehive', 'higgsfield', 'elevenlabs'], $keyCatalog->providerNames());

        foreach ($this->catalog->withKey() as $descriptor) {
            $meta = $keyCatalog->get($descriptor->name);
            $key = $descriptor->key;
            self::assertNotNull($key);
            self::assertSame($meta['displayName'], $key->presentation->displayName ?? $descriptor->displayName, $descriptor->name.' displayName');
            self::assertSame($meta['envVar'], $key->envVar, $descriptor->name.' envVar');
            self::assertSame($meta['secretEnvVar'] ?? null, $key->secretEnvVar, $descriptor->name.' secretEnvVar');
            self::assertSame($meta['consoleUrl'], $key->consoleUrl, $descriptor->name.' consoleUrl');
            self::assertSame($meta['freeTier'], $key->presentation->freeTier, $descriptor->name.' freeTier');
            self::assertSame($meta['recommended'], $key->presentation->recommended, $descriptor->name.' recommended');
            self::assertSame($meta['validation'], $key->validation?->toArray(), $descriptor->name.' validation');
            self::assertSame($meta['chat'] ?? true, $key->servesChat, $descriptor->name.' chat');
            self::assertSame($meta['modelListing'] ?? true, ModelListing::Lists === $key->modelListing || null === $key->validation, $descriptor->name.' modelListing');
            self::assertSame($keyCatalog->listsModels($descriptor->name), ModelListing::Lists === $descriptor->modelListing(), $descriptor->name.' listsModels');
            self::assertSame($keyCatalog->requiresSecret($descriptor->name), null !== $key->secretEnvVar, $descriptor->name.' requiresSecret');
        }
        self::assertFalse($keyCatalog->has('ollama'), 'no platform key');
        self::assertFalse($keyCatalog->has('cloudflare'), 'env-only token, not in the store');
        self::assertSame('higgsfield', $keyCatalog->providerForEnvVar('HIGGSFIELD_API_SECRET'));
        self::assertNull($keyCatalog->providerForEnvVar('GEMINI_API_KEY'), 'aliases are not managed fields');
    }

    /** T3 — the compiler pass wires the store's environment from the descriptors */
    public function testKeyStoreEnvArgumentsComeFromTheKeySpecs(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(ProviderCatalog::class, new Definition(ProviderCatalog::class, ['$providerClasses' => []]));
        $container->setDefinition(ProviderKeyStore::class, new Definition(ProviderKeyStore::class));
        // The source-tree discovery and the container's tags name the same classes.
        self::assertSame(ProviderCatalog::builtIn()->names(), $this->catalog->names());
        foreach (ProviderCatalog::builtIn()->providerClasses() as $class) {
            $container->setDefinition($class, (new Definition($class))->addTag(AsAiProvider::TAG));
        }

        (new ProviderCatalogPass())->process($container);

        $expectedKeys = [];
        $expectedSecrets = [];
        foreach ($this->catalog->withKey() as $descriptor) {
            $key = $descriptor->key;
            self::assertNotNull($key);
            $expressions = array_map(static fn (string $name): string => sprintf('%%env(default::%s)%%', $name), [(string) $key->envVar, ...$key->envAliases]);
            $expectedKeys[$descriptor->name] = 1 === \count($expressions) ? $expressions[0] : $expressions;
            if (null !== $key->secretEnvVar) {
                $expectedSecrets[$descriptor->name] = sprintf('%%env(default::%s)%%', $key->secretEnvVar);
            }
        }
        $store = $container->getDefinition(ProviderKeyStore::class);
        $envKeys = $store->getArgument('$envKeys');
        ksort($envKeys);
        ksort($expectedKeys);
        self::assertSame($expectedKeys, $envKeys);
        self::assertSame($expectedSecrets, $store->getArgument('$envSecrets'));
        self::assertSame(['%env(default::GOOGLE_GEMINI_API_KEY)%', '%env(default::GEMINI_API_KEY)%', '%env(default::GOOGLE_API_KEY)%'], $envKeys['google']);
    }

    /** T4 */
    public function testGatewayByokProvidersEqualTheDescriptorsWithAUserKey(): void
    {
        self::assertSameSet(self::constant(MessagesGatewayController::class, 'SUPPORTED_PROVIDERS'), self::names($this->catalog->withUserKey()));
    }

    /** T5 — the defaults service resolves the descriptor bindings */
    public function testRecommendedDefaultsComeFromTheDescriptorBindings(): void
    {
        $defaults = self::getContainer()->get(ProviderDefaultsService::class);
        \assert($defaults instanceof ProviderDefaultsService);
        $seen = 0;
        foreach ($this->catalog->all() as $descriptor) {
            self::assertSame(null !== $descriptor->defaults, $defaults->supports($descriptor->name), $descriptor->name.' supports');
            if (null === $descriptor->defaults) {
                continue;
            }
            $resolved = $defaults->getRecommendedDefaults($descriptor->name);
            self::assertSame(array_keys($descriptor->defaults->bindings), array_keys($resolved), $descriptor->name.' capabilities');
            foreach ($descriptor->defaults->bindings as $capability => $modelKey) {
                self::assertSame(ModelCatalog::findBidByKey($modelKey), $resolved[$capability], "{$descriptor->name} {$capability}: {$modelKey}");
            }
            ++$seen;
        }
        self::assertSame(13, $seen, 'twelve keyed chat providers and Ollama declare defaults');
    }

    /** T6 — the preference order is the descriptor ranks */
    public function testPreferenceOrderEqualsTheDescriptorRanks(): void
    {
        $ranked = [];
        foreach ($this->catalog->all() as $descriptor) {
            $rank = $descriptor->defaults?->preferenceRank;
            if (null !== $rank) {
                self::assertArrayNotHasKey($rank, $ranked, "two providers share preference rank {$rank}");
                $ranked[$rank] = $descriptor->name;
            }
        }
        ksort($ranked);
        $defaults = self::getContainer()->get(ProviderDefaultsService::class);
        \assert($defaults instanceof ProviderDefaultsService);
        self::assertSame(array_values($ranked), $defaults->preferenceOrder());
        self::assertSame(['groq', 'openai', 'google', 'mistral', 'anthropic', 'trustedtokens', 'huggingface', 'xai', 'a2agent', 'meta', 'cerebras', 'ollama'], $defaults->preferenceOrder());
        self::assertSame(range(1, \count($ranked)), array_keys($ranked), 'ranks are 1..n without gaps');
    }

    /** T7 */
    public function testChatCompletionsUrlsEqualTheChatCompletionsEndpoints(): void
    {
        $expected = [];
        foreach ($this->catalog->all() as $descriptor) {
            $url = $descriptor->chatCompletionsUrl();
            if (null !== $url) {
                $expected[$descriptor->name] = $url;
            }
        }
        $actual = ChatCompletionsUpstreams::URLS;
        ksort($expected);
        ksort($actual);
        self::assertSame($actual, $expected);
        foreach ($expected as $name => $url) {
            self::assertSame($url, ChatCompletionsUpstreams::fixedUrl($name));
        }
    }

    /** T8 */
    public function testLocalChatCompletionsHostsEqualTheLocalDescriptors(): void
    {
        $local = self::names(array_filter($this->catalog->all(), static fn (ProviderDescriptor $d): bool => $d->isLocal()));
        self::assertSameSet(ChatCompletionsUpstreams::LOCAL_PROVIDERS, $local);
        foreach ($this->catalog->all() as $descriptor) {
            self::assertSame($descriptor->isLocal(), ChatCompletionsUpstreams::isLocal($descriptor->name), $descriptor->name);
            self::assertSame(null !== $descriptor->chatCompletionsEndpoint(), ChatCompletionsUpstreams::supports($descriptor->name), $descriptor->name.' supports');
        }
    }

    /** T9 */
    public function testStructuredOutputDialectsEqualTheDescriptorDialects(): void
    {
        $capability = new StructuredOutputCapability();
        foreach ($this->catalog->all() as $descriptor) {
            $dialects = $descriptor->dialects;
            self::assertSame($capability->dialect($descriptor->name), $dialects->structuredOutput, $descriptor->name.' dialect');
            self::assertSame(
                $capability->supports($descriptor->name, null, false),
                null !== $dialects->structuredOutput,
                $descriptor->name.' buffered schema support',
            );
            self::assertSame(
                $capability->supports($descriptor->name, null, true),
                null !== $dialects->structuredOutput && $dialects->schemaWithStreaming,
                $descriptor->name.' streaming schema support',
            );
        }
        $streamingRefusers = self::names(array_filter($this->catalog->all(), static fn (ProviderDescriptor $d): bool => !$d->dialects->schemaWithStreaming));
        self::assertSameSet(self::constant(StructuredOutputCapability::class, 'NO_SCHEMA_WITH_STREAMING'), $streamingRefusers);
    }

    /** T10 */
    public function testModelLevelStructuredOutputRulesEqualTheDescriptorLists(): void
    {
        $capability = new StructuredOutputCapability();

        $unavailable = [];
        $strict = [];
        foreach ($this->catalog->all() as $descriptor) {
            foreach ($descriptor->dialects->structuredOutputUnavailableModelPrefixes as $prefix) {
                $unavailable[] = $prefix;
                self::assertFalse($capability->supports($descriptor->name, $prefix.'-20260101', false), "{$descriptor->name} {$prefix}");
            }
            if ([] !== $descriptor->dialects->strictSchemaModels) {
                $strict[$descriptor->name] = $descriptor->dialects->strictSchemaModels;
                foreach ($descriptor->dialects->strictSchemaModels as $model) {
                    self::assertTrue($capability->supportsStrict($descriptor->name, $model), "{$descriptor->name} {$model} strict");
                }
            }
        }
        self::assertSameSet(self::constant(StructuredOutputCapability::class, 'ANTHROPIC_NO_FORCED_TOOL_CHOICE_PREFIXES'), $unavailable);
        self::assertSame(self::constant(StructuredOutputCapability::class, 'STRICT_CAPABLE_MODELS'), $strict);
    }

    /** T11 */
    public function testToolCallingRulesEqualTheDescriptorDialects(): void
    {
        $capability = new ToolCallingCapability();
        foreach ($this->catalog->all() as $descriptor) {
            self::assertSame($capability->supports($descriptor->name, null, true), $descriptor->dialects->nativeToolRouting, $descriptor->name.' native routing');
            self::assertSame($capability->conflictsWithStructuredOutput($descriptor->name), !$descriptor->dialects->toolsWithStructuredOutput, $descriptor->name.' tools with schema');
        }
    }

    /** T12 */
    public function testCatalogToolUseServicesEqualTheDescriptorFlag(): void
    {
        foreach ($this->catalog->all() as $descriptor) {
            self::assertSame(CatalogToolUse::isCapableChatService($descriptor->name), $descriptor->dialects->catalogToolUse, $descriptor->name);
        }
        $flagged = self::names(array_filter($this->catalog->all(), static fn (ProviderDescriptor $d): bool => $d->dialects->catalogToolUse));
        self::assertSameSet(CatalogToolUse::CAPABLE_CHAT_SERVICES, $flagged);
    }

    /** T13 */
    public function testInlinePngJpegOnlyUpstreamEqualsTheDescriptorImageRule(): void
    {
        $pngJpegOnly = self::names(array_filter($this->catalog->all(), static fn (ProviderDescriptor $d): bool => 'png_jpeg_only' === $d->dialects->inlineImages->value));
        self::assertSame([self::constant(OpenAiMessagesTranslator::class, 'INLINE_PNG_JPEG_ONLY')], $pngJpegOnly);
    }

    /** T14 — `gemini` names no provider; it is `google`'s alias (§2.3.4 #1) */
    public function testVisionRewriteProvidersEqualTheDescriptorFlag(): void
    {
        $today = self::constant(MessagesGateway::class, 'VISION_REWRITE_PROVIDERS');
        $known = array_values(array_filter($today, fn (string $name): bool => $this->catalog->has($name)));
        self::assertSame(['gemini'], array_values(array_diff($today, $known)), 'the only alias on the list');

        $flagged = self::names(array_filter($this->catalog->all(), static fn (ProviderDescriptor $d): bool => $d->dialects->gatewayVisionRewrite));
        self::assertSameSet($known, $flagged);
    }

    /** T15 */
    public function testReasoningLaddersEqualTheReasoningLevelCatalog(): void
    {
        foreach ($this->catalog->all() as $descriptor) {
            $ladder = $descriptor->dialects->reasoning;
            foreach (['zzz-no-such-model', 'gpt-4o', 'claude-3-haiku'] as $unmatched) {
                self::assertSame(
                    $ladder?->levelsFor($unmatched),
                    ReasoningLevelCatalog::levels($descriptor->name, $unmatched, ['reasoning']),
                    "{$descriptor->name} {$unmatched}",
                );
            }
            if (null === $ladder) {
                continue;
            }
            foreach ($ladder->rules as $rule) {
                $ids = match ($rule->match) {
                    ReasoningMatch::Any => ['any-model'],
                    ReasoningMatch::Prefix => array_map(static fn (string $p): string => $p.'-2026', $rule->patterns),
                    ReasoningMatch::Contains => array_map(static fn (string $p): string => 'vendor/'.$p.'-thinking', $rule->patterns),
                };
                foreach ($ids as $id) {
                    self::assertSame(
                        ReasoningLevelCatalog::levels($descriptor->name, $id, ['reasoning']),
                        $ladder->levelsFor($id),
                        "{$descriptor->name} {$id}",
                    );
                }
            }
            self::assertNull(ReasoningLevelCatalog::levels($descriptor->name, 'any-model', []), $descriptor->name.' without the reasoning feature');
        }

        // An inventory of today's model families, independent of the descriptors:
        // a ladder or a pattern dropped from a descriptor fails here.
        foreach (self::REASONING_FAMILIES as [$service, $id, $levels]) {
            self::assertSame($levels, ReasoningLevelCatalog::levels($service, $id, ['reasoning']), "today: {$service} {$id}");
            self::assertSame($levels, $this->catalog->get($service)->dialects->reasoning?->levelsFor($id), "descriptor: {$service} {$id}");
        }
    }

    /**
     * Every arm of `ReasoningLevelCatalog::levels()` and `OpenAiReasoningEffort::TIERS`
     * on `main` @ `619ad883f`, as (service, model id, levels or null).
     *
     * @var list<array{string, string, list<string>|null}>
     */
    private const REASONING_FAMILIES = [
        ['openai', 'gpt-6-sol', ['none', 'low', 'medium', 'high', 'xhigh', 'max']],
        ['openai', 'gpt-6-luna', ['none', 'low', 'medium', 'high', 'xhigh', 'max']],
        ['openai', 'gpt-6.1-sol', ['low', 'medium', 'high', 'xhigh', 'max']],
        ['openai', 'gpt-6-astra', ['low', 'medium', 'high', 'xhigh', 'max']],
        ['openai', 'gpt-5.6', ['none', 'low', 'medium', 'high', 'xhigh', 'max']],
        ['openai', 'gpt-5.5-pro', ['medium', 'high', 'xhigh']],
        ['openai', 'gpt-5.5', ['none', 'low', 'medium', 'high', 'xhigh']],
        ['openai', 'gpt-5.4-mini', ['none', 'low', 'medium', 'high', 'xhigh']],
        ['openai', 'gpt-5', ['minimal', 'low', 'medium', 'high']],
        ['openai', 'o1', ['low', 'medium', 'high']],
        ['openai', 'o3-mini', ['low', 'medium', 'high']],
        ['openai', 'o4-mini', ['low', 'medium', 'high']],
        ['openai', 'gpt-4o', null],
        ['xai', 'grok-4.7', ['low', 'medium', 'high', 'xhigh']],
        ['xai', 'grok-4.3', ['none', 'low', 'medium', 'high']],
        ['xai', 'grok-4.5', null],
        ['google', 'gemini-3.8-flash', ['low', 'medium', 'high']],
        ['anthropic', 'claude-sonnet-5-5-20260601', ['low', 'medium', 'high', 'xhigh', 'max']],
        ['anthropic', 'claude-opus-4-6', ['low', 'medium', 'high']],
        ['anthropic', 'claude-opus-4-7', ['low', 'medium', 'high']],
        ['anthropic', 'claude-opus-4-8', ['low', 'medium', 'high']],
        ['anthropic', 'claude-sonnet-4-6', ['low', 'medium', 'high']],
        ['anthropic', 'claude-opus-5-5', ['low', 'medium', 'high']],
        ['anthropic', 'claude-opus-5', ['low', 'medium', 'high']],
        ['anthropic', 'claude-sonnet-5', ['low', 'medium', 'high']],
        ['anthropic', 'claude-fable-5-1', ['low', 'medium', 'high']],
        ['anthropic', 'claude-fable-5', ['low', 'medium', 'high']],
        ['anthropic', 'claude-haiku-4-5-20251001', null],
        ['meta', 'muse-spark-1.3', ['minimal', 'low', 'medium', 'high', 'xhigh', 'max']],
        ['huggingface', 'moonshotai/Kimi-K3', ['low', 'high', 'max']],
        ['huggingface', 'moonshotai/Kimi-K2.6-deepinfra', ['low', 'high', 'max']],
        ['cerebras', 'qwen-3.8-27b', ['none', 'low', 'medium', 'high']],
        ['cerebras', 'gpt-oss-120b', ['low', 'medium', 'high']],
        ['groq', 'openai/gpt-oss-120b', null],
        ['mistral', 'mistral-large-4', null],
    ];

    /** T16 */
    public function testModelListProbeSupportEqualsTheDescriptorModelListing(): void
    {
        $probe = self::getContainer()->get(PlatformKeyModelListProbe::class);
        \assert($probe instanceof PlatformKeyModelListProbe);
        foreach ($this->catalog->all() as $descriptor) {
            self::assertSame(
                $probe->supports($descriptor->name),
                $descriptor->hasPlatformKey() && ModelListing::None !== $descriptor->modelListing(),
                $descriptor->name,
            );
        }
        $credentialsOnly = self::names(array_filter($this->catalog->all(), static fn (ProviderDescriptor $d): bool => ModelListing::CredentialsOnly === $d->modelListing()));
        self::assertSameSet(self::constant(PlatformKeyModelListProbe::class, 'NO_LISTING'), $credentialsOnly);
    }

    /** T17 — prefixes and vendors are sequences per provider (the first existing key wins) */
    public function testPricePrefixesVendorsAndSkipListEqualTheDescriptorPricing(): void
    {
        $prefixes = [];
        $vendors = [];
        $skipped = [];
        foreach ($this->catalog->all() as $descriptor) {
            if (!$descriptor->pricing->synced) {
                $skipped[] = $descriptor->name;
            } elseif ([] !== $descriptor->pricing->litellmPrefixes) {
                $prefixes[$descriptor->name] = $descriptor->pricing->litellmPrefixes;
            }
            if ([] !== $descriptor->pricing->bareMatchVendors) {
                $vendors[$descriptor->name] = $descriptor->pricing->bareMatchVendors;
            }
            self::assertSame([$descriptor->name], \App\AI\Catalog\ProviderPricing::byServiceName()->prefixesFor($descriptor->name));
        }
        $actualPrefixes = self::constant(SyncModelPricesCommand::class, 'PREFIX_MAP');
        ksort($prefixes);
        ksort($actualPrefixes);
        self::assertSame($actualPrefixes, $prefixes);
        self::assertSameSet(self::constant(SyncModelPricesCommand::class, 'LOCAL_PROVIDERS'), $skipped);

        // `cohere` and `jina` are rerank / embedding plugs, not providers; they
        // stay with the command (03 §2.3.1 T17).
        $actualVendors = self::constant(SyncModelPricesCommand::class, 'BARE_MATCH_PROVIDERS');
        $plugs = array_filter(array_keys($actualVendors), fn (string $name): bool => !$this->catalog->has($name));
        self::assertSame(['cohere', 'jina'], array_values($plugs));
        $actualVendors = array_diff_key($actualVendors, array_flip($plugs));
        ksort($vendors);
        ksort($actualVendors);
        self::assertSame($actualVendors, $vendors);
    }

    /** T18 — `compatible` is the live-voice name of `openaicompatible` (§2.3.4 #2) */
    public function testLiveVoiceCapsEqualTheDescriptorSessions(): void
    {
        $today = self::constant(VoiceLiveConcurrency::class, 'DEFAULTS');
        if (isset($today['compatible'])) {
            $today['openaicompatible'] = $today['compatible'];
            unset($today['compatible']);
        }
        $expected = [];
        foreach ($this->catalog->all() as $descriptor) {
            if (null !== $descriptor->liveVoiceSessions) {
                $expected[$descriptor->name] = $descriptor->liveVoiceSessions;
            }
        }
        ksort($today);
        ksort($expected);
        self::assertSame($today, $expected);
    }

    /** T19 */
    public function testAdminProviderKeysPathEnumEqualsTheKeyedDescriptors(): void
    {
        $enum = null;
        foreach ((new \ReflectionMethod(AdminProviderKeysController::class, 'save'))->getAttributes(OA\Parameter::class) as $attribute) {
            $parameter = $attribute->newInstance();
            if ('provider' === $parameter->name && $parameter->schema instanceof OA\Schema) {
                $enum = $parameter->schema->enum;
            }
        }
        self::assertIsArray($enum, 'the save route still declares the provider enum (dropped in AU21)');
        self::assertSameSet($enum, self::names($this->catalog->withKey()));
    }

    /** T20 */
    public function testSystemConfigPasswordFieldsEqualTheKeySpecs(): void
    {
        $tab = self::getContainer()->get(AiTab::class);
        \assert($tab instanceof AiTab);

        $fields = [];
        foreach ($tab->sections() as $section) {
            foreach ($section->fields as $envVar => $field) {
                if ('password' === $field->type) {
                    $fields[$envVar] = $field->description;
                }
            }
        }

        $described = [];
        foreach ($this->catalog->all() as $descriptor) {
            $key = $descriptor->key;
            if (null === $key) {
                continue;
            }
            foreach ($key->envVars() as $envVar) {
                $described[$envVar] = $descriptor->name;
            }
            $fieldDescription = $key->presentation->fieldDescription;
            if (null !== $fieldDescription) {
                self::assertArrayHasKey((string) $key->envVar, $fields, "{$descriptor->name} field");
                self::assertSame($fields[$key->envVar], $fieldDescription, "{$descriptor->name} field description");
            }
            $secretDescription = $key->presentation->secretFieldDescription;
            if (null !== $secretDescription) {
                self::assertArrayHasKey((string) $key->secretEnvVar, $fields, "{$descriptor->name} secret field");
                self::assertSame($fields[$key->secretEnvVar], $secretDescription, "{$descriptor->name} secret field description");
            }
        }

        foreach ($fields as $envVar => $description) {
            $owner = (new ProviderKeyCatalog($this->catalog))->providerForEnvVar($envVar);
            if (null === $owner) {
                continue;
            }
            self::assertSame($owner, $described[$envVar] ?? null, "{$envVar} belongs to {$owner}");
            $key = $this->catalog->get($owner)->key;
            self::assertNotNull($key);
            // The legacy field's text must be on the descriptor, so a dropped
            // description is caught before AiTab reads the catalog.
            $expected = $envVar === $key->secretEnvVar ? $key->presentation->secretFieldDescription : $key->presentation->fieldDescription;
            self::assertSame($description, $expected, "{$envVar} description");
        }
        self::assertCount(15, array_filter(array_keys($fields), static fn (string $envVar): bool => null !== ProviderKeyCatalog::providerForEnvVar($envVar)), 'fourteen key fields and one secret field are managed');
    }

    /** T21 */
    public function testContainerTagsEqualTheDescriptorCapabilities(): void
    {
        foreach (ProviderCapability::cases() as $capability) {
            self::assertSameSet(
                $this->registry->getRegisteredProviderNames($capability->value),
                self::names($this->catalog->withCapability($capability)),
                $capability->tag(),
            );
        }
        self::assertSame(ProviderRegistry::CAPABILITIES, array_map(static fn (ProviderCapability $c): string => $c->value, ProviderCapability::cases()));
    }

    /** T22 */
    public function testProviderExceptionSpellingsEqualTheDisplayNames(): void
    {
        foreach (self::constant(ProviderException::class, 'DISPLAY_NAMES') as $name => $spelling) {
            self::assertSame($this->catalog->get($name)->displayName, $spelling, $name);
            self::assertSame($spelling, ProviderException::displayName($name));
        }
    }

    /** T23 */
    public function testEgressProviderHostsAreAllDescribedEndpoints(): void
    {
        $providerHosts = [
            'api.openai.com', 'api.anthropic.com', 'generativelanguage.googleapis.com', 'api.groq.com',
            'api.mistral.ai', 'api.x.ai', 'router.huggingface.co', 'api.trustedtokens.eu', 'a2agent.me',
            'api.meta.ai', 'api.cerebras.ai', 'api.perplexity.ai',
        ];
        $hosts = $this->catalog->endpoints();
        foreach ($providerHosts as $host) {
            self::assertContains($host, EgressBuiltinDestinations::PROVIDERS, $host.' is on the static list');
            self::assertContains($host, $hosts, $host.' is a described endpoint');
        }
    }

    /**
     * @param array<ProviderDescriptor> $descriptors a list or a filtered array
     *
     * @return list<string>
     */
    private static function names(array $descriptors): array
    {
        return array_map(static fn (ProviderDescriptor $d): string => $d->name, array_values($descriptors));
    }

    /**
     * @param list<string> $expected
     * @param list<string> $actual
     */
    private static function assertSameSet(array $expected, array $actual, string $message = ''): void
    {
        $expected = array_values(array_unique($expected));
        $actual = array_values(array_unique($actual));
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual, $message);
    }

    private static function constant(string $class, string $name): mixed
    {
        return (new \ReflectionClassConstant($class, $name))->getValue();
    }
}
