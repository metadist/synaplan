<?php

declare(strict_types=1);

namespace App\AI\Messages;

use App\Entity\User;
use App\Service\FeedbackConfigService;
use App\Service\Knowledge\KnowledgeContextFormatter;
use App\Service\RAG\VectorSearchService;
use App\Service\RAG\VectorStorage\DTO\RagScope;
use App\Service\Runtime\RuntimeProfile;
use App\Service\UserMemoryService;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Cache-safe trailing system-block injection for the Messages gateway.
 *
 * Session key = x-claude-code-session-id when present, else SHA-256 of the
 * first user turn + user id. The injected block is computed once per session
 * and replayed byte-identically on every subsequent turn.
 */
final readonly class MessagesContextInjector
{
    private const CACHE_PREFIX = 'messages_gateway_context_';
    private const CACHE_TTL = 7200;
    private const MAX_CHARS = 8000;
    private const RAG_LIMIT = 5;
    private const MEMORY_LIMIT = 5;

    public function __construct(
        private UserMemoryService $userMemoryService,
        private VectorSearchService $vectorSearchService,
        private KnowledgeContextFormatter $formatter,
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
        private FeedbackConfigService $feedbackConfig,
    ) {
    }

    /**
     * Append a trailing system content block when context injection is enabled.
     *
     * Desktop headers pin an Assistant recipe and/or a knowledge folder. The
     * request body `model` is never rewritten here (C15).
     *
     * @param array<string, mixed> $requestBody
     *
     * @return array{body: array<string, mixed>, injected: bool, hash: string|null}
     */
    public function inject(
        array $requestBody,
        User $user,
        string $sessionKey,
        ?string $headerOverride = null,
        ?DesktopTurnOptions $desktop = null,
        ?RuntimeProfile $profile = null,
    ): array {
        $desktop ??= DesktopTurnOptions::none();
        $injected = false;

        if (null !== $profile && is_string($profile->systemPrompt) && '' !== trim($profile->systemPrompt)) {
            $requestBody = $this->prependSystemBlock($requestBody, trim($profile->systemPrompt));
            $injected = true;
        }

        if ('off' === strtolower((string) $headerOverride)) {
            return ['body' => $requestBody, 'injected' => $injected, 'hash' => null];
        }

        $block = $this->sessionBlock($user, $sessionKey, $requestBody, $desktop, $profile);
        if (null === $block || '' === $block) {
            return ['body' => $requestBody, 'injected' => $injected, 'hash' => null];
        }

        $requestBody = $this->appendSystemBlock($requestBody, $block);

        return [
            'body' => $requestBody,
            'injected' => true,
            'hash' => hash('sha256', $block),
        ];
    }

    /**
     * @param array<string, mixed> $requestBody
     */
    private function sessionBlock(
        User $user,
        string $sessionKey,
        array $requestBody,
        DesktopTurnOptions $desktop,
        ?RuntimeProfile $profile,
    ): ?string {
        $userId = (int) $user->getId();
        $cacheKey = self::CACHE_PREFIX.hash('sha256', implode(':', [
            $sessionKey,
            (string) $userId,
            $desktop->ragGroupKey ?? '',
            null !== $profile ? (string) ($profile->agentId ?? '') : '',
        ]));
        $item = $this->cache->getItem($cacheKey);
        if ($item->isHit()) {
            $cached = $item->get();
            if (\is_string($cached)) {
                return $cached;
            }
        }

        $query = $this->firstUserText($requestBody);
        if ('' === trim($query)) {
            $item->set('');
            $item->expiresAfter(self::CACHE_TTL);
            $this->cache->save($item);

            return null;
        }

        try {
            $embedded = $this->userMemoryService->embedUserQuery($userId, $query);
        } catch (\Throwable $e) {
            $this->logger->warning('MessagesContextInjector: embed failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            $embedded = null;
        }

        $rag = '';
        $memories = '';
        $vector = null !== $embedded ? $embedded['embedding'] : [];
        if ([] !== $vector) {
            try {
                $explicit = $this->explicitScopes($userId, $desktop, $profile);
                $ragHits = $this->vectorSearchService->semanticSearchByVector(
                    $userId,
                    $vector,
                    null === $explicit ? $desktop->ragGroupKey : null,
                    null !== $profile && null !== $profile->ragLimit ? $profile->ragLimit : self::RAG_LIMIT,
                    null !== $profile && null !== $profile->ragMinScore ? $profile->ragMinScore : 0.3,
                    $query,
                    $explicit,
                );
                $rag = $this->formatter->formatRagContext($ragHits);
            } catch (\Throwable $e) {
                $this->logger->warning('MessagesContextInjector: RAG failed', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                // Memory collection may use a pinned embedding model; fall back
                // to a memory-specific embed when the shared VECTORIZE vector
                // would be the wrong dimension.
                $memoryEmbed = $this->userMemoryService->embedQueryForMemorySearch($userId, $query);
                $memoryVector = null !== $memoryEmbed ? $memoryEmbed['embedding'] : $vector;
                $memoryHits = $this->userMemoryService->searchMemoriesByVector(
                    $userId,
                    $memoryVector,
                    null,
                    self::MEMORY_LIMIT,
                    $this->feedbackConfig->getMinChatMemoryScore(),
                );
                $memories = $this->formatter->formatMemoriesContext($memoryHits);
            } catch (\Throwable $e) {
                $this->logger->warning('MessagesContextInjector: memories failed', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $block = $this->formatter->combineAndClamp($rag, $memories, self::MAX_CHARS);
        $item->set($block);
        $item->expiresAfter(self::CACHE_TTL);
        $this->cache->save($item);

        return '' !== $block ? $block : null;
    }

    /**
     * @return list<RagScope>|null
     */
    private function explicitScopes(int $userId, DesktopTurnOptions $desktop, ?RuntimeProfile $profile): ?array
    {
        if (null === $profile) {
            return null;
        }

        $scopes = [];
        foreach ($profile->ragScopes as $scope) {
            $scopes[] = new RagScope((int) $scope['ownerId'], $scope['groupKey']);
        }
        if (null !== $desktop->ragGroupKey && '' !== $desktop->ragGroupKey) {
            $scopes[] = new RagScope($userId, $desktop->ragGroupKey);
        }

        return $scopes;
    }

    /**
     * @param array<string, mixed> $requestBody
     *
     * @return array<string, mixed>
     */
    private function prependSystemBlock(array $requestBody, string $blockText): array
    {
        $block = ['type' => 'text', 'text' => $blockText];
        $system = $requestBody['system'] ?? null;

        if (null === $system || '' === $system) {
            $requestBody['system'] = [$block];

            return $requestBody;
        }

        if (\is_string($system)) {
            $requestBody['system'] = [$block, ['type' => 'text', 'text' => $system]];

            return $requestBody;
        }

        if (\is_array($system)) {
            array_unshift($system, $block);
            $requestBody['system'] = $system;
        }

        return $requestBody;
    }

    /**
     * @param array<string, mixed> $requestBody
     *
     * @return array<string, mixed>
     */
    private function appendSystemBlock(array $requestBody, string $blockText): array
    {
        $system = $requestBody['system'] ?? null;

        if (null === $system || '' === $system) {
            $requestBody['system'] = [
                ['type' => 'text', 'text' => ltrim($blockText)],
            ];

            return $requestBody;
        }

        if (\is_string($system)) {
            $requestBody['system'] = [
                ['type' => 'text', 'text' => $system],
                ['type' => 'text', 'text' => ltrim($blockText)],
            ];

            return $requestBody;
        }

        if (\is_array($system)) {
            $system[] = ['type' => 'text', 'text' => ltrim($blockText)];
            $requestBody['system'] = $system;

            return $requestBody;
        }

        return $requestBody;
    }

    /**
     * @param array<string, mixed> $requestBody
     */
    private function firstUserText(array $requestBody): string
    {
        foreach ($requestBody['messages'] ?? [] as $msg) {
            if (!\is_array($msg) || 'user' !== ($msg['role'] ?? '')) {
                continue;
            }
            $content = $msg['content'] ?? '';
            if (\is_string($content)) {
                return $content;
            }
            if (\is_array($content)) {
                $parts = [];
                foreach ($content as $block) {
                    if (\is_array($block) && 'text' === ($block['type'] ?? '') && isset($block['text'])) {
                        $parts[] = (string) $block['text'];
                    }
                }

                return implode("\n", $parts);
            }
        }

        return '';
    }
}
