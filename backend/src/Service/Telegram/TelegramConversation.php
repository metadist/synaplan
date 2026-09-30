<?php

declare(strict_types=1);

namespace App\Service\Telegram;

use App\Entity\Chat;
use App\Entity\File;
use App\Entity\Message;
use App\Entity\TelegramBot;
use App\Realtime\Notifier\ChatActivityNotifier;
use App\Service\Digest\MessageReferenceResolver;
use App\Service\Media\MediaJobMessageSync;
use App\Service\Media\MediaJobService;
use App\Service\Message\ChatErrorPresenter;
use App\Service\Message\MessagePreProcessor;
use App\Service\Message\MessageProcessor;
use App\Service\RateLimitService;
use App\Service\SelfAware\Docs\PlatformDocReferenceResolver;
use App\Service\Usage\RecordedUsage;
use App\Service\UserMemoryService;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * One chat turn in Telegram, with the same pipeline as the web chat: files
 * are attached and analysed, generated files are uploaded, async renders are
 * announced and delivered later, and every reply carries its buttons.
 */
final readonly class TelegramConversation
{
    /** Telegram lets bots edit their messages for 48 hours. */
    private const EDIT_WINDOW_SECONDS = 47 * 3600;
    private const MAX_SOURCES = 5;
    private const ACTIVE_JOB_STATES = ['queued', 'submitting', 'running', 'finalizing'];
    private const FILE_MARKER = '/^__FILE_GENERATED__:.*$/m';

    public function __construct(
        private TelegramMessageStore $store,
        private TelegramConnectionService $connections,
        private TelegramBotApi $api,
        private TelegramMediaSender $sender,
        private TelegramMediaDownloader $downloader,
        private TelegramCopy $copy,
        private MessagePreProcessor $preProcessor,
        private MessageProcessor $processor,
        private ChatErrorPresenter $errors,
        private RateLimitService $rateLimits,
        private UserMemoryService $memories,
        private MessageReferenceResolver $references,
        private ChatActivityNotifier $activity,
        private MediaJobService $mediaJobs,
        private MediaJobMessageSync $mediaJobSync,
        private LoggerInterface $logger,
        private PlatformDocReferenceResolver $docs,
        private ClockInterface $clock,
        private string $frontendUrl,
    ) {
    }

    /**
     * @param list<TelegramMediaRef>    $media
     * @param array<string, mixed>|null $payload
     */
    public function answer(TelegramTurn $turn, string $prompt, array $media, ?string $externalId, ?array $payload = null): void
    {
        $owner = $turn->owner;
        $chat = $this->store->chatFor($turn);
        $meta = null !== $payload ? [TelegramMessageStore::META_PAYLOAD => (string) json_encode($payload)] : [];

        $limit = $this->rateLimits->checkLimit($owner, 'MESSAGES');
        if (empty($limit['allowed'])) {
            $this->refuse($turn, $chat, $prompt, $externalId, 'limit_reached', $meta);

            return;
        }
        if ([] !== $media && empty($this->rateLimits->checkLimit($owner, 'FILE_ANALYSIS')['allowed'])) {
            $this->refuse($turn, $chat, $prompt, $externalId, 'file_limit_reached', $meta);

            return;
        }

        $inbound = $this->store->store($turn, $chat, $prompt, 'IN', 'processing', $externalId, [], null, $meta);
        $this->activity->publishActivity($chat, (int) $owner->getId(), 'IN', '' !== $prompt ? $prompt : '📎');
        $this->typing($turn, [] !== $media ? 'upload_document' : 'typing');

        // A retried job only resumes this row while it is still processing,
        // so every failure below must end the turn and tell the person.
        try {
            $notes = $this->attach($turn, $inbound, $media);
            if ([] !== $media && 0 === $inbound->getFiles()->count()) {
                $this->finish($turn, $chat, $inbound, implode("\n", $notes), 'failed');

                return;
            }
            if ($this->needsTranscript($media)) {
                $this->preProcessor->process($inbound);
                if ('' === trim($inbound->getText())) {
                    $this->finish($turn, $chat, $inbound, $this->say($turn, 'transcript_empty'), 'failed');

                    return;
                }
            }
            $this->respond($turn, $chat, $inbound, [], null, null, $notes);
        } catch (\Throwable $e) {
            $this->fail($turn, $chat, $inbound, $e);
        }
    }

    /**
     * Answers an existing inbound message again, e.g. after an edit or the
     * "Again" button. With $previous the old answer is replaced in place.
     *
     * @param array<string, mixed> $options
     */
    public function regenerate(TelegramTurn $turn, Message $inbound, ?Message $previous, array $options, ?int $replyTo): void
    {
        $chat = $inbound->getChat() ?? $this->store->chatFor($turn);
        if (empty($this->rateLimits->checkLimit($turn->owner, 'MESSAGES')['allowed'])) {
            $this->reply($turn, $this->say($turn, 'limit_reached'));

            return;
        }

        $this->store->setStatus($inbound, 'processing');
        $this->activity->publishActivity($chat, (int) $turn->owner->getId(), 'IN', $inbound->getText());
        $this->typing($turn, 'typing');
        try {
            $this->respond($turn, $chat, $inbound, $options, $previous, $replyTo, []);
        } catch (\Throwable $e) {
            $this->fail($turn, $chat, $inbound, $e);
        }
    }

    /**
     * Answers a message whose worker stopped before the turn ended (a
     * killed process), so it neither stays "processing" nor goes unanswered.
     * The limits were checked when the message arrived.
     */
    public function resume(TelegramTurn $turn, Message $inbound): void
    {
        $chat = $inbound->getChat() ?? $this->store->chatFor($turn);
        $externalId = (string) $inbound->getMeta('external_id', '');
        $replyTo = ctype_digit($externalId) ? (int) $externalId : null;
        $this->typing($turn, 'typing');
        try {
            $this->respond($turn, $chat, $inbound, [], null, $replyTo, []);
        } catch (\Throwable $e) {
            $this->fail($turn, $chat, $inbound, $e);
        }
    }

    /**
     * @param array<string, mixed>|null $keyboard
     *
     * @return list<int>
     */
    public function reply(TelegramTurn $turn, string $text, ?array $keyboard = null, ?int $replyTo = null): array
    {
        try {
            return $this->api->sendMessage($turn->token, $turn->tgChatId, $text, $keyboard, $replyTo);
        } catch (TelegramChannelException $e) {
            $this->noteDeliveryFailure($turn->bot, $e);

            return [];
        }
    }

    /**
     * @param array<string, string|int> $params
     */
    public function say(TelegramTurn $turn, string $key, array $params = []): string
    {
        return $this->copy->say($turn->locale, $key, $params);
    }

    /**
     * Only a revoked token or a blocked bot changes the card. A timeout or a
     * rate limit must not lock the owner out of the next message.
     */
    public function noteDeliveryFailure(TelegramBot $bot, TelegramChannelException $e): void
    {
        if (!in_array($e->errorCode, [
            TelegramChannelException::TOKEN_REVOKED,
            TelegramChannelException::BOT_BLOCKED,
        ], true)) {
            $this->logger->warning('Telegram delivery failed', [
                'bot_id' => $bot->getId(),
                'error' => $e->errorCode,
            ]);

            return;
        }
        $this->connections->markError($bot, $e->errorCode);
    }

    public function chatLink(?int $chatId): string
    {
        $base = rtrim($this->frontendUrl, '/');

        return null === $chatId || '' === $base ? $base : $base.'/?chat='.$chatId;
    }

    /**
     * @param array<string, string> $meta
     */
    private function refuse(TelegramTurn $turn, Chat $chat, string $prompt, ?string $externalId, string $key, array $meta): void
    {
        $sentence = $this->say($turn, $key);
        $inbound = $this->store->store($turn, $chat, $prompt, 'IN', 'complete', $externalId, [], null, $meta);
        $this->store->store($turn, $chat, $sentence, 'OUT', 'complete', null, [], null, [
            TelegramMessageStore::META_REPLY_TO => (string) $inbound->getId(),
        ]);
        $this->reply($turn, $sentence);
    }

    /**
     * @param list<TelegramMediaRef> $media
     *
     * @return list<string> one sentence per file that could not be attached
     */
    private function attach(TelegramTurn $turn, Message $inbound, array $media): array
    {
        $notes = [];
        foreach ($media as $ref) {
            try {
                $this->downloader->attach($turn->token, $turn->owner, $ref, $inbound);
            } catch (TelegramMediaRejected $e) {
                $notes[$e->reason] = $this->say($turn, $e->reason);
            }
        }

        return array_values($notes);
    }

    /**
     * @param list<TelegramMediaRef> $media
     */
    private function needsTranscript(array $media): bool
    {
        foreach ($media as $ref) {
            if ($ref->isSpoken()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string>         $notes
     */
    private function respond(TelegramTurn $turn, Chat $chat, Message $inbound, array $options, ?Message $previous, ?int $replyTo, array $notes): void
    {
        $owner = $turn->owner;
        $alreadyAttached = [];
        foreach ($inbound->getFiles() as $existing) {
            $alreadyAttached[spl_object_id($existing)] = true;
        }
        $pulse = $this->typingPulse($turn);
        $beat = static function () use ($pulse): void {
            $pulse->beat();
        };
        $result = $this->processor->process(
            $inbound,
            $options + ['heartbeat' => $beat],
            static function (array $status) use ($beat): void {
                $beat();
            },
        );

        if (empty($result['success'])) {
            $sentence = $this->errors->presentFromResult($result, $turn->locale, false)->userText;
            $this->finish($turn, $chat, $inbound, $sentence, 'failed', $previous, $replyTo);

            return;
        }

        $classification = is_array($result['classification'] ?? null) ? $result['classification'] : [];
        $response = is_array($result['response'] ?? null) ? $result['response'] : [];
        $metadata = is_array($response['metadata'] ?? null) ? $response['metadata'] : [];
        $search = is_array($result['search_results'] ?? null) ? $result['search_results'] : [];
        $files = TelegramOutgoingFile::fromMetadata($metadata);
        $jobs = $this->pendingJobs($metadata);

        $content = (string) ($response['content'] ?? '');
        $stored = trim($content);
        if ('' !== $stored) {
            $stored = $this->memories->resolveMemoryTags($stored, $owner);
            $stored = $this->references->resolveMessageTags($stored, $owner);
        }
        $channelReply = trim((string) (preg_replace(self::FILE_MARKER, '', $stored) ?? ''));

        $recorded = $this->recordUsage($turn, $inbound, $metadata, $channelReply);
        $this->store->applyClassification($inbound, $classification);
        $this->store->setStatus($inbound, 'complete');

        $generated = $this->claimGeneratedFiles($inbound, $alreadyAttached);
        $hasAnswer = '' !== $stored || [] !== $files || [] !== $jobs || [] !== $generated;
        $storedText = $hasAnswer ? $stored : $this->say($turn, 'empty_reply');
        $outbound = $this->store->store($turn, $chat, $storedText, 'OUT', 'complete', null, $classification, $files[0] ?? null, [
            TelegramMessageStore::META_REPLY_TO => (string) $inbound->getId(),
        ], $generated);
        $this->store->storeAnswerMeta($outbound, $metadata, $classification, $recorded, $search);
        $this->connections->noteExchange($turn->bot);

        $channelReply = '' !== $channelReply ? trim($this->docs->resolveDocTags($channelReply)) : '';
        $text = $this->withSources($turn, $channelReply, $search);
        if ([] !== $jobs) {
            $text = $this->join($text, $this->jobAck($turn, $jobs));
        }
        $text = $this->join($text, implode("\n", $notes));
        if ('' === $text && [] === $files) {
            $text = $this->say($turn, 'empty_reply');
        }

        $outId = (int) $outbound->getId();
        $labels = $this->copy->labels($turn->locale);
        $keyboard = [] !== $jobs ? TelegramKeyboard::cancelJob($outId, $labels) : TelegramKeyboard::actions($outId, $labels);
        $this->send($turn, $outbound, $text, $files, $previous, $replyTo, $keyboard);

        if (null !== $previous) {
            $this->store->supersede($previous, $outbound);
        }
        $this->bindJobs($jobs, $outId);
    }

    /**
     * Files created while answering (documents, exports, edits) start on the
     * inbound message. They belong to the answer, so the web chat and the
     * next turn see them there. Uploads the person sent stay on the inbound.
     *
     * @param array<int, true> $alreadyAttached spl_object_id of files present before processing
     *
     * @return list<File>
     */
    private function claimGeneratedFiles(Message $inbound, array $alreadyAttached): array
    {
        $claimed = [];
        foreach ($inbound->getFiles()->toArray() as $file) {
            if (isset($alreadyAttached[spl_object_id($file)])) {
                continue;
            }
            $inbound->removeFile($file);
            $claimed[] = $file;
        }

        return $claimed;
    }

    /**
     * Ends a turn that has no AI answer with one sentence.
     */
    private function finish(TelegramTurn $turn, Chat $chat, Message $inbound, string $sentence, string $status, ?Message $previous = null, ?int $replyTo = null): void
    {
        $this->store->setStatus($inbound, $status);
        $outbound = $this->store->store($turn, $chat, $sentence, 'OUT', 'complete', null, [], null, [
            TelegramMessageStore::META_REPLY_TO => (string) $inbound->getId(),
        ]);
        $this->connections->noteExchange($turn->bot);
        $this->send($turn, $outbound, $sentence, [], $previous, $replyTo, null);
        if (null !== $previous) {
            $this->store->supersede($previous, $outbound);
        }
    }

    private function fail(TelegramTurn $turn, Chat $chat, Message $inbound, \Throwable $e): void
    {
        $this->logger->error('Telegram message processing failed', [
            'message_id' => $inbound->getId(),
            'exception_class' => $e::class,
            'error' => $e->getMessage(),
        ]);
        $sentence = $this->say($turn, 'failed');
        try {
            $this->store->setStatus($inbound, 'failed');
            $this->store->store($turn, $chat, $sentence, 'OUT', 'complete', null, [], null, [
                TelegramMessageStore::META_REPLY_TO => (string) $inbound->getId(),
            ]);
        } catch (\Throwable $storeError) {
            $this->logger->error('Telegram failed turn could not be stored', [
                'message_id' => $inbound->getId(),
                'exception_class' => $storeError::class,
            ]);
        }
        $this->reply($turn, $sentence);
    }

    /**
     * A text-only answer is replaced in place while Telegram still allows
     * editing it; anything else is sent as a new message.
     *
     * @param list<TelegramOutgoingFile> $files
     * @param array<string, mixed>|null  $keyboard
     */
    private function send(TelegramTurn $turn, Message $outbound, string $text, array $files, ?Message $previous, ?int $replyTo, ?array $keyboard): void
    {
        try {
            $delivery = null;
            $previousIds = null !== $previous ? $this->store->deliveredIds($previous) : [];
            if (null !== $previous && [] === $files && 1 === count($previousIds)
                && '1' === $previous->getMeta(TelegramMessageStore::META_TEXT_ONLY)
                && time() - $previous->getUnixTimestamp() < self::EDIT_WINDOW_SECONDS
                && $this->api->editMessageText($turn->token, $turn->tgChatId, $previousIds[0], $text, $keyboard)
            ) {
                $delivery = new TelegramDelivery($previousIds, [], [], true);
            }
            if (null === $delivery) {
                if ([] !== $previousIds) {
                    $this->api->editMessageReplyMarkup($turn->token, $turn->tgChatId, $previousIds[array_key_last($previousIds)], null);
                }
                $delivery = $this->sender->deliver($turn->token, $turn->tgChatId, $text, $files, $keyboard, $replyTo);
            }
            $unsentKey = $delivery->unsentKey();
            if (null !== $unsentKey) {
                $sentence = $this->say($turn, $unsentKey, ['%link%' => $this->chatLink($outbound->getChatId())]);
                $noticeIds = $this->reply($turn, $sentence, [] === $delivery->messageIds ? $keyboard : null, [] === $delivery->messageIds ? $replyTo : null);
                if ([] === $delivery->messageIds) {
                    $delivery = new TelegramDelivery($noticeIds, $delivery->tooLarge, $delivery->failed, false);
                }
            }
            $this->store->recordDelivery($outbound, $delivery);
        } catch (TelegramChannelException $e) {
            $this->noteDeliveryFailure($turn->bot, $e);
        }
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function recordUsage(TelegramTurn $turn, Message $inbound, array $metadata, string $reply): ?RecordedUsage
    {
        try {
            return $this->rateLimits->recordUsage($turn->owner, 'MESSAGES', [
                'provider' => $metadata['provider'] ?? 'unknown',
                'model' => $metadata['model'] ?? 'unknown',
                'usage' => $metadata['usage'] ?? [],
                'model_id' => $metadata['model_id'] ?? null,
                'source' => 'TELEGRAM',
                'response_text' => $reply,
                'input_text' => $inbound->getText(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Telegram usage record failed', [
                'user_id' => $turn->owner->getId(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Renders that are still running when the answer is stored.
     *
     * @param array<string, mixed> $metadata
     *
     * @return list<array{key: string, type: string}>
     */
    private function pendingJobs(array $metadata): array
    {
        $jobs = [];
        $job = $metadata['media_job'] ?? null;
        if (is_array($job) && is_string($job['job_id'] ?? null) && '' !== $job['job_id']) {
            $state = is_string($job['state'] ?? null) ? $job['state'] : 'running';
            if (in_array($state, self::ACTIVE_JOB_STATES, true)) {
                $jobs[] = ['key' => $job['job_id'], 'type' => is_string($job['type'] ?? null) ? $job['type'] : ''];
            }
        }
        $cards = $metadata['task_plan_render']['cards'] ?? null;
        if (is_array($cards)) {
            foreach ($cards as $card) {
                $key = is_array($card) ? ($card['job_id'] ?? null) : null;
                if (is_string($key) && '' !== $key) {
                    $jobs[] = ['key' => $key, 'type' => ''];
                }
            }
        }

        return $jobs;
    }

    /**
     * The job was created for the inbound message. Once it points at the
     * answer, a job that already finished gets the sync it skipped, which
     * also delivers its file to Telegram.
     *
     * @param list<array{key: string, type: string}> $jobs
     */
    private function bindJobs(array $jobs, int $outboundId): void
    {
        foreach ($jobs as $job) {
            $rebound = $this->mediaJobs->rebindMessage($job['key'], $outboundId);
            if (null !== $rebound && $rebound->isTerminal()) {
                $this->mediaJobSync->syncTerminalState($rebound);
            }
        }
    }

    /**
     * @param list<array{key: string, type: string}> $jobs
     */
    private function jobAck(TelegramTurn $turn, array $jobs): string
    {
        if (1 === count($jobs) && in_array($jobs[0]['type'], ['image', 'video', 'audio'], true)) {
            return $this->say($turn, 'media_started_'.$jobs[0]['type']);
        }

        return $this->say($turn, 'media_started_many');
    }

    /**
     * @param array<string, mixed> $search
     */
    private function withSources(TelegramTurn $turn, string $reply, array $search): string
    {
        $results = $search['results'] ?? null;
        if (!is_array($results)) {
            return $reply;
        }
        $lines = [];
        foreach ($results as $result) {
            if (count($lines) >= self::MAX_SOURCES) {
                break;
            }
            $url = is_array($result) ? trim((string) ($result['url'] ?? '')) : '';
            if (!preg_match('#^https?://#i', $url)) {
                continue;
            }
            $title = trim((string) (preg_replace('/\s+/', ' ', (string) ($result['title'] ?? '')) ?? ''));
            $title = str_replace(['[', ']'], ['(', ')'], '' !== $title ? $title : $url);
            $href = str_replace([' ', '(', ')'], ['%20', '%28', '%29'], $url);
            $lines[] = (count($lines) + 1).'. ['.$title.']('.$href.')';
        }

        return [] === $lines ? $reply : $this->join($reply, $this->say($turn, 'sources')."\n".implode("\n", $lines));
    }

    private function join(string $text, string $addition): string
    {
        if ('' === $addition) {
            return $text;
        }

        return '' === $text ? $addition : $text."\n\n".$addition;
    }

    private function typing(TelegramTurn $turn, string $action): void
    {
        try {
            $this->api->sendChatAction($turn->token, $turn->tgChatId, $action);
        } catch (TelegramChannelException $e) {
            $this->noteDeliveryFailure($turn->bot, $e);
        }
    }

    /**
     * Repeats the typing action while tokens and status updates arrive.
     * The first action is already sent before processing starts.
     */
    private function typingPulse(TelegramTurn $turn): TelegramTypingPulse
    {
        return new TelegramTypingPulse(
            $this->clock,
            function () use ($turn): void {
                $this->api->sendChatAction($turn->token, $turn->tgChatId, 'typing');
            },
            function (TelegramChannelException $e) use ($turn): void {
                $this->noteDeliveryFailure($turn->bot, $e);
            },
        );
    }
}
