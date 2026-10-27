<?php

declare(strict_types=1);

namespace App\Mcp;

use Mcp\Exception\ToolCallException;
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
    private const SIGN_IN_REQUIRED = 'Sign in to Synaplan to use this tool: connect with an API key or your Synaplan account.';

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

            $this->add($builder, $handlers[$name], $tool);
        }
    }

    /**
     * Same catalog for an unauthenticated client. The controller answers a
     * tools/call without a user with 401; the handler only backs that up.
     */
    public function registerDiscovery(Builder $builder, bool $includeAssistants): void
    {
        $refuse = static function (mixed ...$arguments): never {
            throw new ToolCallException(self::SIGN_IN_REQUIRED);
        };

        foreach ($this->definitions($includeAssistants) as $tool) {
            $this->add($builder, $refuse, $tool);
        }
    }

    /**
     * @param array{name: string, title: string, description: string, annotations: ToolAnnotations, schema: array<string, mixed>} $tool
     */
    private function add(Builder $builder, \Closure $handler, array $tool): void
    {
        $builder->addTool(
            $handler,
            $tool['name'],
            $tool['title'],
            $tool['description'],
            $tool['annotations'],
            $tool['schema'],
        );
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
                self::chatSchema(),
            ),
            $this->tool(
                'rag_search',
                'RAG knowledge search',
                'Semantic search across the authenticated user\'s vectorized documents and '
                .'knowledge base. Returns the most relevant text chunks with similarity scores.',
                new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
                self::ragSearchSchema(),
            ),
            $this->tool(
                'memory_search',
                'User memory search',
                'Search the authenticated user\'s stored long-term memories (preferences, '
                .'facts, context) using semantic similarity.',
                new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
                self::memorySearchSchema(),
            ),
            $this->tool(
                'memory_add',
                'Add a user memory',
                'Store a new long-term memory for the authenticated user — a durable fact or '
                .'preference that Synaplan will recall in future conversations.',
                new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
                self::memoryAddSchema(),
            ),
            $this->tool(
                'file_ingest',
                'Ingest a document for RAG',
                'Add a text document to the authenticated user\'s knowledge base. The content is '
                .'chunked, embedded, and stored so it becomes retrievable via rag_search.',
                new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
                self::fileIngestSchema(),
            ),
            $this->tool(
                'rag_similar',
                'Find similar chunks',
                'Find document chunks similar to a given source message/document id (the '
                .'`message_id` returned by rag_search).',
                new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
                self::ragSimilarSchema(),
            ),
            $this->tool(
                'list_chats',
                'List chats',
                'List the authenticated user\'s conversations (most recently updated first).',
                new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
                self::listChatsSchema(),
            ),
            $this->tool(
                'get_messages',
                'Get chat messages',
                'Return the messages of one of the user\'s chats, in chronological order.',
                new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
                self::getMessagesSchema(),
            ),
            $this->tool(
                'list_prompts',
                'List task prompts',
                'List the user\'s available Synaplan task prompts (internal `tools:*` prompts excluded).',
                new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
                self::listPromptsSchema(),
            ),
        ];

        if ($includeAssistants) {
            $tools[] = $this->tool(
                'list_assistants',
                'List assistants',
                'List assistants the authenticated user may use from a connected app. '
                .'Each row includes slug, name, description, version and origin.',
                new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
                self::listAssistantsSchema(),
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

    /**
     * @return array<string, mixed>
     */
    private static function ragSimilarSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'message_id' => [
                    'type' => 'integer',
                    'description' => 'The source document/message id to find similar chunks for '
                        .'(use a `message_id` returned by rag_search).',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 50,
                    'default' => 10,
                    'description' => 'Maximum number of similar chunks to return (1-50).',
                ],
            ],
            'required' => ['message_id'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function listChatsSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 200,
                    'default' => 50,
                    'description' => 'Maximum number of chats to return (1-200).',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function getMessagesSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'chat_id' => [
                    'type' => 'integer',
                    'description' => 'The id of the chat (from list_chats) to read messages from.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 100,
                    'default' => 50,
                    'description' => 'Maximum number of (most recent) messages to return (1-100).',
                ],
            ],
            'required' => ['chat_id'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function listPromptsSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function chatSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'message' => [
                    'type' => 'string',
                    'description' => 'The message or question to send through Synaplan\'s full AI '
                        .'pipeline (classification, web search, RAG over the user\'s documents, '
                        .'memories, and inference).',
                ],
                'chat_id' => [
                    'type' => ['integer', 'null'],
                    'description' => 'Optional existing chat id (from list_chats) to continue a '
                        .'conversation. Omit to start a new chat.',
                ],
                'agentId' => [
                    'type' => ['string', 'integer', 'null'],
                    'description' => 'Optional assistant id or slug (see list_assistants). Pins the reply '
                        .'to that assistant; fails when it is not published, not usable by you, '
                        .'or does not allow connected apps.',
                ],
            ],
            'required' => ['message'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function listAssistantsSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function ragSearchSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Natural-language search query.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 50,
                    'default' => 10,
                    'description' => 'Maximum number of chunks to return (1-50).',
                ],
                'min_score' => [
                    'type' => 'number',
                    'minimum' => 0,
                    'maximum' => 1,
                    'default' => 0.3,
                    'description' => 'Minimum similarity score (0-1) a chunk must reach to be returned.',
                ],
                'group_key' => [
                    'type' => ['string', 'null'],
                    'description' => 'Optional document group key to scope the search to a single document/collection.',
                ],
            ],
            'required' => ['query'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function fileIngestSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'A human-readable name/title for the document.',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'The text content to ingest. It is chunked, embedded, and made '
                        .'retrievable via rag_search.',
                ],
                'group_key' => [
                    'type' => ['string', 'null'],
                    'description' => 'Optional group key to scope the document. Pass the same value to '
                        .'rag_search to retrieve only this group; omit it to search the whole corpus.',
                ],
            ],
            'required' => ['name', 'content'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function memoryAddSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'category' => [
                    'type' => 'string',
                    'description' => 'Category for the memory, e.g. preferences, work, personal, health.',
                ],
                'key' => [
                    'type' => 'string',
                    'minLength' => 3,
                    'description' => 'Short identifier for the memory (min 3 characters), e.g. tech_stack.',
                ],
                'value' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'description' => 'The fact or preference to remember.',
                ],
            ],
            'required' => ['category', 'key', 'value'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function memorySearchSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Natural-language query describing the memory to look up.',
                ],
                'category' => [
                    'type' => ['string', 'null'],
                    'description' => 'Optional category filter (e.g. preferences, work, personal).',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 50,
                    'default' => 5,
                    'description' => 'Maximum number of memories to return (1-50).',
                ],
            ],
            'required' => ['query'],
            'additionalProperties' => false,
        ];
    }
}
