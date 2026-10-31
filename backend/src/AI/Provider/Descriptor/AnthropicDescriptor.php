<?php

declare(strict_types=1);

namespace App\AI\Provider\Descriptor;

use App\AI\Catalog\Attribute\AsAiProvider;
use App\AI\Catalog\DescribesProviderInterface;
use App\AI\Catalog\ModelListing;
use App\AI\Catalog\ProviderBranding;
use App\AI\Catalog\ProviderCapability;
use App\AI\Catalog\ProviderDefaults;
use App\AI\Catalog\ProviderDescriptor;
use App\AI\Catalog\ProviderDialects;
use App\AI\Catalog\ProviderEndpoint;
use App\AI\Catalog\ProviderKeyPresentation;
use App\AI\Catalog\ProviderKeySpec;
use App\AI\Catalog\ProviderKeyValidation;
use App\AI\Catalog\ProviderPricing;
use App\AI\Catalog\ReasoningLadder;
use App\AI\Catalog\ReasoningLevelRule;
use App\AI\Catalog\ReasoningMatch;
use App\AI\Provider\AnthropicProvider;
use App\AI\StructuredOutput\StructuredOutputDialect;

/**
 * The descriptor of {@see AnthropicProvider}. It lives next to the adapter,
 * not inside it, because the adapter is frozen in the size ledger
 * (`03_contracts.md` §2.3.3); the request-shape constants it quotes stay on
 * the adapter.
 */
#[AsAiProvider(adapter: AnthropicProvider::class)]
final class AnthropicDescriptor implements DescribesProviderInterface
{
    /**
     * Models that reject a forced `tool_choice`, which the structured-output
     * dialect needs; they fall back to the prose-instruction path. Matched by
     * prefix because Anthropic ships dated aliases of the same model.
     *
     * @var list<string>
     */
    private const NO_FORCED_TOOL_CHOICE_MODELS = [
        'claude-fable-5-1',
        'claude-mythos-5-1',
        'claude-opus-5-5',
        'claude-sonnet-5-5',
    ];

    /**
     * Claude Sonnet 5.5 accepts xhigh and max in addition to low|medium|high.
     * Checked before the adaptive models: `claude-sonnet-5` is a prefix of
     * `claude-sonnet-5-5`.
     *
     * @var list<string>
     */
    private const EXTENDED_EFFORT_MODELS = ['claude-sonnet-5-5'];

    public static function descriptor(): ProviderDescriptor
    {
        // Adaptive models take output_config.effort low|medium|high; Sonnet 5.5
        // extends that to xhigh and max. Budget-token models keep the toggle.
        $reasoning = new ReasoningLadder([
            new ReasoningLevelRule(ReasoningMatch::Prefix, self::EXTENDED_EFFORT_MODELS, ['low', 'medium', 'high', 'xhigh', 'max']),
            new ReasoningLevelRule(ReasoningMatch::Prefix, AnthropicProvider::ADAPTIVE_THINKING_MODELS, ['low', 'medium', 'high']),
        ]);

        return new ProviderDescriptor(
            name: 'anthropic',
            displayName: 'Anthropic',
            capabilities: [ProviderCapability::Chat, ProviderCapability::Vision],
            endpoints: [ProviderEndpoint::api(AnthropicProvider::BASE_URL)],
            key: ProviderKeySpec::platformAndUser(
                envVar: 'ANTHROPIC_API_KEY',
                consoleUrl: 'https://console.anthropic.com/settings/keys',
                validation: ProviderKeyValidation::header(AnthropicProvider::BASE_URL.'/models', 'x-api-key', '{key}', ['anthropic-version' => AnthropicProvider::API_VERSION]),
                listOrder: 3,
                modelListing: ModelListing::Lists,
                fieldDescription: 'Anthropic (Claude) API key',
                configSection: ProviderKeyPresentation::SECTION_CLOUD,
                configOrder: 2,
            ),
            dialects: new ProviderDialects(
                structuredOutput: StructuredOutputDialect::ANTHROPIC_TOOL_FORCING,
                structuredOutputUnavailableModelPrefixes: self::NO_FORCED_TOOL_CHOICE_MODELS,
                nativeToolRouting: true,
                // The schema IS a forced tool call: it and the caller's tools write
                // the same `tools` / `tool_choice` keys.
                toolsWithStructuredOutput: false,
                catalogToolUse: true,
                gatewayVisionRewrite: true,
                reasoning: $reasoning,
            ),
            defaults: new ProviderDefaults(bindings: [
                'CHAT' => 'anthropic:claude-sonnet-5:chat',
                'TOOLS' => 'anthropic:claude-sonnet-5:chat',
                'ANALYZE' => 'anthropic:claude-sonnet-5:chat',
                'SORT' => 'anthropic:claude-haiku-4-5-20251001:chat',
                'PLAN' => 'anthropic:claude-haiku-4-5-20251001:chat',
                'SUMMARIZE' => 'anthropic:claude-haiku-4-5-20251001:chat',
                'MEM' => 'anthropic:claude-sonnet-5:mem',
                'PIC2TEXT' => 'anthropic:claude-sonnet-5:pic2text',
            ], preferenceRank: 5),
            pricing: ProviderPricing::litellm(['anthropic'], ['anthropic']),
            liveVoiceSessions: null,
            branding: new ProviderBranding(icon: 'simple-icons:anthropic', flag: 'circle-flags:us', helpKey: 'anthropic'),
        );
    }
}
