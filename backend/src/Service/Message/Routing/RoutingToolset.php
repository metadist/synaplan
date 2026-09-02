<?php

declare(strict_types=1);

namespace App\Service\Message\Routing;

use App\AI\ToolCalling\ToolCall;
use App\AI\ToolCalling\ToolDefinition;
use App\Service\Message\Capability\SystemCapabilityRegistry;

/**
 * Derives the native tool declarations for the routing hand-off from
 * {@see SystemCapabilityRegistry} — the Phase 7 register is the single source
 * of truth, so a new system capability becomes routable by declaring it there
 * and nowhere else.
 *
 * The modelled decision is deliberately asymmetric, and that asymmetry IS the
 * point of the native tool-calling path:
 *
 *  - `general` gets NO tool. "The model called nothing" means "this is an
 *    ordinary chat turn" — and since the call that offered the tools is the
 *    answering call itself, that turn costs exactly one request. This is what
 *    removes the AI-sorter round-trip from the hot path.
 *  - Every other system capability gets one hand-off tool. Those need a
 *    DIFFERENT model or backend (image/video/audio generation, office
 *    document generation, document summarisation), so the answer text of the
 *    current call is discarded and the pipeline re-routes.
 *
 * Tool names are `handoff_<topic>`: derived, unique (capability intents are
 * not — `docsummary` and `general` share `chat`), and stable enough to be
 * matched back to a topic by {@see self::topicForToolCall()}.
 */
final readonly class RoutingToolset
{
    private const NAME_PREFIX = 'handoff_';

    /**
     * The one capability that is expressed by the ABSENCE of a tool call.
     */
    private const IMPLICIT_TOPIC = 'general';

    public function __construct(
        private SystemCapabilityRegistry $capabilityRegistry,
    ) {
    }

    /**
     * @return list<ToolDefinition>
     */
    public function build(): array
    {
        $tools = [];

        foreach ($this->capabilityRegistry->all() as $capability) {
            if (self::IMPLICIT_TOPIC === $capability->topic) {
                continue;
            }

            $tools[] = new ToolDefinition(
                name: self::NAME_PREFIX.$capability->topic,
                description: $this->describe($capability->description, $capability->exampleUtterances),
                parameters: $this->parametersFor($capability->parameterSchema),
            );
        }

        return $tools;
    }

    /**
     * The topic a tool call hands off to, or null when the model invented a
     * tool name that is not in the register.
     */
    public function topicForToolCall(ToolCall $call): ?string
    {
        if (!str_starts_with($call->name, self::NAME_PREFIX)) {
            return null;
        }

        $topic = substr($call->name, strlen(self::NAME_PREFIX));

        if (self::IMPLICIT_TOPIC === $topic || null === $this->capabilityRegistry->byTopic($topic)) {
            return null;
        }

        return $topic;
    }

    /**
     * The tool call's arguments, reduced to the values the register actually
     * declares for that topic.
     *
     * Enum membership is re-checked here rather than trusted: `tool_choice`
     * is `auto`, not a strict-mode schema, so nothing on the wire guarantees
     * the model stayed inside the declared values. An out-of-enum value is
     * dropped, not corrected — a missing `media_type` is re-derived
     * downstream by MediaPromptExtractor, whereas a wrong one would silently
     * generate the wrong kind of media.
     *
     * @return array<string, string>
     */
    public function classificationFieldsFor(string $topic, ToolCall $call): array
    {
        $capability = $this->capabilityRegistry->byTopic($topic);
        $declared = $capability?->parameterSchema;
        if (null === $declared) {
            return [];
        }

        $fields = [];
        foreach ($declared as $field => $allowedValues) {
            $value = $call->arguments[$field] ?? null;
            if (is_string($value) && in_array($value, $allowedValues, true)) {
                $fields[$field] = $value;
            }
        }

        return $fields;
    }

    /**
     * @param list<string> $exampleUtterances
     */
    private function describe(string $description, array $exampleUtterances): string
    {
        if ([] === $exampleUtterances) {
            return $description;
        }

        // The examples are already curated per capability for the Phase 8
        // embedding anchors; reusing them keeps the two routing layers
        // describing the same capability with the same words.
        return $description.' Examples: "'.implode('", "', $exampleUtterances).'".';
    }

    /**
     * @param array<string, list<string>>|null $parameterSchema
     *
     * @return array<string, mixed>
     */
    private function parametersFor(?array $parameterSchema): array
    {
        if (null === $parameterSchema || [] === $parameterSchema) {
            return ToolDefinition::noParameters();
        }

        $properties = [];
        foreach ($parameterSchema as $field => $allowedValues) {
            $properties[$field] = [
                'type' => 'string',
                'enum' => $allowedValues,
            ];
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            // Nothing is required: a parameter that does not apply (a
            // resolution for an audio clip) is better left out than filled
            // with a plausible-looking value the pipeline would then act on.
            'required' => [],
        ];
    }
}
