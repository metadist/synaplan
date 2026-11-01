<?php

declare(strict_types=1);

namespace App\Tests\Integration\AI;

use App\AI\Catalog\Attribute\AsAiProvider;
use App\AI\Catalog\DependencyInjection\ProviderCatalogPass;
use App\AI\Catalog\DescribesProviderInterface;
use App\AI\Catalog\InlineImageRule;
use App\AI\Catalog\ModelListing;
use App\AI\Catalog\ProviderCapability;
use App\AI\Catalog\ProviderCatalog;
use App\AI\Catalog\ProviderDescriptor;
use App\AI\Catalog\ReasoningMatch;
use App\AI\Credential\ProviderDefaultsService;
use App\AI\Credential\ProviderKeyCatalog;
use App\AI\Credential\ProviderKeyStore;
use App\AI\Credential\UserProviderKeyResolver;
use App\AI\Exception\ProviderException;
use App\AI\Health\Probe\PlatformKeyModelListProbe;
use App\AI\Messages\Translator\ChatCompletionsUpstreams;
use App\AI\Messages\Translator\OpenAiMessagesTranslator;
use App\AI\Provider\ReasoningLevelCatalog;
use App\AI\Service\ModelProbeResult;
use App\AI\Service\ProviderRegistry;
use App\AI\StructuredOutput\StructuredOutputCapability;
use App\AI\StructuredOutput\StructuredOutputDialect;
use App\AI\Tool\CatalogToolUse;
use App\AI\ToolCalling\ToolCallingCapability;
use App\Command\SyncModelPricesCommand;
use App\Controller\AdminProviderKeysController;
use App\Entity\Model;
use App\Model\ModelCatalog;
use App\Service\Admin\SystemConfig\Tab\AiTab;
use App\Service\Egress\EgressBuiltinDestinations;
use App\Service\Egress\EgressPolicy;
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

    /** T4 — the gateway's BYOK list is the resolver's view of the descriptors */
    public function testGatewayByokProvidersEqualTheDescriptorsWithAUserKey(): void
    {
        $resolver = self::getContainer()->get(UserProviderKeyResolver::class);
        \assert($resolver instanceof UserProviderKeyResolver);
        self::assertSame(self::names($this->catalog->withUserKey()), $resolver->supportedProviders());
        self::assertSame(['openai', 'anthropic', 'google'], $resolver->supportedProviders());
        self::assertTrue($resolver->supports('Anthropic'));
        self::assertFalse($resolver->supports('higgsfield'), 'a personal pair is not a gateway key');
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

    /** T7 — the Messages gateway's fixed upstreams are the Chat Completions endpoints */
    public function testChatCompletionsUrlsEqualTheChatCompletionsEndpoints(): void
    {
        $upstreams = self::getContainer()->get(ChatCompletionsUpstreams::class);
        \assert($upstreams instanceof ChatCompletionsUpstreams);
        $fixed = [];
        foreach ($this->catalog->all() as $descriptor) {
            $url = $descriptor->chatCompletionsUrl();
            self::assertSame($url, $upstreams->fixedUrl($descriptor->name), $descriptor->name);
            self::assertSame($url, $upstreams->completionsUrl(['provider' => $descriptor->name]) === $url ? $url : null, $descriptor->name.' completionsUrl');
            if (null !== $url) {
                $fixed[$descriptor->name] = $url;
            }
        }
        ksort($fixed);
        self::assertSame([
            'a2agent' => 'https://a2agent.me/v1/chat/completions',
            'cerebras' => 'https://api.cerebras.ai/v1/chat/completions',
            'groq' => 'https://api.groq.com/openai/v1/chat/completions',
            'huggingface' => 'https://router.huggingface.co/v1/chat/completions',
            'meta' => 'https://api.meta.ai/v1/chat/completions',
            'mistral' => 'https://api.mistral.ai/v1/chat/completions',
            'openai' => 'https://api.openai.com/v1/chat/completions',
            'perplexity' => 'https://api.perplexity.ai/chat/completions',
            'trustedtokens' => 'https://api.trustedtokens.eu/v1/chat/completions',
            'xai' => 'https://api.x.ai/v1/chat/completions',
        ], $fixed);
    }

    /** T8 — the local hosts are the install-configured Chat Completions endpoints */
    public function testLocalChatCompletionsHostsEqualTheLocalDescriptors(): void
    {
        $upstreams = self::getContainer()->get(ChatCompletionsUpstreams::class);
        \assert($upstreams instanceof ChatCompletionsUpstreams);
        $resolver = self::getContainer()->get(UserProviderKeyResolver::class);
        \assert($resolver instanceof UserProviderKeyResolver);
        $local = self::names(array_filter($this->catalog->all(), static fn (ProviderDescriptor $d): bool => $d->isLocal()));
        self::assertSameSet(['ollama', 'openaicompatible'], $local);
        foreach ($this->catalog->all() as $descriptor) {
            self::assertSame($descriptor->isLocal(), $upstreams->isLocal($descriptor->name), $descriptor->name);
            self::assertSame(!$descriptor->isLocal(), $upstreams->needsProviderKey($descriptor->name), $descriptor->name.' needs a key');
            self::assertSame($descriptor->isLocal(), $resolver->isLocalHost($descriptor->name), $descriptor->name.' resolver');
            self::assertSame(null !== $descriptor->chatCompletionsEndpoint(), $upstreams->supports($descriptor->name), $descriptor->name.' supports');
        }
        self::assertFalse($upstreams->supports('anthropic'), 'Claude Code stays on the passthrough');
        self::assertFalse($upstreams->supports('google'), 'Gemini keeps its own translator');
    }

    /** T9 — the structured-output capability answers from the descriptor dialects */
    public function testStructuredOutputDialectsEqualTheDescriptorDialects(): void
    {
        $capability = new StructuredOutputCapability($this->catalog);
        foreach ($this->catalog->all() as $descriptor) {
            $dialects = $descriptor->dialects;
            self::assertSame($dialects->structuredOutput, $capability->dialect($descriptor->name), $descriptor->name.' dialect');
            self::assertSame(null !== $dialects->structuredOutput, $capability->supports($descriptor->name, null, false), $descriptor->name.' buffered schema support');
            self::assertSame(null !== $dialects->structuredOutput && $dialects->schemaWithStreaming, $capability->supports($descriptor->name, null, true), $descriptor->name.' streaming schema support');
        }
        self::assertSame(StructuredOutputDialect::OPENAI_JSON_SCHEMA, $capability->dialect('groq'));
        self::assertSame(StructuredOutputDialect::OPENAI_RESPONSES_TEXT_FORMAT, $capability->dialect('openai'));
        self::assertSame(StructuredOutputDialect::GOOGLE_RESPONSE_JSON_SCHEMA, $capability->dialect('google'));
        self::assertSame(StructuredOutputDialect::OLLAMA_FORMAT, $capability->dialect('ollama'));
        self::assertSame(StructuredOutputDialect::ANTHROPIC_TOOL_FORCING, $capability->dialect('anthropic'));
        self::assertNull($capability->dialect('triton'));
        self::assertNull($capability->dialect('unknown-provider'));
        self::assertFalse($capability->supports('groq', null, true), 'Groq 400s on a schema while streaming');
        self::assertTrue($capability->supports('mistral', null, true));
    }

    /** T10 — the model-level rules are the descriptor lists */
    public function testModelLevelStructuredOutputRulesEqualTheDescriptorLists(): void
    {
        $capability = new StructuredOutputCapability($this->catalog);
        $unavailable = [];
        $strict = [];
        foreach ($this->catalog->all() as $descriptor) {
            foreach ($descriptor->dialects->structuredOutputUnavailableModelPrefixes as $prefix) {
                $unavailable[] = $prefix;
                self::assertFalse($capability->supports($descriptor->name, $prefix.'-20260101', false), "{$descriptor->name} {$prefix}");
            }
            foreach ($descriptor->dialects->strictSchemaModels as $model) {
                $strict[] = $descriptor->name.':'.$model;
                self::assertTrue($capability->supportsStrict($descriptor->name, strtoupper($model)), "{$descriptor->name} {$model} strict, any case");
            }
        }
        self::assertSameSet(['claude-fable-5-1', 'claude-mythos-5-1', 'claude-opus-5-5', 'claude-sonnet-5-5'], $unavailable);
        self::assertSameSet(['groq:openai/gpt-oss-120b', 'groq:openai/gpt-oss-20b'], $strict);
        self::assertTrue($capability->supports('anthropic', 'claude-sonnet-5', false), 'other Claude generations keep the forced tool call');
        self::assertFalse($capability->supportsStrict('groq', 'qwen/qwen3.6-27b'));
        self::assertFalse($capability->supportsStrict('openai', 'gpt-5.5'));
    }

    /** T11 — the tool-calling capability answers from the descriptor dialects */
    public function testToolCallingRulesEqualTheDescriptorDialects(): void
    {
        $capability = new ToolCallingCapability($this->catalog);
        foreach ($this->catalog->all() as $descriptor) {
            self::assertSame($descriptor->dialects->nativeToolRouting, $capability->supports($descriptor->name, null, true), $descriptor->name.' native routing');
            self::assertSame(!$descriptor->dialects->toolsWithStructuredOutput, $capability->conflictsWithStructuredOutput($descriptor->name), $descriptor->name.' tools with schema');
        }
        self::assertSameSet(['groq', 'anthropic'], self::names(array_filter($this->catalog->all(), static fn (ProviderDescriptor $d): bool => $d->dialects->nativeToolRouting)));
        self::assertSameSet(['groq', 'cerebras', 'anthropic'], self::names(array_filter($this->catalog->all(), static fn (ProviderDescriptor $d): bool => !$d->dialects->toolsWithStructuredOutput)));
        self::assertFalse($capability->supports('unknown-provider', null, true));
        self::assertFalse($capability->conflictsWithStructuredOutput('unknown-provider'));
    }

    /** T12 — the catalog tool-use rule is the descriptor flag */
    public function testCatalogToolUseServicesEqualTheDescriptorFlag(): void
    {
        foreach ($this->catalog->all() as $descriptor) {
            self::assertSame($descriptor->dialects->catalogToolUse, CatalogToolUse::isCapableChatService($descriptor->name), $descriptor->name);
        }
        $flagged = self::names(array_filter($this->catalog->all(), static fn (ProviderDescriptor $d): bool => $d->dialects->catalogToolUse));
        self::assertSameSet(['openai', 'groq', 'anthropic', 'google', 'xai', 'mistral', 'huggingface', 'trustedtokens', 'a2agent', 'meta', 'cerebras'], $flagged);
        self::assertTrue(CatalogToolUse::isCapableChatService('Hugging Face'), 'service aliases normalise');
        self::assertFalse(CatalogToolUse::isCapableChatService('ollama'), 'opt-in per pulled model');
    }

    /** T13 — the OpenAI translator transcodes images for providers with the PNG/JPEG rule */
    public function testInlinePngJpegOnlyUpstreamEqualsTheDescriptorImageRule(): void
    {
        $pngJpegOnly = self::names(array_filter($this->catalog->all(), static fn (ProviderDescriptor $d): bool => InlineImageRule::PngJpegOnly === $d->dialects->inlineImages));
        self::assertSame(['cerebras'], $pngJpegOnly);

        $translator = self::getContainer()->get(OpenAiMessagesTranslator::class);
        \assert($translator instanceof OpenAiMessagesTranslator);
        $link = ['type' => 'image', 'source' => ['type' => 'url', 'url' => 'https://example.test/picture.webp']];
        $body = ['model' => 'x', 'max_tokens' => 8, 'messages' => [['role' => 'user', 'content' => [$link, ['type' => 'text', 'text' => 'describe']]]]];
        $method = new \ReflectionMethod($translator, 'resolveUpstream');

        $cerebras = $method->invoke($translator, $body, ['provider' => 'cerebras'], false);
        self::assertIsArray($cerebras);
        self::assertStringContainsString('Cerebras', (string) $cerebras['input_error'], 'a link is refused for the PNG/JPEG-only upstream');

        $groq = $method->invoke($translator, $body, ['provider' => 'groq'], false);
        self::assertIsArray($groq);
        self::assertNull($groq['input_error'], 'other upstreams take the link as is');
    }

    /** T14 — the gateway's rewrite hosts are the descriptors with the flag (the `gemini` alias matched no row and is gone) */
    public function testVisionRewriteProvidersEqualTheDescriptorFlag(): void
    {
        $flagged = self::names(array_filter($this->catalog->all(), static fn (ProviderDescriptor $d): bool => $d->dialects->gatewayVisionRewrite));
        self::assertSameSet(['anthropic', 'openai', 'google'], $flagged);
        // The behaviour itself is MessagesGatewayVisionTest::testAutoFunnelsImagesUpstreamWhenTheVisionModelIsNotARewriteHost.
    }

    /** T15 — the level catalog applies the descriptor ladders */
    public function testReasoningLaddersEqualTheReasoningLevelCatalog(): void
    {
        foreach ($this->catalog->all() as $descriptor) {
            $ladder = $descriptor->dialects->reasoning;
            foreach (['zzz-no-such-model', 'gpt-4o', 'claude-3-haiku'] as $unmatched) {
                self::assertSame($ladder?->levelsFor($unmatched), ReasoningLevelCatalog::levels($descriptor->name, $unmatched, ['reasoning']), "{$descriptor->name} {$unmatched}");
            }
            self::assertNull(ReasoningLevelCatalog::levels($descriptor->name, 'any-model', []), $descriptor->name.' without the reasoning feature');
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
                    self::assertSame($ladder->levelsFor($id), ReasoningLevelCatalog::levels($descriptor->name, $id, ['reasoning']), "{$descriptor->name} {$id}");
                }
            }
        }

        // An inventory of today's model families, independent of the descriptors:
        // a ladder or a pattern dropped from a descriptor fails here.
        foreach (self::REASONING_FAMILIES as [$service, $id, $levels]) {
            self::assertSame($levels, ReasoningLevelCatalog::levels($service, $id, ['reasoning']), "today: {$service} {$id}");
            self::assertSame($levels, $this->catalog->get($service)->dialects->reasoning?->levelsFor($id), "descriptor: {$service} {$id}");
        }
        self::assertSame(['low', 'high', 'max'], ReasoningLevelCatalog::levels('Hugging Face', 'moonshotai/Kimi-K3', ['reasoning']), 'service aliases normalise');
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

    /** T16 — the model-list probe covers every keyed provider whose endpoint says something */
    public function testModelListProbeSupportEqualsTheDescriptorModelListing(): void
    {
        $probe = self::getContainer()->get(PlatformKeyModelListProbe::class);
        \assert($probe instanceof PlatformKeyModelListProbe);
        foreach ($this->catalog->all() as $descriptor) {
            self::assertSame($descriptor->hasPlatformKey() && ModelListing::None !== $descriptor->modelListing(), $probe->supports($descriptor->name), $descriptor->name);
        }
        $credentialsOnly = self::names(array_filter($this->catalog->all(), static fn (ProviderDescriptor $d): bool => ModelListing::CredentialsOnly === $d->modelListing()));
        self::assertSame(['huggingface'], $credentialsOnly);
        self::assertSame(ModelProbeResult::Inconclusive, $probe->confirm('huggingface', 'moonshotai/Kimi-K3'), 'whoami names no model');
        self::assertFalse($probe->supports('higgsfield'));
        self::assertFalse($probe->supports('thehive'));
        self::assertFalse($probe->supports('ollama'));
    }

    /** T17 — the price sync reads each descriptor's pricing (prefix order matters: the first existing key wins) */
    public function testPricePrefixesVendorsAndSkipListEqualTheDescriptorPricing(): void
    {
        $skipped = [];
        $prefixes = [];
        $vendors = [];
        foreach ($this->catalog->all() as $descriptor) {
            if (!$descriptor->pricing->synced) {
                $skipped[] = $descriptor->name;
            }
            if ([] !== $descriptor->pricing->litellmPrefixes) {
                $prefixes[$descriptor->name] = $descriptor->pricing->litellmPrefixes;
            }
            if ([] !== $descriptor->pricing->bareMatchVendors) {
                $vendors[$descriptor->name] = $descriptor->pricing->bareMatchVendors;
            }
            self::assertSame([$descriptor->name], \App\AI\Catalog\ProviderPricing::byServiceName()->prefixesFor($descriptor->name));
        }
        self::assertSameSet(['ollama', 'triton', 'test', 'piper', 'thehive'], $skipped);
        self::assertSame(['anthropic' => ['anthropic'], 'google' => ['gemini', 'google'], 'groq' => ['groq'], 'huggingface' => ['huggingface'], 'openai' => ['openai']], self::sorted($prefixes));
        self::assertSame(['anthropic' => ['anthropic'], 'google' => ['gemini', 'google', 'vertex_ai'], 'openai' => ['openai']], self::sorted($vendors));

        $command = self::getContainer()->get(SyncModelPricesCommand::class);
        \assert($command instanceof SyncModelPricesCommand);
        $find = new \ReflectionMethod($command, 'findLiteLLMKey');
        $litellm = ['gemini/gemini-3.8-flash' => [], 'google/gemini-3.8-flash' => [], 'deepseek-v4-pro' => ['litellm_provider' => 'deepseek'], 'gpt-5.5' => ['litellm_provider' => 'openai'], 'xai/grok-4.5' => []];
        self::assertSame('gemini/gemini-3.8-flash', $find->invoke($command, self::model('google', 'gemini-3.8-flash'), $litellm), 'gemini before google');
        self::assertSame('gpt-5.5', $find->invoke($command, self::model('openai', 'gpt-5.5'), $litellm), 'a bare id of our own vendor');
        self::assertNull($find->invoke($command, self::model('a2agent', 'deepseek-v4-pro'), $litellm), 'a gateway never matches a bare id');
        self::assertSame('xai/grok-4.5', $find->invoke($command, self::model('xAI', 'grok-4.5'), $litellm), 'the service name is the default prefix');
    }

    /** T18 — live-voice caps are the descriptors' sessions; `compatible` is the endpoint registry's socket */
    public function testLiveVoiceCapsEqualTheDescriptorSessions(): void
    {
        $expected = [];
        foreach ($this->catalog->all() as $descriptor) {
            if (null !== $descriptor->liveVoiceSessions) {
                $expected[$descriptor->name] = $descriptor->liveVoiceSessions;
            }
        }
        self::assertSame(['google' => 100, 'openai' => 50, 'openaicompatible' => 4, 'xai' => 10], self::sorted($expected));

        $concurrency = self::getContainer()->get(VoiceLiveConcurrency::class);
        \assert($concurrency instanceof VoiceLiveConcurrency);
        $cap = new \ReflectionMethod($concurrency, 'cap');
        self::assertSame(50, $cap->invoke($concurrency, 'OpenAI'));
        self::assertSame(100, $cap->invoke($concurrency, 'google'));
        self::assertSame(10, $cap->invoke($concurrency, 'xai'));
        self::assertSame(4, $cap->invoke($concurrency, 'compatible'));
        self::assertSame(10, $cap->invoke($concurrency, 'unknown'), 'the fallback');
    }

    /** T19 — the admin route validates against the catalog; the spec names no provider list (ProviderCatalogControllerTest) */
    public function testAdminProviderKeysPathHasNoEnumAndValidatesAgainstTheCatalog(): void
    {
        foreach ((new \ReflectionMethod(AdminProviderKeysController::class, 'save'))->getAttributes(OA\Parameter::class) as $attribute) {
            $parameter = $attribute->newInstance();
            if ('provider' === $parameter->name && $parameter->schema instanceof OA\Schema) {
                self::assertSame(\OpenApi\Generator::UNDEFINED, $parameter->schema->enum, 'no hand-kept provider list on the save route');
            }
        }
        $keyCatalog = new ProviderKeyCatalog($this->catalog);
        self::assertTrue($keyCatalog->has('groq'));
        self::assertFalse($keyCatalog->has('acme'), 'an unknown name is refused by the controller through the key catalog');
    }

    /** T20 — the AI Services tab's key fields are the descriptors' placements and texts */
    public function testSystemConfigPasswordFieldsEqualTheKeySpecs(): void
    {
        $tab = self::getContainer()->get(AiTab::class);
        \assert($tab instanceof AiTab);

        $fields = [];
        foreach ($tab->sections() as $sectionId => $section) {
            foreach ($section->fields as $envVar => $field) {
                if ('password' === $field->type) {
                    $fields[$envVar] = [$sectionId, $field->description];
                }
            }
        }

        $expected = [];
        foreach ($this->catalog->withKey() as $descriptor) {
            $key = $descriptor->key;
            self::assertNotNull($key);
            $section = $key->presentation->configSection;
            if (null === $section) {
                self::assertArrayNotHasKey((string) $key->envVar, $fields, "{$descriptor->name} has no system-config field");
                continue;
            }
            $expected[(string) $key->envVar] = [$section, $key->presentation->fieldDescription];
            if (null !== $key->secretEnvVar) {
                $expected[$key->secretEnvVar] = [$section, $key->presentation->secretFieldDescription];
            }
        }
        foreach ($expected as $envVar => $placement) {
            self::assertSame($placement, $fields[$envVar] ?? null, $envVar);
        }
        self::assertCount(15, $expected, 'fourteen key fields and one secret field are managed');
        self::assertSame(
            ['OPENAI_API_KEY', 'ANTHROPIC_API_KEY', 'GROQ_API_KEY', 'GOOGLE_GEMINI_API_KEY', 'MISTRAL_API_KEY', 'XAI_API_KEY', 'META_API_KEY', 'CEREBRAS_API_KEY', 'TRUSTEDTOKENS_API_KEY', 'A2AGENT_API_KEY', 'HUGGINGFACE_API_KEY', 'GOOGLE_VERTEX_ACCESS_TOKEN'],
            array_keys($tab->sections()['cloud']->fields),
            'the shipped order of the cloud section',
        );
        self::assertSame(['THEHIVE_API_KEY', 'HIGGSFIELD_API_KEY', 'HIGGSFIELD_API_SECRET'], array_keys($tab->sections()['media']->fields));
        self::assertSame(['SYNAPLAN_TTS_URL', 'ELEVENLABS_API_KEY'], array_keys($tab->sections()['tts']->fields));
        self::assertNull($this->catalog->get('perplexity')->key?->presentation->configSection, 'Perplexity has no system-config field today');
        self::assertSame('Cloudflare API token with Workers AI access', $fields['CLOUDFLARE_API_TOKEN'][1], 'env-only token stays a hand-written field');
    }

    /** T21 — the registry's tags are the descriptors' capabilities, added by the pass; services.yaml names none */
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
        self::assertSame(['a2agent', 'anthropic', 'cerebras', 'google', 'groq', 'huggingface', 'meta', 'mistral', 'ollama', 'openaicompatible', 'openai', 'perplexity', 'test', 'triton', 'trustedtokens', 'xai'], $this->registry->getRegisteredProviderNames('chat'), 'the order the registry lists');

        $services = file_get_contents(\dirname(__DIR__, 3).'/config/services.yaml');
        self::assertIsString($services);
        self::assertDoesNotMatchRegularExpression('/name: \'app\.ai\.(chat|embedding|vision|image_generation|video_generation|speech_to_text|text_to_speech|file_analysis)\'/', $services, 'no capability tag is hand-kept');
    }

    /** T22 */
    public function testProviderExceptionSpellingsEqualTheDisplayNames(): void
    {
        foreach (self::constant(ProviderException::class, 'DISPLAY_NAMES') as $name => $spelling) {
            self::assertSame($this->catalog->get($name)->displayName, $spelling, $name);
            self::assertSame($spelling, ProviderException::displayName($name));
        }
    }

    /** T23 — the allowlist is the internal names, the catalog's hosts and the integrations; nothing of AU15's constant is lost */
    public function testEgressAllowlistIsBuiltFromTheCatalogAndKeepsEveryStaticEntry(): void
    {
        $au15Providers = [
            'api.openai.com', 'api.anthropic.com', 'generativelanguage.googleapis.com', 'api.groq.com',
            'api.mistral.ai', 'api.x.ai', 'router.huggingface.co', 'api.trustedtokens.eu', 'a2agent.me',
            'api.meta.ai', 'api.cerebras.ai', 'api.perplexity.ai',
        ];
        $au15Integrations = [
            'api.stripe.com', 'api.telegram.org', 'graph.facebook.com', 'api.search.brave.com',
            'accounts.google.com', 'androidpublisher.googleapis.com', 'oauth2.googleapis.com', 'www.googleapis.com',
            'github.com', 'api.github.com', 'appleid.apple.com', 'www.google.com', 'www.recaptcha.net', 'challenges.cloudflare.com',
        ];
        $policy = self::getContainer()->get(EgressPolicy::class);
        \assert($policy instanceof EgressPolicy);
        $published = $policy->publishedRules();

        self::assertSame($au15Integrations, EgressBuiltinDestinations::INTEGRATIONS, 'the integration half is the hand-kept list');
        foreach ([...$au15Providers, ...$au15Integrations, ...EgressBuiltinDestinations::INTERNAL] as $entry) {
            self::assertContains($entry, $published, $entry.' stays allowed');
        }
        foreach ($au15Providers as $host) {
            self::assertContains($host, $this->catalog->endpoints(), $host.' is a described endpoint');
        }
        // The hosts the static list lacked (`03` §2.3.4 #10) are allowed now.
        foreach (['huggingface.co', 'platform.higgsfield.ai', 'api.thehive.ai', 'api.elevenlabs.io', 'api.cloudflare.com', 'us-central1-aiplatform.googleapis.com'] as $host) {
            self::assertContains($host, $published, $host.' comes from a descriptor');
            self::assertTrue($policy->decide('https://'.$host.'/')->allowed, $host);
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

    /**
     * @template T
     *
     * @param array<string, T> $map
     *
     * @return array<string, T>
     */
    private static function sorted(array $map): array
    {
        ksort($map);

        return $map;
    }

    private static function model(string $service, string $providerId): Model
    {
        $model = new Model();
        $model->setService($service);
        $model->setProviderId($providerId);

        return $model;
    }
}
