<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Runner;

use App\Entity\Message;
use App\Service\Message\Handler\MediaGenerationHandler;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\TaskRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use Psr\Log\LoggerInterface;

/**
 * `image_generation` / `video_generation` runner — reuses the existing
 * {@see MediaGenerationHandler} (the v1 rule: no new generation code). It feeds
 * the handler a lightweight in-memory message carrying the resolved prompt plus
 * a mediamaker classification, and lifts the produced file out of the handler's
 * metadata into a node file descriptor.
 *
 * Model selection, provider calls, disk persistence and error handling all stay
 * inside MediaGenerationHandler (resolved for the message owner) — the migration
 * principle holds.
 */
final readonly class MediaGenerationRunner implements TaskRunner
{
    public function __construct(
        private MediaGenerationHandler $handler,
        private LoggerInterface $logger,
    ) {
    }

    public function supportedCapabilities(): array
    {
        return [Capability::ImageGeneration, Capability::VideoGeneration];
    }

    public function run(TaskNode $node, NodeContext $context): NodeResult
    {
        $inputs = $context->resolveInputs($node);
        $prompt = $this->stringInput($inputs['prompt'] ?? $inputs['text'] ?? null) ?? (string) $context->message->getText();
        if ('' === trim($prompt)) {
            return NodeResult::failed('no prompt for '.$node->capability->value);
        }

        $mediaType = Capability::VideoGeneration === $node->capability ? 'video' : 'image';
        $language = is_string($context->classification['language'] ?? null)
            ? $context->classification['language']
            : ($context->message->getLanguage() ?: 'en');

        $synthetic = $this->syntheticMessage($context, $prompt, $language);

        // Force the media type via the slash-command topic. MediaGenerationHandler
        // treats tools:pic/tools:vid as authoritative and skips its prompt-based
        // media-type guess — essential here because a multi-task prompt can be
        // ambiguous (e.g. "an image of a dog AND an mp3") and would otherwise be
        // undecidable.
        $classification = [
            'topic' => 'video' === $mediaType ? 'tools:vid' : 'tools:pic',
            'intent' => 'image_generation',
            'media_type' => $mediaType,
            'language' => $language,
        ];
        if ('video' === $mediaType) {
            if (isset($node->params['duration']) && is_numeric($node->params['duration'])) {
                $classification['duration'] = (int) $node->params['duration'];
            }
            if (isset($node->params['resolution']) && is_string($node->params['resolution'])) {
                $classification['resolution'] = $node->params['resolution'];
            }
        }

        try {
            // disable_memories: an intermediate generation node must not trigger
            // memory extraction on the synthetic prompt.
            $result = $this->handler->handle($synthetic, $context->thread, $classification, null, ['disable_memories' => true]);
        } catch (\Throwable $e) {
            $this->logger->warning('MediaGenerationRunner: handler threw', [
                'capability' => $node->capability->value,
                'error' => $e->getMessage(),
            ]);

            return NodeResult::failed($node->capability->value.' failed: '.$e->getMessage());
        }

        $metadata = $result['metadata'] ?? [];
        $file = $metadata['file'] ?? null;
        if (!is_array($file) || empty($file['path'])) {
            return NodeResult::failed($node->capability->value.' produced no file'.(isset($metadata['error']) ? ': '.$metadata['error'] : ''));
        }

        $descriptor = [
            'path' => $file['path'],
            'type' => $file['type'] ?? $mediaType,
            'local_path' => is_string($metadata['local_path'] ?? null) ? $metadata['local_path'] : null,
        ];

        return NodeResult::ok($result['content'] ?? null, [$descriptor], $metadata);
    }

    private function syntheticMessage(NodeContext $context, string $prompt, string $language): Message
    {
        $m = new Message();
        $m->setUserId((int) $context->message->getUserId());
        $m->setText($prompt);
        $m->setLanguage($language);
        $m->setDirection('IN');

        // Carry the inbound message's attachments over: MediaGenerationHandler
        // detects pic2pic (image edit with reference images) purely from the
        // files on the message it receives — without this copy, "edit this
        // image" silently degrades to plain text2pic. The synthetic message is
        // never persisted, so sharing the File entities is safe.
        $m->setFile($context->message->getFile());
        $m->setFilePath($context->message->getFilePath());
        $m->setFileType($context->message->getFileType());
        $m->setFileText($context->message->getFileText());
        foreach ($context->message->getFiles() as $file) {
            $m->addFile($file);
        }

        // MediaGenerationHandler uses the message id only to build the output
        // filename and requires a non-null int. This synthetic message is never
        // persisted, so assign a unique pseudo-id (reflection; entity has no
        // setId) to keep filenames unique across concurrent media nodes.
        $realId = $context->message->getId();
        try {
            $ref = new \ReflectionProperty(Message::class, 'id');
            $ref->setValue($m, $realId ?? random_int(1, 2_000_000_000));
        } catch (\Throwable) {
            // If reflection ever fails, the handler will surface a clear error.
        }

        return $m;
    }

    private function stringInput(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            $parts = array_filter($value, 'is_string');

            return [] === $parts ? null : implode("\n\n", $parts);
        }

        return null;
    }
}
