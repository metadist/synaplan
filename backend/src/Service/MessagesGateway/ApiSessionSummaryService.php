<?php

declare(strict_types=1);

namespace App\Service\MessagesGateway;

use App\AI\Service\AiFacade;
use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\User;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Rolling 2-3 sentence summary of an API/agent session, kept as one chat in
 * the user's normal chat list (BCHATS source 'api' + a single OUT message
 * updated in place).
 *
 * Runs ONLY on the messenger worker ({@see \App\MessageHandler\SummarizeApiSessionCommandHandler})
 * — never on the API request path. Excerpts arrive pre-capped in the queue
 * payload; this service debounces so an agent burst (Claude Code fires dozens
 * of requests per session) costs one summarizer call per
 * {@see self::REFRESH_MIN_INTERVAL_SECONDS} / {@see self::REFRESH_EVERY_N_REQUESTS}
 * window, not one per request.
 *
 * The summarizer model resolves via
 * {@see ModelConfigService::getSummaryModelConfig()} (SUMMARIZE → SORT → CHAT
 * defaults — the shipped SORT binding is a fast, cheap Groq model). Never
 * hardcodes a model name.
 *
 * Privacy: mirrors BUSELOG's posture — no full transcripts are persisted,
 * only the short derived summary. Pending excerpts live in the cache pool
 * (capped) until folded, then are dropped.
 */
