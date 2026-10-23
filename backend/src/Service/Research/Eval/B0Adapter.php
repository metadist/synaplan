<?php

declare(strict_types=1);

namespace App\Service\Research\Eval;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Service\Message\Processing\TurnStatus;
use App\Service\Message\Processing\TurnWebSearch;
use App\Service\ModelConfigService;
use App\Service\Prompt\ChatPromptBlocks;
use App\Service\RAG\ChatKnowledgeContextLoader;
use Psr\Log\LoggerInterface;

/**
 * Today's web chat: a forced web-search vote, the turn's search and page
 * reading, the chat's knowledge loader, then the chat answer.
 *
 * Pages are read only when the turn reads them, so a disabled page reader
 * scores as it ships. The message is not saved. Search results are saved by
 * the turn only when the message already has an id, so this arm writes usage
 * on the evaluation account and nothing else.
 */
final readonly class B0Adapter
{
    private const READ_PAGES_VOTE = 3;
    private const KNOWLEDGE_LIMIT = 5;
    private const KNOWLEDGE_MIN_SCORE = 0.3;

    public function __construct(
        private TurnWebSearch $web,
        private ChatKnowledgeContextLoader $knowledge,
        private AiFacade $ai,
        private ModelConfigService $models,
        private LoggerInterface $logger,
    ) {
    }

    public function answer(CorpusItem $item, int $userId, ?string $folder, ?FrozenStore $frozen): string
    {
        $stored = $frozen?->get($item->id);
        if (null !== $stored) {
            $search = $stored['search'];
            $knowledge = $stored['knowledge'];
        } else {
            if ($item->knowledge && (null === $folder || '' === trim($folder))) {
                throw new \RuntimeException('This item reads a knowledge folder. Pass --folder. Nothing was sent.');
            }
            [$search, $knowledge] = $this->gather($item, $userId, $folder);
            $frozen?->put($item->id, ['search' => $search, 'knowledge' => $knowledge]);
        }

        $blocks = [];
        if (\is_array($search) && !empty($search['results'])) {
            $blocks[] = ChatPromptBlocks::webSearchResults($search);
        }
        if ('' !== trim($knowledge)) {
            $blocks[] = $knowledge;
        }

        $messages = [];
        if ([] !== $blocks) {
            $messages[] = ['role' => 'system', 'content' => implode("\n\n", $blocks)];
        }
        $messages[] = ['role' => 'user', 'content' => $item->question];

        $response = $this->ai->chat($messages, $userId, $this->chatOptions($userId));

        return trim((string) ($response['content'] ?? ''));
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: string}
     */
    private function gather(CorpusItem $item, int $userId, ?string $folder): array
    {
        $message = (new Message())->setUserId($userId)->setText($item->question)->setTopic('general');
        $search = $item->web ? $this->search($message, $item) : null;
        $knowledge = '';
        if ($item->knowledge) {
            $loaded = $this->knowledge->load($message, 'general', $folder, self::KNOWLEDGE_LIMIT, self::KNOWLEDGE_MIN_SCORE, null, true);
            $knowledge = \is_string($loaded['context'] ?? null) ? $loaded['context'] : '';
        }

        return [$search, $knowledge];
    }

    /**
     * The same binding the JSON chat path uses. A provider call without a
     * model name is refused before anything is sent.
     *
     * @return array{provider: string, model: string}
     */
    private function chatOptions(int $userId): array
    {
        $modelId = $this->models->getDefaultModel('CHAT', $userId);
        $provider = null !== $modelId ? $this->models->getProviderForModel($modelId) : null;
        $model = null !== $modelId ? $this->models->getModelName($modelId) : null;
        if (null === $provider || '' === $provider || null === $model || '' === $model) {
            throw new \RuntimeException('The evaluation account has no chat model. Nothing was sent.');
        }

        return ['provider' => $provider, 'model' => $model];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function search(Message $message, CorpusItem $item): ?array
    {
        $ran = $this->web->run(
            $message,
            [
                'topic' => 'general',
                'web_search' => true,
                'read_pages' => self::READ_PAGES_VOTE,
                'language' => $item->language,
            ],
            [],
            [],
            [],
            new TurnStatus(null, $this->logger),
        );
        $search = $ran['searchResults'] ?? null;

        return \is_array($search) ? $search : null;
    }
}
