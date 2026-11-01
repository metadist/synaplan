<?php

declare(strict_types=1);

namespace App\AI\Catalog;

use App\AI\StructuredOutput\StructuredOutputDialect;

/**
 * The provider-specific request rules that today sit in capability lists
 * all over the AI layer: how a JSON schema is requested, whether tools and
 * a schema may share a request, which image inputs are accepted, which
 * reasoning levels a model takes.
 */
final readonly class ProviderDialects
{
    /**
     * @param ?StructuredOutputDialect $structuredOutput                         null = no schema mode at all
     * @param bool                     $schemaWithStreaming                      false where a schema request fails while streaming
     * @param list<string>             $structuredOutputUnavailableModelPrefixes models that reject the dialect (Anthropic's forced tool_choice)
     * @param list<string>             $strictSchemaModels                       lowercased model ids allowed to request `strict: true`
     * @param bool                     $nativeToolRouting                        the streamed tool calls come back folded, so routing may hand off
     * @param bool                     $toolsWithStructuredOutput                false where tools and a schema cannot travel in one request
     * @param bool                     $catalogToolUse                           every chat row of this service must carry `tool_use`
     * @param bool                     $gatewayVisionRewrite                     the Messages gateway may rewrite an image turn onto this provider
     */
    public function __construct(
        public ?StructuredOutputDialect $structuredOutput = null,
        public bool $schemaWithStreaming = true,
        public array $structuredOutputUnavailableModelPrefixes = [],
        public array $strictSchemaModels = [],
        public bool $nativeToolRouting = false,
        public bool $toolsWithStructuredOutput = true,
        public bool $catalogToolUse = false,
        public InlineImageRule $inlineImages = InlineImageRule::Any,
        public bool $gatewayVisionRewrite = false,
        public ?ReasoningLadder $reasoning = null,
    ) {
    }
}
