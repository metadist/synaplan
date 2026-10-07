<?php

declare(strict_types=1);

namespace App\Service\Telegram;

use App\Entity\Chat;
use App\Entity\File;
use App\Entity\Message;
use App\Service\SelfAware\Docs\PlatformDocReferenceResolver;
use App\Service\Usage\RecordedUsage;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Stores the Telegram chat and its messages with the same metadata the web
 * chat and WhatsApp write, plus what the bot needs to edit its replies.
 */
final readonly class TelegramMessageStore
{
    public const META_UPDATE = 'tg_update';
    public const META_REPLY_TO = 'tg_reply_to';
    public const META_DELIVERED = 'tg_message_id';
    public const META_TEXT_ONLY = 'tg_text_only';
    public const META_PAYLOAD = 'tg_payload';
    public const META_SUPERSEDED = 'superseded_by';
    public const META_EDITED_FROM = 'edited_from';

    public function __construct(
        private EntityManagerInterface $em,
        private TelegramConnectionService $connections,
    ) {
    }

    public function chatFor(TelegramTurn $turn): Chat
    {
        $bot = $turn->bot;
        $title = 'Telegram: @'.$bot->getBotUsername();
        $chatId = $bot->getChatId();
        if (null !== $chatId) {
            $existing = $this->em->find(Chat::class, $chatId);
            if (
                $existing instanceof Chat
                && $existing->getUserId() === (int) $turn->owner->getId()
                && 'telegram' === $existing->getSource()
            ) {
                if ($existing->getTitle() !== $title) {
                    $existing->setTitle($title);
                    $this->em->flush();
                }

                return $existing;
            }
        }

        $chat = new Chat();
        $chat->setUserId((int) $turn->owner->getId());
        $chat->setTitle($title);
        $chat->setSource('telegram');
        $this->em->persist($chat);
        $this->em->flush();
        $id = $chat->getId();
        if (null === $id) {
            throw new \RuntimeException('Telegram chat persist did not assign an id');
        }
        $this->connections->attachChat($bot, $id);

        return $chat;
    }

    /**
     * @param array<string, mixed>  $classification
     * @param array<string, string> $meta
     * @param list<File>            $attachments    generated documents for this answer
     */
    public function store(
        TelegramTurn $turn,
        Chat $chat,
        string $text,
        string $direction,
        string $status,
        ?string $externalId,
        array $classification = [],
        ?TelegramOutgoingFile $file = null,
        array $meta = [],
        array $attachments = [],
    ): Message {
        $now = time();
        $message = new Message();
        $message->setUserId((int) $turn->owner->getId());
        $message->setChat($chat);
        $message->setTrackingId($now);
        $message->setProviderIndex('TELEGRAM');
        $message->setUnixTimestamp($now);
        $message->setDateTime(date('YmdHis', $now));
        $message->setMessageType('TGRM');
        // Pictures, video and audio use the legacy file columns the web chat
        // renders as media. Documents ride the File relation only, same as
        // the web stream, so a docx is not shown twice.
        if (null !== $file && TelegramOutgoingFile::DOCUMENT !== $file->type) {
            $message->setFile(1);
            $message->setFilePath($file->relativePath());
            $message->setFileType($file->type);
        } else {
            $message->setFile(0);
        }
        foreach ($attachments as $attachment) {
            $message->addFile($attachment);
        }
        $message->setTopic('CHAT');
        $message->setLanguage($turn->locale);
        $this->applyClassification($message, $classification);
        $message->setText($text);
        $message->setDirection($direction);
        $message->setStatus($status);

        // The row and its update marker land together, so a retried job
        // either finds the marker or finds nothing stored at all.
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $this->em->persist($message);
            $this->em->flush();
            $message->setMeta('channel', 'telegram');
            if (null !== $externalId && '' !== $externalId) {
                $message->setMeta('external_id', $externalId);
            }
            $message->setMeta('tg_chat_id', $turn->tgChatId);
            if ('IN' === $direction) {
                $message->setMeta(self::META_UPDATE, $turn->updateKey);
            }
            foreach ($meta as $key => $value) {
                $message->setMeta($key, $value);
            }
            $chat->updateTimestamp();
            $this->em->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        return $message;
    }

    public function setStatus(Message $message, string $status): void
    {
        $message->setStatus($status);
        $this->em->flush();
    }

    /**
     * @param array<string, mixed> $classification
     */
    public function applyClassification(Message $message, array $classification): void
    {
        $topic = $classification['topic'] ?? null;
        $language = $classification['language'] ?? null;
        if (is_string($topic) && '' !== $topic) {
            $message->setTopic($topic);
        }
        if (is_string($language) && '' !== $language) {
            $message->setLanguage($language);
        }
    }

    /**
     * Same keys as the web and WhatsApp replies, so the chat shows which
     * models produced the answer and which sources it used.
     *
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $search
     */
    public function storeAnswerMeta(Message $outbound, array $metadata, array $classification, ?RecordedUsage $recorded, array $search): void
    {
        $outbound->setMeta('ai_chat_provider', (string) ($metadata['provider'] ?? 'unknown'));
        $outbound->setMeta('ai_chat_model', (string) ($metadata['model'] ?? 'unknown'));
        if (!empty($metadata['model_id']) && is_scalar($metadata['model_id'])) {
            $outbound->setMeta('ai_chat_model_id', (string) $metadata['model_id']);
        }
        if (!empty($metadata['usage']) && is_array($metadata['usage'])) {
            $outbound->setMeta('ai_chat_usage', (string) json_encode($metadata['usage']));
        }
        if (null !== $recorded) {
            $recorded->attachChatCost($outbound);
        }
        foreach (['sorting_provider' => 'ai_sorting_provider', 'sorting_model_name' => 'ai_sorting_model', 'sorting_model_id' => 'ai_sorting_model_id'] as $source => $key) {
            $value = $classification[$source] ?? null;
            if (!empty($value) && is_scalar($value)) {
                $outbound->setMeta($key, (string) $value);
            }
        }
        $results = $search['results'] ?? null;
        if (is_array($results) && [] !== $results) {
            $outbound->setMeta('web_search_query', (string) ($search['query'] ?? ''));
            $outbound->setMeta('web_search_results_count', (string) count($results));
        }
        foreach (['media_job' => 'media_job', 'task_plan_render' => 'task_plan'] as $source => $key) {
            if (is_array($metadata[$source] ?? null) && [] !== $metadata[$source]) {
                $outbound->setMeta($key, (string) json_encode($metadata[$source], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
            }
        }
        foreach (['media_prompt', 'media_type'] as $key) {
            if (is_string($metadata[$key] ?? null) && '' !== $metadata[$key]) {
                $outbound->setMeta($key, $metadata[$key]);
            }
        }
        $docs = PlatformDocReferenceResolver::encodeDocsMeta($metadata);
        if (null !== $docs) {
            $outbound->setMeta('docs', $docs);
        }
        $this->em->flush();
    }

    public function recordDelivery(Message $outbound, TelegramDelivery $delivery): void
    {
        if ([] === $delivery->messageIds) {
            return;
        }
        $outbound->setMeta(self::META_DELIVERED, (string) json_encode($delivery->messageIds));
        $outbound->setMeta(self::META_TEXT_ONLY, $delivery->textOnly ? '1' : '0');
        $this->em->flush();
    }

    /**
     * @return list<int>
     */
    public function deliveredIds(Message $message): array
    {
        $decoded = json_decode((string) $message->getMeta(self::META_DELIVERED, '[]'), true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, is_int(...)));
    }

    public function supersede(Message $previous, Message $replacement): void
    {
        $previous->setMeta(self::META_SUPERSEDED, (string) $replacement->getId());
        $this->em->flush();
    }

    /**
     * Keeps every earlier wording of an edited message.
     */
    public function recordEdit(Message $inbound, string $newText): void
    {
        $history = json_decode((string) $inbound->getMeta(self::META_EDITED_FROM, '[]'), true);
        $history = is_array($history) ? $history : [];
        $history[] = ['text' => $inbound->getText(), 'at' => time()];
        $inbound->setMeta(self::META_EDITED_FROM, (string) json_encode($history));
        $inbound->setText($newText);
        $this->em->flush();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updatePayload(Message $inbound, array $payload): void
    {
        $inbound->setMeta(self::META_PAYLOAD, (string) json_encode($payload));
        $this->em->flush();
    }
}
