<?php

declare(strict_types=1);

namespace App\Service\Chat\Stream;

use App\Entity\Prompt;
use App\Entity\User;
use App\Service\Billing\PremiumFeatureGate;
use App\Service\Message\Processing\TurnHistoryLoader;
use App\Service\Prompt\PromptService;
use App\Service\RateLimitService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns a stream request into a {@see StreamTurnRequest}.
 *
 * Both transports carry the same parameters: the GET variant in the query
 * string (EventSource cannot POST, and proxies reject long request lines
 * with 431/414), the POST variant in a JSON body whose values override the
 * query. Checks run in a fixed order — caller, pinned assistant, required
 * input, selected prompt — so a malformed request always gets the same
 * answer. A person over a limit still gets a turn: it only reports the limit
 * as an event, because EventSource cannot read a JSON error.
 */
final readonly class StreamRequestResolver
{
    /**
     * The quote travels as a query parameter on the GET stream URL, so an
     * over-long excerpt could exceed reverse-proxy request limits (Nginx's
     * 8 KB default). Kept in sync with MAX_QUOTE_LENGTH in
     * useMessageQuoting.ts.
     */
    private const MAX_QUOTED_TEXT_LENGTH = 1000;

    /**
     * The incognito history the client sends gets the same window as a
     * persisted chat ({@see TurnHistoryLoader::HISTORY_MAX_MESSAGES}), so a
     * crafted request cannot bloat the prompt.
     */
    private const MAX_INCOGNITO_HISTORY_MESSAGES = TurnHistoryLoader::HISTORY_MAX_MESSAGES;
    private const MAX_INCOGNITO_HISTORY_CHARS = TurnHistoryLoader::HISTORY_MAX_CHARS;

    private const REASONING_EFFORTS = ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'];

    public function __construct(
        private StreamCallerResolver $callers,
        private StreamAgentPinResolver $agents,
        private EntityManagerInterface $em,
        private PromptService $promptService,
        private RateLimitService $rateLimitService,
        private PremiumFeatureGate $premiumFeatureGate,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws StreamRequestRejected
     */
    public function resolve(Request $request, ?User $user): StreamTurnRequest
    {
        $params = self::streamParams($request);
        $caller = $this->callers->resolve($request, $params, $user);
        $user = $caller->user;

        $message = $params->get('message', '');
        $trackId = $params->get('trackId', time());
        $chatId = self::optionalString($params->get('chatId', null));
        $flags = self::flags($params);
        $modelId = self::optionalString($params->get('modelId', null));
        $fileIds = $params->get('fileIds', '');
        $promptTopic = $params->get('promptTopic');
        $promptId = $params->get('promptId');

        $agent = $this->agents->resolve(
            $user,
            $params->getInt('agentId') ?: null,
            self::isTruthyFlag($params->get('draft')),
            is_numeric($chatId) ? (int) $chatId : null,
        );

        // getString() answers array input with a clean 400; the empty string
        // means "no folder selected".
        $ragGroupKey = $params->getString('ragGroupKey') ?: null;
        $quotedText = self::quotedText($params);
        $quotedMessageId = $params->getInt('quotedMessageId') ?: null;
        $continueMessageId = self::optionalString($params->get('continueMessageId'));
        // Explicit opt-out from memory loading and extraction (public demo).
        $disableMemories = '1' === $params->get('disableMemories', '0');

        // Incognito keeps the turn in memory: nothing is stored, memories,
        // knowledge and feedback are still read. Signed-in web users only.
        $incognito = '1' === $params->get('incognito', '0') && !$caller->isWidget() && !$caller->isGuest();
        $history = $incognito ? self::incognitoHistory($request) : [];
        if ($incognito) {
            // Without stored rows there is nothing to continue and no source
            // message to resolve; the quoted text itself still works.
            $continueMessageId = null;
            $quotedMessageId = null;
        }

        $input = new StreamUserInput(
            message: (string) $message,
            fileIds: self::fileIds($fileIds),
            continueMessageId: $continueMessageId,
            // A falsy excerpt ("0") never counted as a quote.
            quote: $quotedText ? new StreamQuote($quotedText, $quotedMessageId) : null,
        );
        self::assertSendable($input, $chatId, $incognito);

        $fixedTaskPrompt = $caller->isWidget()
            ? $caller->widgetTaskPrompt
            : $this->selectedPromptTopic($promptId, $promptTopic, $user);

        $this->logger->info('StreamController: Received request', [
            'user_id' => $user->getId(),
            'chat_id' => $chatId,
            'has_model_id' => null !== $modelId,
            'model_id' => $modelId,
            'is_again' => $flags['isAgain'],
            'file_ids' => $input->fileIds,
            'file_count' => count($input->fileIds),
        ]);

        return new StreamTurnRequest(
            caller: $caller,
            chatId: $chatId,
            trackId: is_int($trackId) ? $trackId : (string) $trackId,
            input: $input,
            options: new StreamTurnOptions(
                reasoning: $flags['reasoning'],
                reasoningEffort: $flags['reasoningEffort'],
                webSearch: $flags['webSearch'],
                voiceReply: $flags['voiceReply'],
                disableMemories: $disableMemories,
                modelId: $modelId,
                isAgain: $flags['isAgain'],
                fixedTaskPromptTopic: $fixedTaskPrompt,
                ragGroupKey: $ragGroupKey,
                agent: $agent,
            ),
            incognito: $incognito,
            incognitoHistory: $history,
            rateLimitError: $caller->isWidget() || $caller->isGuest() ? null : $this->rateLimitError($user),
        );
    }

    /**
     * Query parameters merged with a JSON body. Body values override query
     * values; only scalars are accepted, and booleans become '1'/'0' so the
     * `'1' === …` flag checks work for JSON `true`.
     *
     * @return InputBag<bool|float|int|string>
     */
    private static function streamParams(Request $request): InputBag
    {
        $params = new InputBag($request->query->all());

        if (!$request->isMethod('POST')) {
            return $params;
        }

        $decoded = json_decode($request->getContent(), true);
        if (!is_array($decoded)) {
            return $params;
        }

        foreach ($decoded as $key => $value) {
            if (is_bool($value)) {
                $params->set((string) $key, $value ? '1' : '0');
            } elseif (is_scalar($value)) {
                $params->set((string) $key, (string) $value);
            }
        }

        return $params;
    }

    /**
     * @param InputBag<bool|float|int|string> $params
     *
     * @return array{reasoning: bool, reasoningEffort: string, webSearch: bool, voiceReply: bool, isAgain: bool}
     */
    private static function flags(InputBag $params): array
    {
        $reasoning = '1' === $params->get('reasoning', '0');
        $reasoningEffort = strtolower(trim((string) $params->get('reasoningEffort', '')));
        if (!in_array($reasoningEffort, self::REASONING_EFFORTS, true)) {
            $reasoningEffort = '';
        }
        $webSearch = '1' === $params->get('webSearch', '0');

        return [
            'reasoning' => $reasoning,
            'reasoningEffort' => $reasoningEffort,
            'webSearch' => $webSearch,
            'voiceReply' => '1' === $params->get('voiceReply', '0'),
            'isAgain' => '1' === $params->get('isAgain', '0'),
        ];
    }

    /**
     * Trimmed and capped server-side, so a crafted request cannot bloat the
     * stored meta or the prompt. getString() answers array input with a 400.
     *
     * @param InputBag<bool|float|int|string> $params
     */
    private static function quotedText(InputBag $params): ?string
    {
        $quotedText = trim($params->getString('quotedText'));
        if (mb_strlen($quotedText) > self::MAX_QUOTED_TEXT_LENGTH) {
            $quotedText = mb_substr($quotedText, 0, self::MAX_QUOTED_TEXT_LENGTH);
        }

        return '' !== $quotedText ? $quotedText : null;
    }

    /**
     * @return list<int>
     */
    private static function fileIds(mixed $fileIds): array
    {
        if (empty($fileIds)) {
            return [];
        }

        return array_values(array_map('intval', array_filter(explode(',', (string) $fileIds))));
    }

    private static function assertSendable(StreamUserInput $input, ?string $chatId, bool $incognito): void
    {
        if (empty($input->message) && empty($input->fileIds) && !$input->continueMessageId) {
            throw StreamRequestRejected::json(['error' => 'Message or file attachment is required'], Response::HTTP_BAD_REQUEST);
        }

        if (!$chatId && !$incognito) {
            throw StreamRequestRejected::json(['error' => 'Chat ID is required'], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * The prompt an API caller picked: `promptId` wins over `promptTopic`.
     */
    private function selectedPromptTopic(mixed $promptId, mixed $promptTopic, User $user): ?string
    {
        if ($promptId) {
            $prompt = $this->em->getRepository(Prompt::class)->find((int) $promptId);
            if (!$prompt) {
                throw StreamRequestRejected::json(['error' => 'PROMPT_NOT_FOUND', 'message' => 'No prompt found with ID '.$promptId], Response::HTTP_NOT_FOUND);
            }
            if (0 !== $prompt->getOwnerId() && $prompt->getOwnerId() !== $user->getId()) {
                throw StreamRequestRejected::json(['error' => 'ACCESS_DENIED', 'message' => 'You do not have access to this prompt'], Response::HTTP_FORBIDDEN);
            }

            return $prompt->getTopic();
        }

        if ($promptTopic) {
            $promptTopic = (string) $promptTopic;
            if (!$this->promptService->getPromptWithMetadata($promptTopic, $user->getId())) {
                throw StreamRequestRejected::json(['error' => 'PROMPT_NOT_FOUND', 'message' => 'No prompt found with topic "'.$promptTopic.'"'], Response::HTTP_BAD_REQUEST);
            }

            return $promptTopic;
        }

        return null;
    }

    /**
     * The message limit, then — when spend is capped — the cost budget.
     * `phone_verified` must reflect phone verification, not email: the limit
     * dialog offers "Verify phone" to anonymous users (#839).
     *
     * @return array<string, mixed>|null
     */
    private function rateLimitError(User $user): ?array
    {
        $rateLimitCheck = $this->rateLimitService->checkLimit($user, 'MESSAGES');
        if (!$rateLimitCheck['allowed']) {
            return [
                'status' => 'error',
                'error' => 'Rate limit exceeded',
                'limit_type' => $rateLimitCheck['limit_type'] ?? 'lifetime',
                'action_type' => 'MESSAGES',
                'limit' => $rateLimitCheck['limit'],
                'used' => $rateLimitCheck['used'],
                'remaining' => $rateLimitCheck['remaining'],
                'reset_at' => $rateLimitCheck['reset_at'] ?? null,
                'user_level' => $user->getUserLevel(),
                'phone_verified' => $user->hasVerifiedPhone(),
            ];
        }

        if (!$this->premiumFeatureGate->isCostBudgetEnforced()) {
            return null;
        }

        $budgetCheck = $this->rateLimitService->checkCostBudget($user);
        if ($budgetCheck['allowed']) {
            return null;
        }

        return [
            'status' => 'error',
            'error' => 'Cost budget exceeded',
            // Machine-readable, so the client never matches the translated text.
            'code' => 'COST_BUDGET_EXCEEDED',
            'limit_type' => 'monthly',
            'action_type' => 'MESSAGES',
            'limit' => $budgetCheck['budget'],
            'used' => $budgetCheck['used_cost'],
            'remaining' => $budgetCheck['remaining'],
            'topup_available' => true,
            'user_level' => $user->getUserLevel(),
            'phone_verified' => $user->hasVerifiedPhone(),
        ];
    }

    /**
     * The incognito history from the request, validated to `{role, content}`
     * pairs and capped like a stored chat window
     * ({@see \App\Repository\MessageRepository::findChatHistory()}). The POST
     * body carries it as an array, the GET variant as a JSON string.
     *
     * @return list<array{role: 'user'|'assistant', content: string}>
     */
    private static function incognitoHistory(Request $request): array
    {
        $entries = [];
        foreach (self::rawHistory($request) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $role = is_string($entry['role'] ?? null) ? strtolower(trim($entry['role'])) : '';
            $content = is_string($entry['content'] ?? null) ? trim($entry['content']) : '';
            if ('' === $content || !in_array($role, ['user', 'assistant'], true)) {
                continue;
            }
            $entries[] = ['role' => $role, 'content' => $content];
        }

        // Keep the NEWEST messages within the caps, then restore the order.
        $entries = array_slice($entries, -self::MAX_INCOGNITO_HISTORY_MESSAGES);
        $kept = [];
        $totalChars = 0;
        foreach (array_reverse($entries) as $entry) {
            $length = strlen($entry['content']);
            if ([] !== $kept && ($totalChars + $length) > self::MAX_INCOGNITO_HISTORY_CHARS) {
                break;
            }
            $kept[] = $entry;
            $totalChars += $length;
        }

        return array_reverse($kept);
    }

    /**
     * @return array<mixed>
     */
    private static function rawHistory(Request $request): array
    {
        if ($request->isMethod('POST')) {
            $decoded = json_decode($request->getContent(), true);
            if (is_array($decoded) && isset($decoded['history']) && is_array($decoded['history'])) {
                return $decoded['history'];
            }
        }

        $queryHistory = $request->query->get('history');
        if (is_string($queryHistory) && '' !== $queryHistory) {
            $decoded = json_decode($queryHistory, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private static function isTruthyFlag(mixed $value): bool
    {
        return true === $value || 1 === $value || '1' === (string) $value || 'true' === $value;
    }

    private static function optionalString(mixed $value): ?string
    {
        return null === $value ? null : (string) $value;
    }
}
