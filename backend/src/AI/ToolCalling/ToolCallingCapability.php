<?php

declare(strict_types=1);

namespace App\AI\ToolCalling;

/**
 * Answers "can this provider + model + streaming-mode take a native tool
 * declaration and give the calls back?" — the single place the hard,
 * provider-specific rules live, mirroring
 * {@see \App\AI\StructuredOutput\StructuredOutputCapability}.
 *
 * The allow-list is deliberately NARROW: a provider only appears here once
 * both directions are implemented AND covered by tests — the request side in
 * {@see ToolCallingTranslator} and the response side in
 * {@see ToolCallParser}, for streaming as well as non-streaming where the
 * provider is listed as stream-capable.
 *
 * That narrowness is a feature, not a gap. The only consumer today is the
 * native tool-calling routing path, whose contract is that an unsupported
 * provider silently keeps the AI-sorter round-trip
 * ({@see \App\Service\Message\MessageClassifier}). A provider missing here
 * therefore behaves exactly as it does today; a provider wrongly listed here
 * would break the hottest path in the product.
 */
final class ToolCallingCapability
{
    /**
     * Providers speaking the OpenAI Chat Completions `tools`/`tool_calls`
     * dialect through the shared openai-php client.
     *
     * Only `groq` is listed even though Mistral, xAI, TrustedTokens and
     * OpenAICompatible use the same SDK: each of those builds its own request
     * payload and parses its own response, so listing them here without that
     * wiring would silently drop the tool declaration and make every routing
     * turn look like "the model chose to answer directly".
     */
    private const OPENAI_FUNCTIONS_PROVIDERS = ['groq'];

    private const ANTHROPIC_PROVIDERS = ['anthropic'];

    /**
     * Providers whose STREAMING path also parses tool calls back out.
     *
     * Fails closed on purpose: a provider added to a dialect list above but
     * not here keeps tools for non-streaming calls only, instead of silently
     * swallowing a streamed tool call and answering with an empty message.
     */
    private const STREAMING_CAPABLE_PROVIDERS = ['groq', 'anthropic'];

    /**
     * Providers that reject a request carrying BOTH a tool declaration and a
     * JSON-schema response format.
     *
     * Groq documents this combination as unsupported and 400s on it. No
     * caller sends both today — the officemaker path uses a schema and never
     * declares tools, the routing path declares tools and never a schema —
     * but the rule belongs next to the other provider quirks rather than in a
     * caller's head.
     */
    private const NO_TOOLS_WITH_STRUCTURED_OUTPUT = ['groq'];

    public function supports(string $providerName, ?string $model, bool $streaming): bool
    {
        $provider = strtolower($providerName);

        if (null === $this->dialect($provider)) {
            return false;
        }

        return !$streaming || in_array($provider, self::STREAMING_CAPABLE_PROVIDERS, true);
    }

    public function conflictsWithStructuredOutput(string $providerName): bool
    {
        return in_array(strtolower($providerName), self::NO_TOOLS_WITH_STRUCTURED_OUTPUT, true);
    }

    public function dialect(string $providerName): ?ToolCallingDialect
    {
        $provider = strtolower($providerName);

        return match (true) {
            in_array($provider, self::OPENAI_FUNCTIONS_PROVIDERS, true) => ToolCallingDialect::OPENAI_FUNCTIONS,
            in_array($provider, self::ANTHROPIC_PROVIDERS, true) => ToolCallingDialect::ANTHROPIC_TOOLS,
            default => null,
        };
    }
}
