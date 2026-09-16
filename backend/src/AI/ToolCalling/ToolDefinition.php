<?php

declare(strict_types=1);

namespace App\AI\ToolCalling;

/**
 * A provider-agnostic description of one tool the model may call.
 *
 * Call-sites build these and hand them to {@see ToolCallingTranslator}, which
 * emits the request options {@see \App\AI\Service\AiFacade::chat()} takes.
 * The indirection is deliberate: a toolset such as
 * {@see \App\Service\Message\Routing\RoutingToolset} describes what the model
 * may do without the OpenAI `function` envelope or Anthropic's `input_schema`
 * naming leaking into it.
 *
 * `$parameters` is plain JSON Schema for the tool's argument object (the same
 * conservative subset {@see \App\AI\StructuredOutput\StructuredOutputSchema}
 * documents). A tool that takes no arguments still needs a valid empty object
 * schema — see {@see self::noParameters()} — because Anthropic and Google both
 * reject a missing/`null` schema.
 */
final readonly class ToolDefinition
{
    /**
     * @param string               $name        machine name the model uses to call the tool; must match
     *                                          `^[a-zA-Z0-9_-]{1,64}$` (the intersection of what OpenAI,
     *                                          Anthropic and Google accept)
     * @param string               $description what the tool does — this is the ONLY signal the model has
     *                                          for when to pick it, so it carries the routing semantics
     * @param array<string, mixed> $parameters  JSON Schema for the argument object
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $parameters,
    ) {
    }

    /**
     * Schema for a tool that takes no arguments.
     *
     * Not simply `[]`: an empty PHP array encodes to a JSON array, not an
     * object, and providers reject `"parameters": []`. Callers that build
     * their schema by hand have the same trap, which is why this lives here.
     *
     * @return array<string, mixed>
     */
    public static function noParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'required' => [],
        ];
    }
}
