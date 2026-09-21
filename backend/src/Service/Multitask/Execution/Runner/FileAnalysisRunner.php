<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Runner;

use App\Entity\File;
use App\Entity\Message;
use App\Repository\FileRepository;
use App\Service\File\ConversationFileCatalog;
use App\Service\Message\ChatErrorPresenter;
use App\Service\Message\Handler\FileAnalysisHandler;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\TaskRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Multitask\Skill\SkillDescriptor;
use Psr\Log\LoggerInterface;

/**
 * `file_analysis` runner — answers a question about an attached file by reusing
 * the existing {@see FileAnalysisHandler} (vision models for images, chat models
 * over Tika/Whisper-extracted text for documents/audio). No new inference code.
 *
 * Tika/Whisper/vision extraction already ran in MessagePreProcessor before
 * routing, so the attachments carry their extracted text + on-disk paths; the
 * handler picks the right route (documents / audio / images) itself.
 *
 * A synthetic message carries the node's scoped question plus the inbound
 * attachments (so a multi-step prompt's file_analysis clause is answered in
 * isolation), and the handler's answer is lifted into the node result.
 */
final readonly class FileAnalysisRunner implements TaskRunner
{
    public function __construct(
        private FileAnalysisHandler $handler,
        private LoggerInterface $logger,
        private ?ConversationFileCatalog $conversationFiles = null,
        private ?FileRepository $fileRepository = null,
        private ?ChatErrorPresenter $errorPresenter = null,
    ) {
    }

    public function supportedCapabilities(): array
    {
        return [Capability::FileAnalysis];
    }

    /**
     * @return list<SkillDescriptor>
     */
    public function describe(): array
    {
        return [
            new SkillDescriptor(Capability::FileAnalysis, 'Analyze/describe/OCR an image or document — attached now, already present in this conversation, or produced by a prior node ($nX.file) — and answer about it. Plan this even when the current turn has no new attachment if the user asks about a file listed in the conversation.'),
        ];
    }

    public function run(TaskNode $node, NodeContext $context): NodeResult
    {
        $inputs = $context->resolveInputs($node);
        $prompt = $this->stringInput($inputs['prompt'] ?? $inputs['text'] ?? null);

        $synthetic = $this->syntheticMessage($context, $prompt);

        // Issue #1080: a DAG can chain image_generation → file_analysis →
        // text2sound, so the file to analyze may be produced by an UPSTREAM node
        // (referenced as `$nX.file` / `$nX.files` in this node's inputs) rather
        // than uploaded by the user. The inbound message carries no attachment in
        // that case, so without lifting the resolved upstream file the runner
        // fails with "no file to analyze" and the downstream node cascades to
        // "skipped". When the inbound message already carries its own files the
        // user's upload wins; otherwise fall back to the upstream-generated file.
        if ($synthetic->getFiles()->isEmpty() && 0 === $synthetic->getFile()) {
            $this->attachUpstreamFile($synthetic, $inputs);
        }

        if ($synthetic->getFiles()->isEmpty() && 0 === $synthetic->getFile()) {
            $this->attachConversationFile($synthetic, $context);
        }

        if ($synthetic->getFiles()->isEmpty() && 0 === $synthetic->getFile()) {
            return NodeResult::failed('file_analysis: no file to analyze');
        }

        $language = is_string($context->classification['language'] ?? null)
            ? $context->classification['language']
            : ($context->message->getLanguage() ?: 'en');

        $classification = [
            'topic' => 'analyzefile',
            'intent' => 'file_analysis',
            'language' => $language,
        ];

        try {
            $result = $this->handler->handle($synthetic, $context->thread, $classification, null, ['disable_memories' => true]);
        } catch (\Throwable $e) {
            $this->logger->warning('FileAnalysisRunner: handler threw', [
                'error' => $e->getMessage(),
            ]);

            // Never leak provider internals into the card: present the
            // localized reason, keep the raw message in the log (#1074).
            $copy = $this->errorPresenter instanceof ChatErrorPresenter
                ? $this->errorPresenter->present($e, $language)->userText
                : 'Something went wrong while answering this request. Please try again.';

            return NodeResult::failed($copy);
        }

        $metadata = is_array($result['metadata'] ?? null) ? $result['metadata'] : [];
        $content = is_string($result['content'] ?? null) ? $result['content'] : '';
        if ('' === trim($content)) {
            return NodeResult::failed('file_analysis produced no answer'.(isset($metadata['error']) && is_string($metadata['error']) ? ': '.$metadata['error'] : ''));
        }

        return NodeResult::ok($content, [], $metadata);
    }

    /**
     * In-memory message carrying the node's scoped question plus the inbound
     * attachments (both the M2M files and the legacy single-file fields). Never
     * persisted; FileAnalysisHandler reads files + text from it and returns an
     * answer without mutating it.
     */
    private function syntheticMessage(NodeContext $context, ?string $prompt): Message
    {
        $source = $context->message;

        $m = new Message();
        $m->setUserId((int) $source->getUserId());
        // Handler catalog fallback uses this message's chatId for
        // findFilesByChatId when the history window dropped the upload.
        $m->setChatId($source->getChatId());
        $m->setText(null !== $prompt && '' !== trim($prompt) ? $prompt : (string) $source->getText());
        $m->setLanguage($source->getLanguage() ?: 'en');
        $m->setDirection('IN');

        foreach ($source->getFiles() as $file) {
            $m->addFile($file);
        }

        // Legacy single-file fallback fields (used when there are no M2M files).
        $m->setFile($source->getFile());
        $m->setFilePath((string) $source->getFilePath());
        $m->setFileType((string) $source->getFileType());
        $m->setFileText((string) ($source->getFileText() ?: ''));

        $realId = $source->getId();
        try {
            $ref = new \ReflectionProperty(Message::class, 'id');
            $ref->setValue($m, $realId ?? random_int(1, 2_000_000_000));
        } catch (\Throwable) {
            // If reflection ever fails, the handler will surface a clear error.
        }

        return $m;
    }

    /**
     * Lift the first resolved upstream file descriptor out of the node's inputs
     * onto the synthetic message's legacy single-file fields (issue #1080).
     *
     * NodeContext resolves `$nX.file` / `$nX.files` into descriptors shaped like
     * `['path' => ..., 'type' => ..., 'local_path' => ...]`. FileAnalysisHandler
     * reads the legacy single-file path (`getFilePath()`/`getFileText()`) when the
     * message carries no `File` entities and normalises the public
     * `/api/v1/files/uploads/...` form to a relative upload path itself, so
     * surfacing one path is enough to route a generated image to the vision model.
     *
     * @param array<string, mixed> $inputs
     */
    private function attachUpstreamFile(Message $message, array $inputs): void
    {
        $descriptor = $this->firstUpstreamFile($inputs);
        if (null === $descriptor) {
            return;
        }

        $path = $descriptor['local_path'] ?? $descriptor['path'] ?? null;
        if (!is_string($path) || '' === trim($path)) {
            return;
        }

        $message->setFile(1);
        $message->setFilePath($path);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $message->setFileType('' !== $extension ? $extension : (is_string($descriptor['type'] ?? null) ? $descriptor['type'] : ''));
        $message->setFileText(is_string($descriptor['text'] ?? null) ? $descriptor['text'] : '');

        $this->logger->info('FileAnalysisRunner: analyzing upstream-generated file', [
            'path' => $path,
        ]);
    }

    /**
     * A follow-up ("what does the PDF say?") has no new attachment. The file
     * is still in the conversation catalog — attach it so the handler can
     * answer instead of failing with "no file to analyze".
     */
    private function attachConversationFile(Message $synthetic, NodeContext $context): void
    {
        if (null === $this->conversationFiles || null === $this->fileRepository) {
            return;
        }

        $catalog = $this->conversationFiles->build($context->message, $context->thread, [], null, false);
        $picks = $this->conversationFiles->analyzableForFollowUp($catalog);
        if ([] === $picks) {
            return;
        }

        $userId = (int) $synthetic->getUserId();
        $attached = 0;

        foreach ($picks as $pick) {
            if (null === $pick->fileId) {
                continue;
            }
            $file = $this->fileRepository->find($pick->fileId);
            if ($file instanceof File && $file->getUserId() === $userId) {
                $synthetic->addFile($file);
                ++$attached;
            }
        }

        if ($attached > 0) {
            $this->logger->info('FileAnalysisRunner: analyzing conversation file(s)', [
                'file_count' => $attached,
            ]);

            return;
        }

        $pick = $picks[0];
        $synthetic->setFile(1);
        $synthetic->setFilePath($pick->relativePath);
        $extension = strtolower(pathinfo($pick->relativePath, PATHINFO_EXTENSION));
        $synthetic->setFileType('' !== $extension ? $extension : $pick->category);
        $synthetic->setFileText($pick->extractedText);

        $this->logger->info('FileAnalysisRunner: analyzing conversation file by path', [
            'path' => $pick->relativePath,
        ]);
    }

    /**
     * Find the first upstream file descriptor among the resolved inputs. The
     * `prompt`/`text` inputs are skipped; every other value is scanned because it
     * may be a single descriptor or a list of them.
     *
     * @param array<string, mixed> $inputs
     *
     * @return array<string, mixed>|null
     */
    private function firstUpstreamFile(array $inputs): ?array
    {
        foreach ($inputs as $key => $value) {
            if ('prompt' === $key || 'text' === $key) {
                continue;
            }
            $descriptor = $this->findDescriptor($value);
            if (null !== $descriptor) {
                return $descriptor;
            }
        }

        return null;
    }

    /**
     * Recursively locate the first file descriptor (an array carrying a `path` or
     * `local_path`) inside a resolved input value.
     *
     * @return array<string, mixed>|null
     */
    private function findDescriptor(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $hasPath = (isset($value['local_path']) && is_string($value['local_path']) && '' !== $value['local_path'])
            || (isset($value['path']) && is_string($value['path']) && '' !== $value['path']);
        if ($hasPath) {
            return $value;
        }

        foreach ($value as $item) {
            $descriptor = $this->findDescriptor($item);
            if (null !== $descriptor) {
                return $descriptor;
            }
        }

        return null;
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
