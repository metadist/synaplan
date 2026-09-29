<?php

declare(strict_types=1);

namespace App\Service\Telegram;

use App\Entity\Message;
use App\Entity\TelegramBot;
use App\Repository\MessageRepository;
use App\Repository\ModelRepository;
use App\Service\FeedbackExampleService;
use App\Service\Media\MediaJob;
use App\Service\Media\MediaJobCanceller;
use App\Service\Media\MediaJobService;
use Psr\Log\LoggerInterface;

/**
 * The buttons under bot replies. A button only acts on an answer of the
 * connected owner in this bot's chat; anything else is refused.
 */
final readonly class TelegramCallbackService
{
    private const MAX_MODELS = 8;

    public function __construct(
        private MessageRepository $messages,
        private ModelRepository $models,
        private TelegramConversation $conversation,
        private TelegramMessageStore $store,
        private TelegramState $state,
        private TelegramBotApi $api,
        private TelegramConnectionService $connections,
        private TelegramCopy $copy,
        private FeedbackExampleService $feedback,
        private MediaJobService $mediaJobs,
        private MediaJobCanceller $canceller,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $callback
     */
    public function handle(TelegramTurn $turn, array $callback, bool $fromOwner, bool $acknowledged): void
    {
        $callbackId = !$acknowledged && is_string($callback['id'] ?? null) ? $callback['id'] : '';
        $buttonMessageId = $callback['message']['message_id'] ?? null;
        $parsed = TelegramKeyboard::parse($callback['data'] ?? null);
        $answer = null !== $parsed && $fromOwner ? $this->answerFor($turn, $parsed['messageId']) : null;

        if (null === $parsed || null === $answer || !is_int($buttonMessageId)) {
            $this->acknowledge($turn, $callbackId, $this->say($turn, 'callback_not_allowed'));

            return;
        }

        match ($parsed['action']) {
            TelegramKeyboard::AGAIN => $this->again($turn, $callbackId, $answer, $this->previousModel($answer)),
            TelegramKeyboard::PICK_MODEL => $this->pickModel($turn, $callbackId, $answer, (int) $parsed['modelId']),
            TelegramKeyboard::MODELS => $this->showModels($turn, $callbackId, $answer, $buttonMessageId),
            TelegramKeyboard::BACK => $this->showActions($turn, $callbackId, $answer, $buttonMessageId),
            TelegramKeyboard::NOT_CORRECT => $this->askFeedback($turn, $callbackId, $answer),
            TelegramKeyboard::FEEDBACK_CANCEL => $this->cancelFeedback($turn, $callbackId, $buttonMessageId),
            TelegramKeyboard::CANCEL_JOB => $this->cancelJob($turn, $callbackId, $answer, $buttonMessageId),
            default => $this->acknowledge($turn, $callbackId, $this->say($turn, 'callback_not_allowed')),
        };
    }

    /**
     * Stops the button spinner while an earlier answer of this chat is still
     * running; Telegram drops the acknowledgement after a few seconds.
     *
     * @param array<string, mixed> $callback
     */
    public function acknowledgeEarly(TelegramBot $bot, array $callback): bool
    {
        $callbackId = is_string($callback['id'] ?? null) ? $callback['id'] : '';
        $token = '' !== $callbackId ? $this->connections->revealToken($bot) : null;
        if (null === $token) {
            return false;
        }
        try {
            $this->api->answerCallbackQuery($token, $callbackId, null);
        } catch (TelegramChannelException $e) {
            $this->conversation->noteDeliveryFailure($bot, $e);

            return false;
        }

        return true;
    }

    /**
     * The owner's reply to "What was wrong?" becomes a correction the AI
     * remembers.
     */
    public function saveFeedback(TelegramTurn $turn, string $text): bool
    {
        $pending = $this->state->pendingFeedback((int) $turn->bot->getId());
        if (null === $pending) {
            return false;
        }
        $this->state->clearFeedback((int) $turn->bot->getId());
        $this->removeButtons($turn, $pending['prompt']);

        $answer = $this->answerFor($turn, $pending['answer']);
        if (null === $answer) {
            return false;
        }
        try {
            $this->feedback->createFalsePositive($turn->owner, mb_substr($text, 0, 2000), (int) $answer->getId());
            $this->conversation->reply($turn, $this->say($turn, 'feedback_saved'));
        } catch (\Throwable $e) {
            $this->logger->warning('Telegram feedback could not be saved', [
                'message_id' => $answer->getId(),
                'exception_class' => $e::class,
            ]);
            $this->conversation->reply($turn, $this->say($turn, 'feedback_unavailable'));
        }

        return true;
    }

    private function answerFor(TelegramTurn $turn, int $messageId): ?Message
    {
        $answer = $this->messages->find($messageId);
        if (
            !$answer instanceof Message
            || $answer->getUserId() !== (int) $turn->owner->getId()
            || 'OUT' !== $answer->getDirection()
            || 'telegram' !== $answer->getMeta('channel')
            || $turn->tgChatId !== $answer->getMeta('tg_chat_id')
        ) {
            return null;
        }

        return $answer;
    }

    private function inboundFor(TelegramTurn $turn, Message $answer): ?Message
    {
        $inboundId = (int) $answer->getMeta(TelegramMessageStore::META_REPLY_TO, '0');
        $inbound = $inboundId > 0 ? $this->messages->find($inboundId) : null;
        if (!$inbound instanceof Message || $inbound->getUserId() !== (int) $turn->owner->getId() || 'IN' !== $inbound->getDirection()) {
            return null;
        }

        return $inbound;
    }

    private function again(TelegramTurn $turn, string $callbackId, Message $answer, ?int $modelId): void
    {
        $inbound = $this->inboundFor($turn, $answer);
        if (null === $inbound) {
            $this->acknowledge($turn, $callbackId, $this->say($turn, 'callback_not_allowed'));

            return;
        }
        $this->acknowledge($turn, $callbackId, null);

        $current = null === $answer->getMeta(TelegramMessageStore::META_SUPERSEDED) ? $answer : null;
        $options = null !== $modelId ? ['model_id' => $modelId, 'is_again' => true] : [];
        $replyTo = null === $current ? ($this->store->deliveredIds($answer)[0] ?? null) : null;
        $this->conversation->regenerate($turn, $inbound, $current, $options, $replyTo);
    }

    private function pickModel(TelegramTurn $turn, string $callbackId, Message $answer, int $modelId): void
    {
        if (!array_key_exists($modelId, $this->chatModels())) {
            $this->acknowledge($turn, $callbackId, $this->say($turn, 'model_unavailable'));

            return;
        }
        $this->again($turn, $callbackId, $answer, $modelId);
    }

    private function showModels(TelegramTurn $turn, string $callbackId, Message $answer, int $buttonMessageId): void
    {
        $models = $this->chatModels();
        if ([] === $models) {
            $this->acknowledge($turn, $callbackId, $this->say($turn, 'model_unavailable'));

            return;
        }
        $this->acknowledge($turn, $callbackId, null);
        $keyboard = TelegramKeyboard::models((int) $answer->getId(), $models, $this->copy->labels($turn->locale));
        $this->editButtons($turn, $buttonMessageId, $keyboard);
    }

    private function showActions(TelegramTurn $turn, string $callbackId, Message $answer, int $buttonMessageId): void
    {
        $this->acknowledge($turn, $callbackId, null);
        $keyboard = TelegramKeyboard::actions((int) $answer->getId(), $this->copy->labels($turn->locale));
        $this->editButtons($turn, $buttonMessageId, $keyboard);
    }

    private function askFeedback(TelegramTurn $turn, string $callbackId, Message $answer): void
    {
        $this->acknowledge($turn, $callbackId, null);
        $botRowId = (int) $turn->bot->getId();
        $previous = $this->state->pendingFeedback($botRowId);
        if (null !== $previous) {
            $this->removeButtons($turn, $previous['prompt']);
        }
        $keyboard = TelegramKeyboard::cancelFeedback((int) $answer->getId(), $this->copy->labels($turn->locale));
        $sent = $this->conversation->reply($turn, $this->say($turn, 'feedback_ask'), $keyboard);
        if ([] !== $sent) {
            $this->state->askFeedback($botRowId, (int) $answer->getId(), $sent[count($sent) - 1]);
        }
    }

    private function cancelFeedback(TelegramTurn $turn, string $callbackId, int $buttonMessageId): void
    {
        $this->state->clearFeedback((int) $turn->bot->getId());
        $this->removeButtons($turn, $buttonMessageId);
        $this->acknowledge($turn, $callbackId, $this->say($turn, 'feedback_cancelled'));
    }

    private function cancelJob(TelegramTurn $turn, string $callbackId, Message $answer, int $buttonMessageId): void
    {
        $answerId = (int) $answer->getId();
        $running = array_filter(
            $this->mediaJobs->findByMessage($answerId),
            static fn (MediaJob $job): bool => !$job->isTerminal()
                && $job->getMessageId() === $answerId
                && $job->getUserId() === $answer->getUserId(),
        );
        if ([] === $running) {
            $this->removeButtons($turn, $buttonMessageId);
            $this->acknowledge($turn, $callbackId, $this->say($turn, 'media_already_done'));

            return;
        }
        $this->acknowledge($turn, $callbackId, null);
        foreach ($running as $job) {
            $this->canceller->cancel($job);
        }
    }

    private function previousModel(Message $answer): ?int
    {
        $modelId = (int) $answer->getMeta('ai_chat_model_id', '0');

        return $modelId > 0 && array_key_exists($modelId, $this->chatModels()) ? $modelId : null;
    }

    /**
     * @return array<int, string>
     */
    private function chatModels(): array
    {
        $models = [];
        foreach ($this->models->findByTag('chat', true) as $model) {
            if (1 !== $model->getActive() || null === $model->getId()) {
                continue;
            }
            $models[$model->getId()] = $model->getName();
            if (count($models) >= self::MAX_MODELS) {
                break;
            }
        }

        return $models;
    }

    /**
     * @param array<string, mixed>|null $keyboard
     */
    private function editButtons(TelegramTurn $turn, int $messageId, ?array $keyboard): void
    {
        try {
            $this->api->editMessageReplyMarkup($turn->token, $turn->tgChatId, $messageId, $keyboard);
        } catch (TelegramChannelException $e) {
            $this->conversation->noteDeliveryFailure($turn->bot, $e);
        }
    }

    private function removeButtons(TelegramTurn $turn, int $messageId): void
    {
        $this->editButtons($turn, $messageId, null);
    }

    /**
     * Telegram shows a spinner on the button until this is answered.
     */
    private function acknowledge(TelegramTurn $turn, string $callbackId, ?string $text): void
    {
        if ('' === $callbackId) {
            return;
        }
        try {
            $this->api->answerCallbackQuery($turn->token, $callbackId, $text);
        } catch (TelegramChannelException $e) {
            $this->conversation->noteDeliveryFailure($turn->bot, $e);
        }
    }

    /**
     * @param array<string, string|int> $params
     */
    private function say(TelegramTurn $turn, string $key, array $params = []): string
    {
        return $this->copy->say($turn->locale, $key, $params);
    }
}
