<?php

declare(strict_types=1);

namespace App\Service\Telegram;

use App\Entity\Message;
use App\Entity\TelegramBot;
use App\Entity\User;
use App\Repository\MessageRepository;
use App\Repository\TelegramBotRepository;
use App\Repository\UserRepository;
use App\Service\AccountLanguage;
use App\Service\Media\MediaJob;
use App\Service\Media\MediaJobService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Sends a finished render, or one sentence about why there is none, to the
 * Telegram chat whose answer announced it. Each job is delivered once.
 */
final readonly class TelegramMediaJobDelivery
{
    private const LOCK_SECONDS = 120.0;
    private const MEDIA_TYPES = ['image', 'video', 'audio'];

    public function __construct(
        private EntityManagerInterface $em,
        private MessageRepository $messages,
        private TelegramBotRepository $bots,
        private UserRepository $users,
        private TelegramConnectionService $connections,
        private TelegramMessageStore $store,
        private TelegramMediaSender $sender,
        private TelegramBotApi $api,
        private TelegramConversation $conversation,
        private TelegramCopy $copy,
        private MediaJobService $mediaJobs,
        private LockFactory $lockFactory,
        private LoggerInterface $logger,
    ) {
    }

    public function deliver(string $jobKey, int $messageId): void
    {
        $job = $this->mediaJobs->findByKey($jobKey);
        $answer = $this->messages->find($messageId);
        if (null === $job || !$job->isTerminal() || !$answer instanceof Message
            || $job->getMessageId() !== $messageId || $job->getUserId() !== $answer->getUserId()
            || 'telegram' !== $answer->getMeta('channel')) {
            return;
        }

        $marker = 'tg_job_'.substr(hash('sha256', $jobKey), 0, 32);
        $lock = $this->lockFactory->createLock('telegram_'.$marker, self::LOCK_SECONDS);
        if (!$lock->acquire()) {
            return;
        }
        try {
            $this->em->refresh($answer);
            if (null !== $answer->getMeta($marker)) {
                return;
            }
            $turn = $this->turnFor($answer);
            if (null === $turn) {
                return;
            }
            $this->send($turn, $job, $answer);
            $answer->setMeta($marker, $job->getStatus());
            $this->em->flush();
        } finally {
            $lock->release();
        }
    }

    private function send(TelegramTurn $turn, MediaJob $job, Message $answer): void
    {
        $answerId = (int) $answer->getId();
        $delivered = $this->store->deliveredIds($answer);
        $replyTo = $delivered[0] ?? null;
        $keyboard = TelegramKeyboard::actions($answerId, $this->copy->labels($turn->locale));
        $type = in_array($job->getType(), self::MEDIA_TYPES, true) ? $job->getType() : 'image';

        try {
            if ([] !== $delivered && !$this->othersRunning($job, $answerId)) {
                $this->api->editMessageReplyMarkup($turn->token, $turn->tgChatId, $delivered[array_key_last($delivered)], null);
            }

            $file = MediaJob::STATUS_COMPLETED === $job->getStatus() ? $this->resultFile($job) : null;
            if (null !== $file) {
                $delivery = $this->sender->deliver($turn->token, $turn->tgChatId, '', [$file], $keyboard, $replyTo);
                $unsentKey = $delivery->unsentKey();
                if (null !== $unsentKey) {
                    $link = $this->conversation->chatLink($answer->getChatId());
                    $this->conversation->reply($turn, $this->copy->say($turn->locale, $unsentKey, ['%link%' => $link]), $keyboard, $replyTo);
                }

                return;
            }

            $key = match ($job->getStatus()) {
                MediaJob::STATUS_CANCELLED => 'media_cancelled',
                default => 'media_failed_'.$type,
            };
            $this->api->sendMessage($turn->token, $turn->tgChatId, $this->copy->say($turn->locale, $key), $keyboard, $replyTo);
        } catch (TelegramChannelException $e) {
            $this->conversation->noteDeliveryFailure($turn->bot, $e);
        }
    }

    /**
     * The Cancel button stays on the answer while another of its renders runs.
     */
    private function othersRunning(MediaJob $job, int $answerId): bool
    {
        foreach ($this->mediaJobs->findByMessage($answerId) as $other) {
            if ($other->getJobKey() !== $job->getJobKey() && !$other->isTerminal()) {
                return true;
            }
        }

        return false;
    }

    private function resultFile(MediaJob $job): ?TelegramOutgoingFile
    {
        $result = $job->getResult();
        $url = is_array($result['file'] ?? null) ? ($result['file']['url'] ?? null) : null;
        if (!is_string($url) || '' === $url) {
            $this->logger->warning('Telegram media job finished without a file', ['job_key' => $job->getJobKey()]);

            return null;
        }

        return TelegramOutgoingFile::forPath($url, $job->getType());
    }

    /**
     * Only the bot that is still paired with the same Telegram chat gets the
     * file; a disconnected or re-paired bot gets nothing.
     */
    private function turnFor(Message $answer): ?TelegramTurn
    {
        $bot = $this->bots->findOneByOwner($answer->getUserId());
        $owner = $this->users->find($answer->getUserId());
        if (!$bot instanceof TelegramBot || !$owner instanceof User
            || TelegramBot::STATUS_CONNECTED !== $bot->getStatus()
            || null === $bot->getTgChatId()
            || $bot->getTgChatId() !== $answer->getMeta('tg_chat_id')) {
            return null;
        }
        $token = $this->connections->revealToken($bot);
        if (null === $token) {
            return null;
        }

        $locale = AccountLanguage::normalize($answer->getLanguage()) ?? $owner->getLocale();

        return new TelegramTurn($bot, $owner, $token, $bot->getTgChatId(), 'job', $locale);
    }
}
