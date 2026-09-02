<?php

declare(strict_types=1);

namespace App\AI\ToolCalling;

/**
 * The provider-specific wire format for declaring tools and reading tool
 * calls back.
 *
 * Mirrors {@see \App\AI\StructuredOutput\StructuredOutputDialect}: the enum
 * names the format, {@see ToolCallingCapability} decides which provider speaks
 * which one, {@see ToolCallingTranslator} writes the request side and
 * {@see ToolCallParser} reads the response side.
 */
enum ToolCallingDialect: string
{
    /**
     * OpenAI Chat Completions: `tools[].function.{name,description,parameters}`
     * plus `tool_choice`; calls come back as `message.tool_calls[]` with
     * `function.arguments` as a JSON-encoded STRING.
     */
    case OPENAI_FUNCTIONS = 'openai_functions';

    /**
     * Anthropic Messages: `tools[].{name,description,input_schema}` plus
     * `tool_choice`; calls come back as `content[]` blocks of
     * `type: tool_use` with `input` as a decoded object.
     */
    case ANTHROPIC_TOOLS = 'anthropic_tools';
}
