<?php

declare(strict_types=1);

namespace App\Service\Widget\Turn;

use App\Entity\Message;
use App\Service\Chat\ConversationSummaryRefreshDispatcher;
use App\Service\Media\GeneratedFileMetadataNormalizer;
use App\Service\Media\GeneratedFileRegistrar;
use App\Service\Message\AiResponseSanitizer;
use App\Service\RateLimitService;
use App\Service\Usage\FacadeMeteredChat;
use App\Service\Widget\WidgetRealtimeBroadcaster;
use App\Service\Widget\WidgetSessionService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Keeps a finished widget answer. The visitor's message takes the turn's
 * topic and language; the answer is stored with its knowledge sources and
 * generated media (attached, so the widget's history shows the player after
 * a reload); the session's preview follows it without the scratchpad, the
 * dashboard hears it live, and the chat summary, the session title and the
 * owner's usage are brought up to date.
 */
final readonly class WidgetAnswerStore
{
    private const TOPIC_COLUMN_LENGTH = 64;

    public function __construct(
        private WidgetSessionService $sessions,
        private WidgetRealtimeBroadcaster $broadcaster,
        private GeneratedFileMetadataNormalizer $generatedFiles,
        private GeneratedFileRegistrar $fileRegistrar,
        private ConversationSummaryRefreshDispatcher $summaries,
        private RateLimitService $rateLimits,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * The knowledge sources the answer cites, or null when it cites none.
     *
     * @param array<string, mixed> $metadata the processor's response metadata
     *
     * @return array<mixed>|null
     */
    public static function ragSources(array $metadata): ?array
    {
        $sources = $metadata['rag_sources'] ?? null;

        return is_array($sources) && [] !== $sources ? $sources : null;
    }

    /**
     * @param array<string, mixed> $result the processor's result
     */
    public function store(WidgetTurn $turn, StoredVisitorMessage $stored, string $answer, array $result): void
    {
        $metadata = $result['response']['metadata'] ?? [];
        $classification = $result['classification'] ?? [];
        $topic = mb_substr((string) ($classification['topic'] ?? 'WIDGET'), 0, self::TOPIC_COLUMN_LENGTH);
        // `auto` only steers the prompt; the column holds a language code.
        $language = (string) ($classification['language'] ?? 'en');
        $language = 'auto' === $language ? 'en' : $language;

        $stored->message->setTopic($topic);
        $stored->message->setLanguage($language);
        $stored->message->setStatus('complete');

        $outgoing = $this->answerMessage($turn, $stored, $answer, $metadata, $topic, $language);

        $session = $this->sessions->getOrCreateSession($turn->widget->getWidgetId(), $turn->session->getSessionId());
        $session->setLastMessage(time());
        $visibleAnswer = AiResponseSanitizer::stripForDisplay($answer);
        $session->setLastMessagePreview($visibleAnswer);
        $this->em->flush();

        $this->broadcaster->publishSessionEvent($turn->widget->getWidgetId(), $turn->session->getSessionId(), 'message', [
            'direction' => 'OUT',
            'text' => $visibleAnswer,
            'messageId' => $outgoing->getId(),
            'timestamp' => $outgoing->getUnixTimestamp(),
            'sender' => 'ai',
        ]);

        if (null !== $stored->chat->getId()) {
            $this->summaries->dispatch((int) $stored->chat->getId(), (int) $turn->owner->getId());
        }
        $this->sessions->generateTitleIfNeeded($session, $turn->owner->getId());

        \App\Service\Usage\LegacyUsageWriter::record($this->rateLimits, $turn->owner, 'MESSAGES', FacadeMeteredChat::messages(
            $metadata,
            'WIDGET',
            $stored->message->getText(),
            $answer,
            ['channel' => 'WIDGET', 'files' => $stored->fileIds],
        ));
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function answerMessage(WidgetTurn $turn, StoredVisitorMessage $stored, string $answer, array $metadata, string $topic, string $language): Message
    {
        $generatedFile = $this->generatedFiles->normalize($metadata['file'] ?? null);

        $outgoing = new Message();
        $outgoing->setUserId($turn->owner->getId());
        $outgoing->setChat($stored->chat);
        $outgoing->setTrackingId($stored->message->getTrackingId());
        $outgoing->setProviderIndex((string) ($metadata['provider'] ?? 'AI_WIDGET'));
        $outgoing->setUnixTimestamp(time());
        $outgoing->setDateTime(date('YmdHis'));
        $outgoing->setMessageType('WDGT');
        $outgoing->setFile(null !== $generatedFile ? 1 : 0);
        $outgoing->setFilePath($generatedFile['path'] ?? '');
        $outgoing->setFileType($generatedFile['type'] ?? '');
        $outgoing->setTopic($topic);
        $outgoing->setLanguage($language);
        $outgoing->setText($answer);
        $outgoing->setDirection('OUT');
        $outgoing->setStatus('complete');

        $sources = self::ragSources($metadata);
        $encodedSources = null !== $sources ? json_encode($sources, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) : false;
        if (is_string($encodedSources)) {
            $outgoing->setMeta('rag_sources', $encodedSources);
        }

        $this->em->persist($outgoing);
        $this->em->flush();

        if (null !== $generatedFile) {
            $localPath = $metadata['local_path'] ?? null;
            $file = $this->fileRegistrar->register($turn->owner->getId(), is_string($localPath) ? $localPath : null, $generatedFile['type'], $outgoing->getId());
            if (null !== $file) {
                $outgoing->addFile($file);
                $this->em->flush();
            }
        }

        return $outgoing;
    }
}