final readonly class ApiSessionSummaryService
{
    /**
     * Cap applied by DISPATCHERS before the excerpt enters the queue payload.
     */
    public const EXCERPT_MAX_CHARS = 1500;

    private const PENDING_MAX_ITEMS = 20;
    private const PENDING_MAX_CHARS = 8000;
    private const SUMMARY_MAX_CHARS = 600;
    private const REFRESH_MIN_INTERVAL_SECONDS = 120;
    private const REFRESH_EVERY_N_REQUESTS = 5;
    private const STATE_TTL_SECONDS = 21600;

    /**
     * Reasoning models (the shipped SORT default) spend budget on thinking
     * tokens before the short answer — same headroom rationale as
     * MessageSorter::CLASSIFICATION_MAX_TOKENS.
     */
    private const SUMMARY_MAX_TOKENS = 1024;

    private const CACHE_PREFIX = 'api_session_summary.';
    private const CHAT_SOURCE = 'api';
    private const MESSAGE_TYPE = 'API';
    private const MESSAGE_TOPIC = 'api_session';

    public function __construct(
        private AiFacade $aiFacade,
        private ModelConfigService $modelConfigService,
        private RateLimitService $rateLimitService,
        private EntityManagerInterface $em,
        private CacheItemPoolInterface $cache,
        private LockFactory $lockFactory,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Fold one API request/response excerpt into the session's rolling summary.
     *
     * Cheap when debounced (cache write only); one summarizer call plus a
     * BMESSAGES upsert when the refresh window is due.
     */
    public function record(
        int $userId,
        string $sessionKey,
        string $client,
        string $model,
        string $requestExcerpt,
        string $responseExcerpt,
    ): void {
        $stateKey = self::CACHE_PREFIX.hash('sha256', $userId.'|'.$sessionKey);

        // Serialize concurrent worker messages for the same session so the
        // read-modify-write on the state and the chat upsert never race.
        $lock = $this->lockFactory->createLock('lock_'.$stateKey, ttl: 60.0, autoRelease: true);
        $lock->acquire(true);

        try {
            $state = $this->readState($stateKey);

            $entry = trim(sprintf(
                "Request: %s\nResponse: %s",
                $this->clip(trim($requestExcerpt), self::EXCERPT_MAX_CHARS),
                $this->clip(trim($responseExcerpt), self::EXCERPT_MAX_CHARS),
            ));
            $state['pending'][] = $entry;
            $state['pending'] = $this->capPending($state['pending']);
            ++$state['countSinceRefresh'];

            if (!$this->isRefreshDue($state)) {
                $this->writeState($stateKey, $state);

                return;
            }

            $user = $this->em->getRepository(User::class)->find($userId);
            if (null === $user) {
                return;
            }

            $summary = $this->summarize($user, $state['summary'], $state['pending'], $client, $model);
            if (null === $summary) {
                // Keep the pending excerpts; the next command retries the fold.
                $this->writeState($stateKey, $state);

                return;
            }

            $chatId = $this->upsertSessionChat($userId, $state, $client, $summary, $model);

            $state['summary'] = $summary;
            $state['pending'] = [];
            $state['countSinceRefresh'] = 0;
            $state['lastRefreshAt'] = time();
            $state['chatId'] = $chatId;
            $this->writeState($stateKey, $state);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param list<string> $pending
     */
    private function summarize(User $user, string $previousSummary, array $pending, string $client, string $model): ?string
    {
        $userId = (int) $user->getId();
        $modelConfig = $this->modelConfigService->getSummaryModelConfig($userId);

        $sections = [];
        if ('' !== $previousSummary) {
            $sections[] = "## Previous session summary\n".$previousSummary;
        }
        $sections[] = "## New API requests in this session\n".implode("\n---\n", $pending);

        try {
            $response = $this->aiFacade->chat([
                ['role' => 'system', 'content' => $this->buildSystemPrompt($client, $model)],
                ['role' => 'user', 'content' => implode("\n\n", $sections)],
            ], $userId, [
                'provider' => $modelConfig['provider'] ?? null,
                'model' => $modelConfig['model'] ?? null,
                'temperature' => 0.2,
                'max_tokens' => self::SUMMARY_MAX_TOKENS,
            ]);

            $summary = $this->clip(trim((string) ($response['content'] ?? '')), self::SUMMARY_MAX_CHARS);
            if ('' === $summary) {
                return null;
            }

            $this->recordSummarizerUsage($user, $modelConfig['model_id'] ?? null, $response, $summary);

            return $summary;
        } catch (\Throwable $e) {
            $this->logger->warning('ApiSessionSummaryService: summarizer call failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function buildSystemPrompt(string $client, string $model): string
    {
        $clientLabel = $this->clientLabel($client);

        return <<<PROMPT
            You maintain a rolling summary of an API session: a user connected an external client ({$clientLabel}, model {$model}) to their account, and you see short excerpts of what was requested and answered. Fold the new excerpts into the previous summary (when present) and return the updated summary.

            Rules:
            - 2-3 sentences, plain prose, no headings, no bullet lists.
            - Describe WHAT the session is about and what was done (topics, tasks, files or tools touched) — not the mechanics of the API.
            - Write in the language the excerpts use.
            - Be factual. Never invent information that is not in the excerpts.
            - No preamble, no meta commentary — output only the summary text.
            PROMPT;
    }

    /**
     * Create the per-session chat on first refresh, then update the single
     * OUT message's text in place on subsequent refreshes.
     *
     * @param array{chatId: int|null} $state
     */
    private function upsertSessionChat(int $userId, array $state, string $client, string $summary, string $model): int
    {
        $chat = null;
        if (null !== $state['chatId']) {
            $chat = $this->em->getRepository(Chat::class)->find($state['chatId']);
            if (null !== $chat && $chat->getUserId() !== $userId) {
                $chat = null;
            }
        }

        if (null === $chat) {
            $chat = new Chat();
            $chat->setUserId($userId);
            $chat->setSource(self::CHAT_SOURCE);
            $chat->setTitle(sprintf('%s · %s', $this->clientLabel($client), date('Y-m-d H:i')));
            $this->em->persist($chat);
            $this->em->flush();
        }

        $message = $this->em->getRepository(Message::class)->findOneBy([
            'chatId' => $chat->getId(),
            'direction' => 'OUT',
        ]);

        if (null === $message) {
            $message = new Message();
            $message->setUserId($userId);
            $message->setTrackingId(time());
            $message->setMessageType(self::MESSAGE_TYPE);
            $message->setTopic(self::MESSAGE_TOPIC);
            $message->setDirection('OUT');
            $message->setChat($chat);
            $this->em->persist($message);
            // Flush to get the message ID — MessageMeta rows need it.
            $this->em->flush();
        }

        $message->setText($summary);
        $message->setUnixTimestamp(time());
        $message->setDateTime(date('YmdHis'));
        $message->setMeta('api_session.model', $model);
        $chat->updateTimestamp();
        $this->em->flush();

        return (int) $chat->getId();
    }

    /**
     * @param array<string, mixed> $response
     */
    private function recordSummarizerUsage(User $user, ?int $modelId, array $response, string $summary): void
    {
        try {
            $this->rateLimitService->recordUsage($user, 'SORTING', [
                'usage' => $response['usage'] ?? [],
                'model_id' => $modelId,
                'provider' => $response['provider'] ?? '',
                'model' => $response['model'] ?? '',
                'input_text' => '',
                'response_text' => $summary,
                'source' => 'API_SUMMARY',
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('ApiSessionSummaryService: failed to record summarizer usage', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array{lastRefreshAt: int, countSinceRefresh: int} $state
     */
    private function isRefreshDue(array $state): bool
    {
        if (0 === $state['lastRefreshAt']) {
            return true; // first request of the session: surface the chat immediately
        }

        if ($state['countSinceRefresh'] >= self::REFRESH_EVERY_N_REQUESTS) {
            return true;
        }

        return (time() - $state['lastRefreshAt']) >= self::REFRESH_MIN_INTERVAL_SECONDS;
    }

    /**
     * @param list<string> $pending
     *
     * @return list<string>
     */
    private function capPending(array $pending): array
    {
        if (\count($pending) > self::PENDING_MAX_ITEMS) {
            $pending = \array_slice($pending, -self::PENDING_MAX_ITEMS);
        }

        // Drop oldest entries until the combined size fits the char budget.
        while (\count($pending) > 1 && $this->totalChars($pending) > self::PENDING_MAX_CHARS) {
            array_shift($pending);
        }
        if ([] !== $pending && $this->totalChars($pending) > self::PENDING_MAX_CHARS) {
            $pending = [$this->clip($pending[0], self::PENDING_MAX_CHARS)];
        }

        return $pending;
    }

    /**
     * @param list<string> $items
     */
    private function totalChars(array $items): int
    {
        $total = 0;
        foreach ($items as $item) {
            $total += mb_strlen($item);
        }

        return $total;
    }

    /**
     * @return array{summary: string, pending: list<string>, countSinceRefresh: int, lastRefreshAt: int, chatId: int|null}
     */
    private function readState(string $stateKey): array
    {
        $default = [
            'summary' => '',
            'pending' => [],
            'countSinceRefresh' => 0,
            'lastRefreshAt' => 0,
            'chatId' => null,
        ];

        $item = $this->cache->getItem($stateKey);
        if (!$item->isHit()) {
            return $default;
        }

        $raw = $item->get();
        if (!\is_array($raw)) {
            return $default;
        }

        return [
            'summary' => \is_string($raw['summary'] ?? null) ? $raw['summary'] : '',
            'pending' => \is_array($raw['pending'] ?? null) ? array_values(array_filter($raw['pending'], 'is_string')) : [],
            'countSinceRefresh' => \is_int($raw['countSinceRefresh'] ?? null) ? $raw['countSinceRefresh'] : 0,
            'lastRefreshAt' => \is_int($raw['lastRefreshAt'] ?? null) ? $raw['lastRefreshAt'] : 0,
            'chatId' => \is_int($raw['chatId'] ?? null) ? $raw['chatId'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function writeState(string $stateKey, array $state): void
    {
        $item = $this->cache->getItem($stateKey);
        $item->set($state);
        $item->expiresAfter(self::STATE_TTL_SECONDS);
        $this->cache->save($item);
    }

    private function clientLabel(string $client): string
    {
        return match ($client) {
            'claude-code' => 'Claude Code',
            'openai-api' => 'API client (OpenAI-compatible)',
            default => 'API client',
        };
    }

    private function clip(string $value, int $maxChars): string
    {
        if (mb_strlen($value) <= $maxChars) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $maxChars)).'…';
    }
}
