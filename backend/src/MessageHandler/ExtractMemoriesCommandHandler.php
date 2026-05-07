<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Message;
use App\Entity\User;
use App\Message\ExtractMemoriesCommand;
use App\Service\MemoryExtractionService;
use App\Service\UserMemoryService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler for {@see ExtractMemoriesCommand}.
 *
 * Phase 2a: runs on the messenger worker so the user's HTTP stream can
 * close immediately after the answer text is delivered. Persists newly
 * extracted memories via {@see UserMemoryService}; suggested deletions are
 * NOT auto-applied (the original ChatHandler behaviour was to surface them
 * as a UI suggestion, kept here as a structured log entry that the
 * notifications poll endpoint can pick up via {@see Message::getMeta()}).
 *
 * Errors are logged and re-thrown so the messenger retry strategy
 * (max 3 attempts with exponential backoff, see `messenger.yaml`) can
 * recover from transient Qdrant or AI provider blips.
 */
#[AsMessageHandler]
final readonly class ExtractMemoriesCommandHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private MemoryExtractionService $memoryExtractionService,
        private UserMemoryService $memoryService,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExtractMemoriesCommand $command): void
    {
        $messageId = $command->getMessageId();
        $userId = $command->getUserId();

        $message = $this->em->getRepository(Message::class)->find($messageId);
        if (!$message) {
            $this->logger->warning('ExtractMemoriesCommand: source message not found, skipping', [
                'message_id' => $messageId,
            ]);

            return;
        }

        $user = $this->em->getRepository(User::class)->find($userId);
        if (!$user) {
            $this->logger->warning('ExtractMemoriesCommand: user not found, skipping', [
                'user_id' => $userId,
                'message_id' => $messageId,
            ]);

            return;
        }

        if (!$user->isMemoriesEnabled()) {
            $this->logger->debug('ExtractMemoriesCommand: memories disabled by user, skipping', [
                'user_id' => $userId,
                'message_id' => $messageId,
            ]);

            return;
        }

        // Build the enhanced thread the same way the inline path used to: thread + assistant response.
        $enhancedThread = $command->getThreadSnapshot();
        $aiResponse = $command->getAiResponse();
        if ('' !== $aiResponse) {
            $enhancedThread[] = ['role' => 'assistant', 'content' => $aiResponse];
        }

        $this->logger->info('ExtractMemoriesCommand: starting extraction', [
            'message_id' => $messageId,
            'user_id' => $userId,
            'thread_length' => count($enhancedThread),
            'relevant_memories' => count($command->getRelevantMemories()),
        ]);

        try {
            $memoryActions = $this->memoryExtractionService->analyzeAndExtract(
                $message,
                $enhancedThread,
                $command->getRelevantMemories(),
            );
        } catch (\Throwable $e) {
            $this->logger->error('ExtractMemoriesCommand: extraction LLM call failed', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);

            throw $e; // let messenger retry
        }

        if (empty($memoryActions)) {
            $this->logger->info('ExtractMemoriesCommand: no memories extracted', [
                'message_id' => $messageId,
            ]);
            $this->writeOutcomeMeta($message, status: 'empty', savedMemories: [], deleteSuggestions: []);

            return;
        }

        $savedMemories = [];
        $deleteSuggestions = [];

        foreach ($memoryActions as $action) {
            try {
                $kind = $action['action'] ?? 'create';

                if ('create' === $kind) {
                    $memory = $this->memoryService->createMemory(
                        $user,
                        $action['category'],
                        $action['key'],
                        $action['value'],
                        'auto_detected',
                        $message->getId(),
                    );
                    $savedMemories[] = $memory->toArray();
                } elseif ('update' === $kind && isset($action['memory_id'])) {
                    $memory = $this->memoryService->updateMemory(
                        (int) $action['memory_id'],
                        $user,
                        $action['value'],
                        'ai_edited',
                        $message->getId(),
                    );
                    $savedMemories[] = $memory->toArray();
                } elseif ('delete' === $kind && isset($action['memory_id'])) {
                    $existing = $this->memoryService->getMemoryById((int) $action['memory_id'], $user);
                    if ($existing) {
                        $deleteSuggestions[] = $existing->toArray();
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('ExtractMemoriesCommand: failed to persist a single memory action', [
                    'message_id' => $messageId,
                    'action' => $action,
                    'error' => $e->getMessage(),
                ]);
                // Continue with the next action — partial results are better than none.
            }
        }

        $this->writeOutcomeMeta(
            $message,
            status: empty($savedMemories) && empty($deleteSuggestions) ? 'empty' : 'complete',
            savedMemories: $savedMemories,
            deleteSuggestions: $deleteSuggestions,
        );

        $this->logger->info('ExtractMemoriesCommand: extraction complete', [
            'message_id' => $messageId,
            'saved' => count($savedMemories),
            'delete_suggestions' => count($deleteSuggestions),
        ]);
    }

    /**
     * Write extraction outcome to the source message metadata so the
     * frontend's poll endpoint (Phase 2c) can pick it up after SSE
     * `complete` has already closed the stream.
     *
     * Stores a single JSON-encoded BMESSAGEMETA row keyed by
     * `extracted_memories` — small, idempotent, no schema change.
     *
     * @param array<int, array<string, mixed>> $savedMemories
     * @param array<int, array<string, mixed>> $deleteSuggestions
     */
    private function writeOutcomeMeta(
        Message $message,
        string $status,
        array $savedMemories,
        array $deleteSuggestions,
    ): void {
        try {
            $payload = json_encode([
                'status' => $status,
                'completed_at' => time(),
                'saved' => $savedMemories,
                'delete_suggestions' => $deleteSuggestions,
            ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);

            if (false === $payload) {
                $this->logger->warning('ExtractMemoriesCommand: failed to JSON-encode outcome', [
                    'message_id' => $message->getId(),
                ]);

                return;
            }

            $message->setMeta('extracted_memories', $payload);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('ExtractMemoriesCommand: failed to persist outcome meta', [
                'message_id' => $message->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
