<?php

declare(strict_types=1);

namespace App\Mcp;

use Mcp\Schema\ToolAnnotations;
use Mcp\Server\Builder;

/**
 * The public MCP tool catalog (names, descriptions, schemas).
 *
 * Shared by the authenticated server and the unauthenticated discovery server
 * so a registry sees the same tool definitions a signed-in client does.
 * Admin-only and desktop check-in tools are not part of this catalog.
 */
final class McpPublicTools
{
    /**
     * @param array<string, \Closure> $handlers
     */
    public function register(Builder $builder, array $handlers, bool $includeAssistants): void
    {
        foreach ($this->definitions($includeAssistants) as $tool) {
            $name = $tool['name'];
            if (!isset($handlers[$name])) {
                throw new \LogicException(sprintf('Missing MCP handler for "%s".', $name));
            }

            $builder->addTool(
                $handlers[$name],
                $name,
                $tool['title'],
                $tool['description'],
                $tool['annotations'],
                $tool['schema'],
            );
        }
    }

    /**
     * @return list<array{name: string, title: string, description: string, annotations: ToolAnnotations, schema: array<string, mixed>}>
     */
    private function definitions(bool $includeAssistants): array
    {
        $tools = [
            $this->tool(
                'synaplan_chat',
                'Synaplan chat',
                'Send a message through Synaplan\'s full AI pipeline — intent classification, '
                .'web search, RAG over the user\'s documents, long-term memories, and inference — '
                .'and return the synthesized answer. This is richer than a raw model call: it uses '
                .'the authenticated account\'s knowledge base and memories.',
                new ToolAnnotations(readOnlyHint: false, destructiveHint: false, openWorldHint: true),
                McpServerFactory::chatSchema(),
            ),
            $this->tool(
                'rag_search',
                'RAG knowledge search',
                'Semantic search across the authenticated user\'s vectorized documents and '
                .'knowledge base. Returns the most relevant text chunks with similarity scores.',
                new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
                McpServerFactory::ragSearchSchema(),
            ),
            $this->tool(
                'memory_search',
                'User memory search',
                'Search the authenticated user\'s stored long-term memories (preferences, '
                .'facts, context) using semantic similarity.',
                new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
                McpServerFactory::memorySearchSchema(),
            ),
            $this->tool(
                'memory_add',
                'Add a user memory',
                'Store a new long-term memory for the authenticated user — a durable fact or '
                .'preference that Synaplan will recall in future conversations.',
                new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
                McpServerFactory::memoryAddSchema(),
            ),
            $this->tool(
                'file_ingest',
                'Ingest a document for RAG',
                'Add a text document to the authenticated user\'s knowledge base. The content is '
                .'chunked, embedded, and stored so it becomes retrievable via rag_search.',
                new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
                McpServerFactory::fileIngestSchema(),
            ),
            $this->tool(
                'rag_similar',
                'Find similar chunks',
                'Find document chunks similar to a given source message/document id (the '
                .'`message_id` returned by rag_search).',
                new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
                McpServerFactory::ragSimilarSchema(),
            ),
            $this->tool(
                'list_chats',
                'List chats',
                'List the authenticated user\'s conversations (most recently updated first).',
                new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
                McpServerFactory::listChatsSchema(),
            ),
            $this->tool(
                'get_messages',
                'Get chat messages',
                'Return the messages of one of the user\'s chats, in chronological order.',
                new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
                McpServerFactory::getMessagesSchema(),
            ),
            $this->tool(
                'list_prompts',
                'List task prompts',
                'List the user\'s available Synaplan task prompts (internal `tools:*` prompts excluded).',
                new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
                McpServerFactory::listPromptsSchema(),
            ),
        ];

        if ($includeAssistants) {
            $tools[] = $this->tool(
                'list_assistants',
                'List assistants',
                'List assistants the authenticated user may use from a connected app. '
                .'Each row includes slug, name, description, version and origin.',
                new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
                McpServerFactory::listAssistantsSchema(),
            );
        }

        return $tools;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array{name: string, title: string, description: string, annotations: ToolAnnotations, schema: array<string, mixed>}
     */
    private function tool(string $name, string $title, string $description, ToolAnnotations $annotations, array $schema): array
    {
        return [
            'name' => $name,
            'title' => $title,
            'description' => $description,
            'annotations' => $annotations,
            'schema' => $schema,
        ];
    }
}
