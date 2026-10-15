<?php

declare(strict_types=1);

namespace App\Service\Message\Handler;

use App\Entity\Message;
use App\Service\Message\FileAnalysis\AnalysisContext;
use App\Service\Message\FileAnalysis\AnalysisFileResolver;
use App\Service\Message\FileAnalysis\AnalysisReply;
use App\Service\Message\FileAnalysis\DocumentAnalysis;
use App\Service\Message\FileAnalysis\FileAnalysisFailureCopy;
use App\Service\Message\FileAnalysis\FileAnalysisRoute;
use App\Service\Message\FileAnalysis\ImageAnalysis;
use App\Service\Message\FileAnalysis\VoiceMessageReply;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Answers about the files of a turn: documents and videos through their
 * extracted text, voice messages conversationally, images with the vision
 * model. Buffered and streamed turns run the same path; only the reply's
 * transport differs ({@see AnalysisReply}).
 */
#[AutoconfigureTag('app.message.handler')]
final readonly class FileAnalysisHandler implements MessageHandlerInterface
{
    public function __construct(
        private AnalysisFileResolver $files,
        private DocumentAnalysis $documents,
        private VoiceMessageReply $voiceMessages,
        private ImageAnalysis $images,
        private FileAnalysisFailureCopy $failures,
        private LoggerInterface $logger,
    ) {
    }

    public function getName(): string
    {
        return 'file_analysis';
    }

    /**
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $options        accepted for the handler interface; file analysis reads none
     */
    public function handle(
        Message $message,
        array $thread,
        array $classification,
        ?callable $progressCallback = null,
        array $options = [],
    ): array {
        return $this->analyze(AnalysisContext::for($message, $classification, $progressCallback, AnalysisReply::buffered()), $thread);
    }

    /**
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $options
     */
    public function handleStream(
        Message $message,
        array $thread,
        array $classification,
        callable $streamCallback,
        ?callable $progressCallback = null,
        array $options = [],
    ): array {
        return $this->analyze(AnalysisContext::for($message, $classification, $progressCallback, AnalysisReply::streamed($streamCallback)), $thread);
    }

    /**
     * @param array<int, mixed> $thread
     *
     * @return array<string, mixed>
     */
    private function analyze(AnalysisContext $context, array $thread): array
    {
        $message = $context->message;
        $context->notify('analyzing', 'Analyzing file...');

        [$filesInfo, $fromConversation] = $this->files->resolve($message, $thread);
        if ([] === $filesInfo) {
            $this->logger->error('FileAnalysisHandler: No file found', [
                'message_id' => $message->getId(),
                'chat_id' => $message->getChatId(),
                'thread_count' => count($thread),
            ]);

            return $this->deliver($context, $this->failures->noFile());
        }

        if ($fromConversation) {
            $names = AnalysisFileResolver::describe($filesInfo);
            $context->notify('analyzing', 1 === count($filesInfo)
                ? 'Using '.$names.' from earlier in this chat...'
                : 'Using files from earlier in this chat: '.$names.'...');
        }

        $this->logFiles($message, $filesInfo, $fromConversation);

        $route = FileAnalysisRoute::of($filesInfo);
        $blocked = $this->failures->forBlockedRoute($route, $message);
        if (null !== $blocked) {
            return $this->deliver($context, $blocked);
        }

        switch ($route['kind']) {
            case 'documents':
                return $this->documents->analyze($context, $route['documents'] ?? []);
            case 'audio':
                return $this->voiceMessages->reply($context, $route['audio'] ?? []);
            case 'images':
                return $this->images->analyze($context, $route['images'] ?? []);
            default:
                $this->logger->warning('FileAnalysisHandler: Unsupported file types only', [
                    'message_id' => $message->getId(),
                    'file_types' => array_map(static fn (array $f): ?string => $f['type'], $filesInfo),
                ]);

                return $this->deliver($context, $this->failures->unsupported());
        }
    }

    /**
     * @param array{content: string, metadata: array<string, mixed>} $copy
     *
     * @return array<string, mixed>
     */
    private function deliver(AnalysisContext $context, array $copy): array
    {
        return $context->reply->deliver($copy['content'], $copy['metadata']);
    }

    /**
     * One log line that covers every attached file, so a dropped file shows
     * up in production logs.
     *
     * @param list<array<string, mixed>> $filesInfo
     */
    private function logFiles(Message $message, array $filesInfo, bool $fromConversation): void
    {
        $this->logger->info('FileAnalysisHandler: Processing file(s)', [
            'message_id' => $message->getId(),
            'from_conversation' => $fromConversation,
            'file_count' => count($filesInfo),
            'files' => array_map(static fn (array $info): array => [
                'id' => $info['id'] ?? null,
                'name' => $info['name'] ?? null,
                'type' => $info['type'] ?? null,
                'is_image' => $info['is_image'] ?? false,
                'is_audio' => $info['is_audio'] ?? false,
                'is_document' => $info['is_document'] ?? false,
                'is_video' => $info['is_video'] ?? false,
                'has_extracted_text' => '' !== trim((string) ($info['text'] ?? '')),
                'extracted_text_length' => strlen((string) ($info['text'] ?? '')),
                'status' => $info['status'] ?? null,
            ], $filesInfo),
        ]);
    }
}
