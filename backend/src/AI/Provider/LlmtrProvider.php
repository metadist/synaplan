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
 * LLMTR — OpenAI-compatible gateway at https://llmtr.com/v1.
 *
 * Models under `llmtr/` run on servers in Turkey. The same key also reaches
 * global providers; the catalog rows here are the Turkey-hosted ones.
 * Gemma 4 reads images only as base64 data URLs (local uploads already
 * are). Trendyol Asure does not call tools, so tool use stays on the row.
 *
 * Prices from GET /v1/models on 2026-11-21.
 *
 * @see https://llmtr.com/docs/en/
 * @see https://llmtr.com/docs/en/gateway/llmtr-gemma/
 */
#[AsAiProvider]
class LlmtrProvider extends AbstractChatCompletionsCloudProvider
{
    private const PROVIDER_NAME = 'llmtr';
    private const BASE_URI = 'https://llmtr.com/v1';
    private const DEFAULT_CHAT_MODEL = 'llmtr/gemma-4';
    private const DEFAULT_TOOLS_MODEL = 'llmtr/qwen3-8-27b';

    public static function descriptor(): ProviderDescriptor
    {
        return new ProviderDescriptor(
            name: self::PROVIDER_NAME,
            displayName: 'LLMTR',
            capabilities: [ProviderCapability::Chat, ProviderCapability::Vision],
            endpoints: [ProviderEndpoint::chatCompletions(self::BASE_URI.'/chat/completions')],
            key: ProviderKeySpec::platform(
                envVar: 'LLMTR_API_KEY',
                consoleUrl: 'https://llmtr.com/',
                validation: ProviderKeyValidation::bearer(self::BASE_URI.'/models'),
                listOrder: 17,
                modelListing: ModelListing::Lists,
                fieldDescription: 'LLMTR API key — models hosted in Turkey (Gemma 4, Qwen 3.8 27B, Trendyol Asure, Muse Glimmer). Create it at https://llmtr.com/',
                configSection: ProviderKeyPresentation::SECTION_CLOUD,
                configOrder: 13,
            ),
            dialects: new ProviderDialects(
                structuredOutput: StructuredOutputDialect::OPENAI_JSON_SCHEMA,
                // Trendyol Asure 12B does not accept tools. Tool use is the
                // row's `tool_use` feature; Qwen 3.8 27B is the schema default.
                catalogToolUse: false,
            ),
            defaults: new ProviderDefaults(bindings: [
                'CHAT' => self::PROVIDER_NAME.':'.self::DEFAULT_CHAT_MODEL.':chat',
                'PIC2TEXT' => self::PROVIDER_NAME.':'.self::DEFAULT_CHAT_MODEL.':pic2text',
                'TOOLS' => self::PROVIDER_NAME.':'.self::DEFAULT_TOOLS_MODEL.':chat',
                'ANALYZE' => self::PROVIDER_NAME.':'.self::DEFAULT_TOOLS_MODEL.':chat',
                'SORT' => self::PROVIDER_NAME.':'.self::DEFAULT_TOOLS_MODEL.':chat',
                'SUMMARIZE' => self::PROVIDER_NAME.':'.self::DEFAULT_TOOLS_MODEL.':chat',
            ], preferenceRank: 14),
            pricing: ProviderPricing::byServiceName(),
            liveVoiceSessions: null,
            branding: new ProviderBranding(icon: 'mdi:robot-outline', flag: 'circle-flags:tr', helpKey: self::PROVIDER_NAME),
        );
    }

    public function getName(): string
    {
        return self::PROVIDER_NAME;
    }

    public function getDisplayName(): string
    {
        return 'LLMTR';
    }

    public function getDescription(): string
    {
        return 'LLMTR — OpenAI-compatible models hosted in Turkey. The same key also reaches global providers.';
    }

    public function getCapabilities(): array
    {
        return ['chat', 'vision'];
    }

    public function getDefaultModels(): array
    {
        return [
            'chat' => self::DEFAULT_CHAT_MODEL,
            'vision' => self::DEFAULT_CHAT_MODEL,
        ];
    }

    protected function baseUri(): string
    {
        return self::BASE_URI;
    }

    protected function envVarName(): string
    {
        return 'LLMTR_API_KEY';
    }

    protected function envVarHint(): string
    {
        return 'Create an API key at https://llmtr.com/, then paste it under Admin → AI infrastructure → Providers & keys.';
    }
}
