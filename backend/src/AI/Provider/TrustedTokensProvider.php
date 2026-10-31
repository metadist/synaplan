<?php

declare(strict_types=1);

namespace App\AI\Provider;

use App\AI\Catalog\Attribute\AsAiProvider;
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
use App\AI\StructuredOutput\StructuredOutputDialect;

/**
 * TrustedTokens — sovereign OpenAI-compatible inference hosted in Germany
 * by TNG Technology Consulting (EU data residency).
 *
 * Chat and vision share the same `/v1/chat/completions` endpoint. Vision is
 * only offered by models that advertise it (Qwen3.6 and GLM-5.3-Flash);
 * GLM-5.2, GLM-5.3, Chimera and GPT OSS 120B are text-only.
 *
 * @see https://trustedtokens.eu/docs/
 * @see https://api.trustedtokens.eu/v1
 */
#[AsAiProvider]
class TrustedTokensProvider extends AbstractChatCompletionsCloudProvider
{
    private const PROVIDER_NAME = 'trustedtokens';
    private const BASE_URI = 'https://api.trustedtokens.eu/v1';
    private const DEFAULT_CHAT_MODEL = 'zai-org/GLM-5.2';
    private const DEFAULT_VISION_MODEL = 'Qwen/Qwen3.6-35B-A3B-FP8';

    public static function descriptor(): ProviderDescriptor
    {
        return new ProviderDescriptor(
            name: self::PROVIDER_NAME,
            displayName: 'TrustedTokens',
            capabilities: [ProviderCapability::Chat, ProviderCapability::Vision],
            endpoints: [ProviderEndpoint::chatCompletions(self::BASE_URI.'/chat/completions')],
            key: ProviderKeySpec::platform(
                envVar: 'TRUSTEDTOKENS_API_KEY',
                consoleUrl: 'https://trustedtokens.eu/',
                validation: ProviderKeyValidation::bearer(self::BASE_URI.'/models'),
                listOrder: 6,
                modelListing: ModelListing::Lists,
                fieldDescription: 'TrustedTokens API key — sovereign inference on German GPUs (GLM, DeepSeek, Qwen, GPT OSS)',
                configSection: ProviderKeyPresentation::SECTION_CLOUD,
                configOrder: 9,
            ),
            dialects: new ProviderDialects(
                structuredOutput: StructuredOutputDialect::OPENAI_JSON_SCHEMA,
                catalogToolUse: true,
            ),
            defaults: new ProviderDefaults(bindings: [
                'CHAT' => 'trustedtokens:zai-org/GLM-5.2:chat',
                'TOOLS' => 'trustedtokens:zai-org/GLM-5.2:chat',
                'ANALYZE' => 'trustedtokens:zai-org/GLM-5.2:chat',
                'SORT' => 'trustedtokens:openai/gpt-oss-120b:chat',
                'PLAN' => 'trustedtokens:openai/gpt-oss-120b:chat',
                'SUMMARIZE' => 'trustedtokens:openai/gpt-oss-120b:chat',
                'PIC2TEXT' => 'trustedtokens:Qwen/Qwen3.6-35B-A3B-FP8:pic2text',
            ], preferenceRank: 6),
            pricing: ProviderPricing::byServiceName(),
            liveVoiceSessions: null,
            // No brand glyph in Iconify; a shield reads as "sovereign / secured".
            branding: new ProviderBranding(icon: 'mdi:shield-check', flag: 'circle-flags:de', helpKey: 'trustedtokens'),
        );
    }

    public function getName(): string
    {
        return self::PROVIDER_NAME;
    }

    public function getDisplayName(): string
    {
        return 'TrustedTokens';
    }

    public function getDescription(): string
    {
        return 'Sovereign LLM inference on German infrastructure (TNG Technology Consulting). OpenAI-compatible API.';
    }

    public function getCapabilities(): array
    {
        return ['chat', 'vision'];
    }

    public function getDefaultModels(): array
    {
        return [
            'chat' => self::DEFAULT_CHAT_MODEL,
            'vision' => self::DEFAULT_VISION_MODEL,
        ];
    }

    protected function baseUri(): string
    {
        return self::BASE_URI;
    }

    protected function envVarName(): string
    {
        return 'TRUSTEDTOKENS_API_KEY';
    }

    protected function envVarHint(): string
    {
        return 'Get your API key from https://trustedtokens.eu/ (Account → API Access)';
    }
}
