<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\User;
use App\Service\RAG\VectorSearchService;
use App\Service\UserMemoryService;
use Mcp\Server;
use Mcp\Server\Session\Psr16SessionStore;
use Mcp\Server\Session\SessionStoreInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Psr16Cache;

/**
 * Builds a per-request MCP {@see Server} exposing a curated, user-scoped set of
 * Synaplan capabilities as MCP tools.
 *
 * Design notes:
 *  - The server is rebuilt for every HTTP request so the registered tool
 *    closures can capture the authenticated {@see User}; authorization is always
 *    enforced per request by the Symfony `mcp` firewall, never by the MCP
 *    session. The MCP session only carries protocol plumbing (request counters,
 *    outgoing queues) and is persisted in the shared cache so the
 *    initialize → tools/list → tools/call round-trip survives across the
 *    stateless HTTP requests.
 *  - Tools are a deliberately small, read-only allowlist for the first
 *    iteration (see _devextras/planning/.../02-mcp-integration/00-ROADMAP-2026.md).
 */
final class McpServerFactory
{
    private const SERVER_NAME = 'Synaplan';
    private const SERVER_VERSION = '0.1.0';
    private const SESSION_TTL_SECONDS = 3600;

    private ?SessionStoreInterface $sessionStore = null;

    public function __construct(
        private readonly VectorSearchService $vectorSearch,
        private readonly UserMemoryService $memoryService,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Build a server instance with all tools bound to the given user.
     */
    public function build(User $user): Server
    {
        return Server::builder()
            ->setServerInfo(
                self::SERVER_NAME,
                self::SERVER_VERSION,
                'Synaplan MCP server — knowledge retrieval (RAG) and user memories.',
            )
            ->setInstructions(
                'Use rag_search to retrieve relevant chunks from the user\'s vectorized '
                .'documents and chat knowledge base, and memory_search to look up facts the '
                .'user has stored about themselves. Both tools are scoped to the authenticated '
                .'Synaplan account.',
            )
            ->setLogger($this->logger)
            ->setSession($this->sessionStore())
            ->addTool(
                $this->ragSearchHandler($user),
                'rag_search',
                'RAG knowledge search',
                'Semantic search across the authenticated user\'s vectorized documents and '
                .'knowledge base. Returns the most relevant text chunks with similarity scores.',
                null,
                self::ragSearchSchema(),
            )
            ->addTool(
                $this->memorySearchHandler($user),
                'memory_search',
                'User memory search',
                'Search the authenticated user\'s stored long-term memories (preferences, '
                .'facts, context) using semantic similarity.',
                null,
                self::memorySearchSchema(),
            )
            ->build();
    }

    /**
     * @return \Closure(string, int, float, ?string): array<string, mixed>
     */
    private function ragSearchHandler(User $user): \Closure
    {
        return function (string $query, int $limit = 10, float $min_score = 0.3, ?string $group_key = null) use ($user): array {
            $results = $this->vectorSearch->semanticSearch(
                $query,
                (int) $user->getId(),
                $group_key,
                max(1, min(50, $limit)),
                max(0.0, min(1.0, $min_score)),
            );

            return [
                'query' => $query,
                'total_results' => \count($results),
                'provider' => $this->vectorSearch->getProviderName(),
                'results' => array_map(static fn (array $r): array => [
                    'chunk_id' => $r['chunk_id'] ?? null,
                    'message_id' => $r['message_id'] ?? null,
                    'text' => $r['chunk_text'] ?? '',
                    'score' => $r['score'] ?? null,
                ], $results),
            ];
        };
    }

    /**
     * @return \Closure(string, ?string, int): array<string, mixed>
     */
    private function memorySearchHandler(User $user): \Closure
    {
        return function (string $query, ?string $category = null, int $limit = 5) use ($user): array {
            if (!$this->memoryService->isAvailable()) {
                return [
                    'available' => false,
                    'query' => $query,
                    'total_results' => 0,
                    'memories' => [],
                    'error' => 'Memory service is currently unavailable.',
                ];
            }

            $memories = $this->memoryService->searchMemories(
                $user,
                $query,
                $category,
                max(1, min(50, $limit)),
            );

            return [
                'available' => true,
                'query' => $query,
                'total_results' => \count($memories),
                'memories' => $memories,
            ];
        };
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

    private function sessionStore(): SessionStoreInterface
    {
        return $this->sessionStore ??= new Psr16SessionStore(
            new Psr16Cache($this->cache),
            'mcp-sess-',
            self::SESSION_TTL_SECONDS,
        );
    }
}
