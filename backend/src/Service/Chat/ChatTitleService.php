<?php

declare(strict_types=1);

namespace App\Service\Chat;

use App\AI\Service\AiFacade;
use App\Entity\Chat;
use App\Entity\Message;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Generates short conversation titles.
 *
 * One place for both title consumers: widget sessions (which titled their
 * sessions here first) and web chats, whose sidebar previously showed the raw
 * truncated first user message and so filled up with "Hi" and "New Chat"
 * (#1500).
 *
 * The model comes from the SUMMARIZE capability (SUMMARIZE → SORT → CHAT), so
 * an operator can point titling at a cheap or local model; nothing here names a
 * model. Requests are deliberately bare — a single user turn, no system prompt,
 * no memories, no RAG context, no tools — because a title is not worth a full
 * chat turn's tokens.
 */
final readonly class ChatTitleService
{
    /**
     * BCHATS.BTITLE holds 255, but a sidebar entry is unreadable long before
     * that and models like to pad. Keep it to a label.
     */
    public const MAX_TITLE_LENGTH = 60;

    private const MAX_TURN_LENGTH = 200;
    private const MAX_TURNS = 8;
    private const HISTORY_MAX_MESSAGES = 20;
    private const HISTORY_MAX_CHARS = 50000;

    /**
     * Titles the frontend treats as "no title yet". Anything else may be a
     * rename by the user and is never replaced.
     */
    private const PLACEHOLDER_TITLES = ['New Chat', 'Neuer Chat'];

    public function __construct(
        private AiFacade $aiFacade,
        private ModelConfigService $modelConfigService,
        private MessageRepository $messageRepository,
        private UserRepository $userRepository,
        private RateLimitService $rateLimitService,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Title a web chat once its first exchange is on record.
     *
     * Returns the stored title so the caller can hand it to the client in the
     * same response, or null when the chat was left as it was — which is not an
     * error: an untitled chat falls back to the first-message preview and then
     * to the localized "New Chat" label in the sidebar.
     */
    public function titleWebChatIfNeeded(Chat $chat, int $userId): ?string
    {
        if (!$this->needsTitle($chat->getTitle())) {
            return null;
        }

        $messages = $this->messageRepository->findChatHistory(
            $userId,
            (int) $chat->getId(),
            self::HISTORY_MAX_MESSAGES,
            self::HISTORY_MAX_CHARS,
        );

        $turns = $this->toTurns($messages);

        // Wait for a complete exchange. A lone user message is what produced
        // the useless "Hi" titles; the answer is often where the subject is.
        if (count($turns) < 2) {
            return null;
        }

        $title = $this->generate($turns, $userId, 'CHAT_TITLE');

        if (null === $title) {
            return null;
        }

        // Another turn of the same chat may have won the race while the model
        // was answering. Re-read before writing; the first title wins.
        $this->em->refresh($chat);
        if (!$this->needsTitle($chat->getTitle())) {
            return null;
        }

        $chat->setTitle($title);
        $this->em->flush();

        $this->logger->info('ChatTitleService: generated web chat title', [
            'chat_id' => $chat->getId(),
            'title' => $title,
        ]);

        return $title;
    }

    /**
     * Turn a conversation into a label.
     *
     * @param list<array{role: string, text: string}> $turns
     * @param string                                  $usageAction rate-limit action the call is booked under
     */
    public function generate(array $turns, int $ownerId, string $usageAction): ?string
    {
        if ([] === $turns) {
            return null;
        }

        try {
            $summaryConfig = $this->modelConfigService->getSummaryModelConfig($ownerId);

            $aiOptions = ['temperature' => 0.3];
            if ($summaryConfig['provider'] && $summaryConfig['model']) {
                $aiOptions['provider'] = $summaryConfig['provider'];
                $aiOptions['model'] = $summaryConfig['model'];
            }

            $prompt = $this->buildPrompt($turns);

            $response = $this->aiFacade->chat(
                [['role' => 'user', 'content' => $prompt]],
                $ownerId,
                $aiOptions,
            );

            $title = $this->clean((string) ($response['content'] ?? ''));

            $owner = $this->userRepository->find($ownerId);
            if ($owner) {
                $this->rateLimitService->recordUsage($owner, $usageAction, [
                    'provider' => $response['provider'] ?? 'unknown',
                    'model' => $response['model'] ?? 'unknown',
                    'model_id' => $summaryConfig['model_id'],
                    'usage' => $response['usage'] ?? [],
                    'response_text' => $title ?? '',
                    'input_text' => $prompt,
                ]);
            }

            return $title;
        } catch (\Throwable $e) {
            // A missing title is cosmetic; the sidebar has a fallback. Never
            // let it disturb the turn that triggered it.
            $this->logger->warning('ChatTitleService: title generation failed', [
                'owner_id' => $ownerId,
                'action' => $usageAction,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param list<Message> $messages
     *
     * @return list<array{role: string, text: string}>
     */
    public function toTurns(array $messages): array
    {
        $turns = [];

        foreach ($messages as $message) {
            $text = trim($message->getText());
            if ('' === $text) {
                continue;
            }

            $turns[] = [
                'role' => 'IN' === $message->getDirection() ? 'user' : 'assistant',
                'text' => $text,
            ];
        }

        return $turns;
    }

    private function needsTitle(?string $title): bool
    {
        if (null === $title || '' === trim($title)) {
            return true;
        }

        return in_array(trim($title), self::PLACEHOLDER_TITLES, true);
    }

    /**
     * @param list<array{role: string, text: string}> $turns
     */
    private function buildPrompt(array $turns): string
    {
        $transcript = '';
        foreach (array_slice($turns, 0, self::MAX_TURNS) as $turn) {
            $label = 'user' === $turn['role'] ? 'User' : 'Assistant';
            $transcript .= $label.': '.mb_substr($turn['text'], 0, self::MAX_TURN_LENGTH)."\n";
        }

        return <<<PROMPT
            Read this conversation and write a short title (3-5 words) describing what it is about.
            Write the title in the language the conversation is in.
            Only output the title, nothing else. No quotes, no punctuation at the end.

            Conversation:
            {$transcript}

            Title:
            PROMPT;
    }

    private function clean(string $raw): ?string
    {
        // Models like to answer in prose or add a label; keep the first line.
        $title = trim(strtok($raw, "\n") ?: '');
        $title = trim($title, " \t\"'`*");
        $title = preg_replace('/^(?:title|titel)\s*:\s*/i', '', $title) ?? $title;
        $title = trim($title, " \t\"'`*");
        $title = rtrim($title, '.!?,;:');
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);

        if ('' === $title) {
            return null;
        }

        return mb_substr($title, 0, self::MAX_TITLE_LENGTH);
    }
}
