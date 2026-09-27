<?php

declare(strict_types=1);

namespace App\Service\MessagesGateway;

use App\AI\Messages\AnthropicContentText;
use App\AI\Messages\ApiSessionClient;
use App\AI\Service\AiFacade;
use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\User;
use App\Service\ModelConfigService;
use App\Service\Prompt\LanguageDirectiveBuilder;
use App\Service\RateLimitService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Rolling record of an API/agent session, kept as one chat in the user's
 * normal chat list (BCHATS source 'api').
 *
 * The chat holds the actual turns the external client saw — one IN message
 * with the user's request excerpt and one OUT message with the assistant's
 * response excerpt per request — plus ONE rolling summary message (topic
 * `api_session`, labelled as a session log) that is updated in place. The
 * summary alone used to be the whole thread, so opening the conversation in
 * Synaplan showed a third-person recap instead of what was said (#2023).
 *
 * Runs ONLY on the messenger worker ({@see \App\MessageHandler\SummarizeApiSessionCommandHandler})
 * — never on the API request path. Excerpts arrive pre-capped in the queue
 * payload; this service debounces so an agent burst (Claude Code fires dozens
 * of requests per session) costs one summarizer call per
 * {@see self::REFRESH_MIN_INTERVAL_SECONDS} / {@see self::REFRESH_EVERY_N_REQUESTS}
 * window, not one per request.
 *
 * The summarizer model resolves via
 * {@see ModelConfigService::getSummaryModelConfig()} (ANALYZE → CHAT). Never
 * hardcodes a model name.
 *
 * Privacy: no full transcripts are persisted, only the short capped excerpts
 * ({@see self::EXCERPT_MAX_CHARS}) as turns plus the derived summary. Pending
 * excerpts live in the cache pool (capped) until folded, then are dropped.
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
    // Turns reuse the plain conversation topic, mirroring how MessageProcessor
    // persists external history — the summary keeps its own topic so the
    // in-place lookup below never grabs a turn.
    private const TURN_TOPIC = 'CHAT';
    // Language-neutral tag: the summary is machine text in the user's language
    // and must not read as a message someone sent.
    private const SUMMARY_LABEL = '[Session log]';

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

            $requestExcerpt = AnthropicContentText::humanText($requestExcerpt);
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

            $language = $this->resolveSummaryLanguage($user, $state['pending']);
            $summary = $this->summarize($user, $state['summary'], $state['pending'], $client, $model, $language);
            if (null === $summary) {
                // Keep the pending excerpts; the next command retries the fold.
                $this->writeState($stateKey, $state);

                return;
            }

            $chatId = $this->upsertSessionChat($userId, $user, $state, $client, $summary, $model, $language, $state['pending']);

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
    private function summarize(User $user, string $previousSummary, array $pending, string $client, string $model, string $language): ?string
    {
        $userId = (int) $user->getId();
        $modelConfig = $this->modelConfigService->getSummaryModelConfig($userId);
        $provider = strtolower(trim((string) ($modelConfig['provider'] ?? '')));
        $modelName = $modelConfig['model'] ?? null;
        // The dev stub answers with a canned "demo mode" paragraph and a
        // placeholder image. That must not become the session log.
        if ('test' === $provider || !\is_string($modelName) || '' === trim($modelName)) {
            return $this->plainExcerptSummary($pending);
        }

        $sections = [];
        if ('' !== $previousSummary) {
            $sections[] = "## Previous session summary\n".$previousSummary;
        }
        $sections[] = "## New API requests in this session\n".implode("\n---\n", $pending);

        try {
            $response = $this->aiFacade->chat([
                ['role' => 'system', 'content' => $this->buildSystemPrompt($client, $model, $language)],
                ['role' => 'user', 'content' => implode("\n\n", $sections)],
            ], $userId, [
                'provider' => $modelConfig['provider'] ?? null,
                'model' => $modelName,
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

    private function buildSystemPrompt(string $client, string $model, string $language): string
    {
        $clientLabel = $this->clientLabel($client);
        $languageName = LanguageDirectiveBuilder::nameFor($language);

        $prompt = <<<PROMPT
            You maintain a rolling summary of an API session: a user connected an external client ({$clientLabel}, model {$model}) to their account, and you see short excerpts of what was requested and answered. Fold the new excerpts into the previous summary (when present) and return the updated summary.

            Rules:
            - 2-3 sentences, plain prose, no headings, no bullet lists.
            - Describe WHAT the session is about and what was done (topics, tasks, files or tools touched) — not the mechanics of the API.
            - Write the summary in {$languageName}. When you quote the user, keep their original wording — do not translate quoted questions or answers.
            - Be factual. Never invent information that is not in the excerpts.
            - No preamble, no meta commentary — output only the summary text.
            PROMPT;

        return $prompt.LanguageDirectiveBuilder::buildForOutputLanguage($language);
    }

    /**
     * Language of the log the user reads in Synaplan.
     *
     * Prefer a confident guess from the request excerpts (the actual user
     * question, not the assistant reply or a previous summary). Fall back to
     * the account UI locale so a short or wrapped client prompt cannot leave
     * the model free to pick another language.
     *
     * @param list<string> $pending
     */
    private function resolveSummaryLanguage(User $user, array $pending): string
    {
        $requests = [];
        foreach ($pending as $entry) {
            if (1 !== preg_match('/^Request:[ \t]*(.*)$/s', $entry, $match)) {
                continue;
            }
            $parts = preg_split('/^Response:\s*/m', (string) $match[1], 2);
            $requests[] = trim(is_array($parts) ? ($parts[0] ?? '') : '');
        }

        return $this->detectRequestLanguage(implode("\n", $requests)) ?? $user->getLocale();
    }

    /**
     * Distinctive stopword / question-word heuristic for the five Synaplan
     * UI locales. Returns null when there is no signal (caller uses locale).
     */
    private function detectRequestLanguage(string $text): ?string
    {
        $normalized = preg_replace('/[^\p{L}]+/u', ' ', mb_strtolower($text)) ?? '';
        $lower = ' '.trim($normalized).' ';
        if ('  ' === $lower) {
            return null;
        }

        $hits = [
            'de' => 0,
            'en' => 0,
            'es' => 0,
            'fr' => 0,
            'tr' => 0,
        ];

        foreach ([
            ' ich ', ' der ', ' die ', ' das ', ' und ', ' nicht ', ' ist ',
            ' wer ', ' wie ', ' was ', ' du ', ' bist ', ' hallo ', ' danke ',
            ' bitte ',
        ] as $word) {
            if (str_contains($lower, $word)) {
                $hits['de'] += 2;
            }
        }
        foreach ([
            ' the ', ' and ', ' you ', ' who ', ' what ', ' how ', ' why ',
            ' are ', ' is ', ' please ', ' hello ', ' thanks ',
        ] as $word) {
            if (str_contains($lower, $word)) {
                $hits['en'] += 2;
            }
        }
        foreach ([
            ' el ', ' los ', ' las ', ' para ', ' quién ', ' qué ', ' cómo ',
            ' eres ', ' gracias ', ' hola ',
        ] as $word) {
            if (str_contains($lower, $word)) {
                $hits['es'] += 2;
            }
        }
        foreach ([
            ' le ', ' les ', ' une ', ' est ', ' pour ', ' qui ', ' quoi ',
            ' comment ', ' merci ', ' bonjour ',
        ] as $word) {
            if (str_contains($lower, $word)) {
                $hits['fr'] += 2;
            }
        }
        foreach ([
            ' ve ', ' bir ', ' için ', ' bu ', ' ile ', ' merhaba ', ' teşekkür ',
        ] as $word) {
            if (str_contains($lower, $word)) {
                $hits['tr'] += 2;
            }
        }

        arsort($hits);
        $best = array_key_first($hits);
        $scores = array_values($hits);
        $bestScore = $hits[$best];
        $runnerUp = $scores[1];

        // One distinctive anchor is enough on a short request, but a tie
        // (qué vs is) must not pick English just because it is listed first.
        return $bestScore >= 2 && $bestScore > $runnerUp ? $best : null;
    }

    /**
     * Create the per-session chat on first refresh, append the folded turns as
     * real IN/OUT messages, then update the single labelled summary message's
     * text in place on subsequent refreshes.
     *
     * @param array{chatId: int|null} $state
     * @param list<string>            $turns pending "Request: …\nResponse: …" entries being folded
     */
    private function upsertSessionChat(int $userId, User $user, array $state, string $client, string $summary, string $model, string $language, array $turns): int
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
            $chat->setTitle(sprintf('%s · %s', $this->clientLabel($client), ApiSessionClient::localStamp($user)));
            $this->em->persist($chat);
            $this->em->flush();
        } elseif (ApiSessionClient::DESKTOP === $client) {
            $retitled = ApiSessionClient::retitleDesktop((string) $chat->getTitle());
            if (null !== $retitled) {
                $chat->setTitle($retitled);
            }
        }

        // One second per message keeps the turn order stable for readers that
        // sort by time; the summary lands last so list previews keep showing
        // the compact trail.
        $tick = time();
        foreach ($turns as $entry) {
            [$request, $response] = $this->splitTurn($entry);
            $request = AnthropicContentText::humanText($request);
            if ('' !== $request) {
                $this->appendTurnMessage($userId, $chat, 'IN', $request, $language, $tick);
                ++$tick;
            }
            if ('' !== $response) {
                $this->appendTurnMessage($userId, $chat, 'OUT', $response, $language, $tick);
                ++$tick;
            }
        }

        if ('' === trim($summary)) {
            $chat->updateTimestamp();
            $this->em->flush();

            return (int) $chat->getId();
        }

        $message = $this->em->getRepository(Message::class)->findOneBy([
            'chatId' => $chat->getId(),
            'direction' => 'OUT',
            'topic' => self::MESSAGE_TOPIC,
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

        $message->setText(self::SUMMARY_LABEL.' '.$summary);
        $message->setLanguage($language);
        $message->setUnixTimestamp($tick);
        $message->setDateTime(date('YmdHis', $tick));
        $message->setMeta('api_session.model', $model);
        $chat->updateTimestamp();
        $this->em->flush();

        return (int) $chat->getId();
    }

    private function appendTurnMessage(int $userId, Chat $chat, string $direction, string $text, string $language, int $timestamp): void
    {
        $turn = new Message();
        $turn->setUserId($userId);
        $turn->setTrackingId($timestamp);
        $turn->setMessageType(self::MESSAGE_TYPE);
        $turn->setTopic(self::TURN_TOPIC);
        $turn->setDirection($direction);
        $turn->setStatus('complete');
        $turn->setText($text);
        $turn->setLanguage($language);
        $turn->setUnixTimestamp($timestamp);
        $turn->setDateTime(date('YmdHis', $timestamp));
        $turn->setChat($chat);
        $this->em->persist($turn);
    }

    /**
     * Split a folded "Request: …\nResponse: …" entry back into its turns.
     *
     * @return array{0: string, 1: string} request text, response text (either may be empty)
     */
    private function splitTurn(string $entry): array
    {
        if (1 !== preg_match('/^Request:[ \t]*(.*?)(?:\nResponse:[ \t]*(.*))?$/s', $entry, $match)) {
            return ['', ''];
        }

        return [trim((string) $match[1]), trim((string) ($match[2] ?? ''))];
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
        return ApiSessionClient::label($client);
    }

    /**
     * Factual stand-in when no real summarizer model is configured.
     * Uses the person's own request text, never the dev stub's canned reply.
     *
     * @param list<string> $pending
     */
    private function plainExcerptSummary(array $pending): string
    {
        $bits = [];
        foreach ($pending as $entry) {
            [$request] = $this->splitTurn($entry);
            $text = trim(AnthropicContentText::humanText($request));
            if ('' !== $text) {
                $bits[] = $text;
            }
        }

        if ([] === $bits) {
            return '';
        }

        return $this->clip(implode(' ', $bits), self::SUMMARY_MAX_CHARS);
    }

    private function clip(string $value, int $maxChars): string
    {
        if (mb_strlen($value) <= $maxChars) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $maxChars)).'…';
    }
}
