<?php

declare(strict_types=1);

namespace App\Service\Email\Turn;

use App\Entity\Message;
use App\Service\Chat\ConversationSummaryRefreshDispatcher;
use App\Service\Media\GeneratedFileMetadataNormalizer;
use App\Service\RateLimitService;
use App\Service\SelfAware\Docs\PlatformDocReferenceResolver;
use App\Service\Usage\FacadeMeteredChat;
use App\Service\Usage\RecordedUsage;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Keeps a finished email answer: the account's usage (the answer, and the
 * media it made), the inbound message's topic and language, and the answer
 * itself with its generated media (#626), provider, model, usage and cost,
 * doc references and every extra usage of the turn (sorting, planning,
 * transcription). The chat summary is refreshed afterwards.
 */
final readonly class EmailAnswerStore
{
    private const MEDIA_ACTIONS = ['image' => 'IMAGES', 'video' => 'VIDEOS', 'audio' => 'AUDIOS'];

    public function __construct(
        private RateLimitService $rateLimits,
        private GeneratedFileMetadataNormalizer $generatedFiles,
        private ConversationSummaryRefreshDispatcher $summaries,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata the processor's response metadata
     *
     * @return RecordedUsage the answer's usage, whose cost the stored answer carries
     */
    public function recordUsage(InboundEmail $email, StoredInboundEmail $stored, string $answer, array $metadata, float $processingTime): RecordedUsage
    {
        $usage = $this->rateLimits->recordUsage($email->user, 'MESSAGES', FacadeMeteredChat::messages(
            $metadata,
            'EMAIL',
            $stored->message->getText(),
            $answer,
            ['latency' => (int) ($processingTime * 1000)],
        ));

        $mediaType = $metadata['media_type'] ?? null;
        $mediaAction = is_string($mediaType) ? (self::MEDIA_ACTIONS[$mediaType] ?? null) : null;
        if (null !== $mediaAction) {
            $this->rateLimits->recordUsage($email->user, $mediaAction, [
                'provider' => $metadata['provider'] ?? 'unknown',
                'model' => $metadata['model'] ?? 'unknown',
                'model_id' => $metadata['model_id'] ?? null,
                'source' => 'EMAIL',
                'media_usage' => $metadata['media_usage'] ?? [],
            ]);
        }

        return $usage;
    }

    /**
     * @param array<string, mixed> $result the processor's result
     */
    public function store(InboundEmail $email, StoredInboundEmail $stored, string $answer, array $result, RecordedUsage $usage): void
    {
        $classification = $result['classification'] ?? [];
        $metadata = $result['response']['metadata'] ?? [];
        $topic = (string) ($classification['topic'] ?? 'CHAT');
        $language = (string) ($classification['language'] ?? 'en');

        $stored->message->setTopic($topic);
        $stored->message->setLanguage($language);
        $stored->message->setStatus('complete');

        $generatedFile = $this->generatedFiles->normalize($metadata['file'] ?? null);
        $outgoing = new Message();
        $outgoing->setUserId($email->user->getId());
        $outgoing->setChat($stored->chat);
        $outgoing->setTrackingId($stored->message->getTrackingId());
        $outgoing->setProviderIndex('EMAIL');
        $outgoing->setUnixTimestamp(time());
        $outgoing->setDateTime(date('YmdHis'));
        $outgoing->setMessageType('MAIL');
        $outgoing->setFile(null !== $generatedFile ? 1 : 0);
        $outgoing->setFilePath($generatedFile['path'] ?? '');
        $outgoing->setFileType($generatedFile['type'] ?? '');
        $outgoing->setTopic($topic);
        $outgoing->setLanguage($language);
        $outgoing->setText($answer);
        $outgoing->setDirection('OUT');
        $outgoing->setStatus('complete');
        $this->em->persist($outgoing);
        $this->em->flush();

        $outgoing->setMeta('ai_chat_provider', (string) ($metadata['provider'] ?? 'unknown'));
        $outgoing->setMeta('ai_chat_model', (string) ($metadata['model'] ?? 'unknown'));
        if (!empty($metadata['usage'])) {
            $outgoing->setMeta('ai_chat_usage', (string) json_encode($metadata['usage']));
        }
        if (!empty($metadata['model_id'])) {
            $outgoing->setMeta('ai_chat_model_id', (string) $metadata['model_id']);
        }
        $charged = FacadeMeteredChat::chargedCost($metadata);
        if (null !== $charged) {
            $outgoing->setMeta('ai_chat_cost', $charged);
        } else {
            $usage->attachChatCost($outgoing);
        }
        $docs = PlatformDocReferenceResolver::encodeDocsMeta($metadata);
        if (null !== $docs) {
            $outgoing->setMeta('docs', $docs);
        }
        $extra = self::extraUsage($classification, $metadata, $stored->message);
        if ([] !== $extra) {
            $outgoing->setMeta('ai_usage_extra', (string) json_encode($extra));
        }

        $stored->chat->updateTimestamp();
        $this->em->flush();

        if (null !== $stored->chat->getId()) {
            $this->summaries->dispatch((int) $stored->chat->getId(), (int) $email->user->getId());
        }
    }

    /**
     * The turn's usage besides the answer: the sorter, the planner, and the
     * transcription of a voice attachment.
     *
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $metadata
     *
     * @return list<mixed>
     */
    private static function extraUsage(array $classification, array $metadata, Message $message): array
    {
        $extra = [];
        if (is_array($classification['sorting_usage'] ?? null)) {
            $extra[] = RecordedUsage::fromSortingUsage(
                $classification['sorting_usage'],
                isset($classification['sorting_provider']) ? (string) $classification['sorting_provider'] : null,
                isset($classification['sorting_model_name']) ? (string) $classification['sorting_model_name'] : null,
            );
        }
        if (is_array($metadata['planning_usage'] ?? null)) {
            $extra[] = $metadata['planning_usage'];
        }
        $transcription = $message->getMeta('ai_transcription_usage');
        if (null !== $transcription && '' !== $transcription) {
            $decoded = json_decode($transcription, true);
            if (is_array($decoded)) {
                $extra[] = $decoded;
            }
        }

        return $extra;
    }
}
