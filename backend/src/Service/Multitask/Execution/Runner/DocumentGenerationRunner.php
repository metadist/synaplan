<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Runner;

use App\Entity\Message;
use App\Service\Message\Handler\ChatHandler;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\TaskRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use Psr\Log\LoggerInterface;

/**
 * `document_generation` runner — produces an Office document (DOCX/XLSX/PPTX/CSV)
 * by reusing the existing legacy generation path ({@see ChatHandler} with the
 * `officemaker` topic + {@see \App\Service\File\DocumentGeneratorService}). The
 * v1 rule holds: no new generation code, just an adapter.
 *
 * Without this runner the DAG fails a `document_generation` node ("no runner for
 * capability"), shows a FAILED task card, and only the all-failed legacy
 * fallback actually produces the file — so the user sees an error *and* gets the
 * document. This adapter makes the node succeed and surface the file directly.
 *
 * Model selection, the LLM call, the JSON→OOXML conversion and disk persistence
 * all stay inside ChatHandler (resolved for the message owner).
 */
final readonly class DocumentGenerationRunner implements TaskRunner
{
    public function __construct(
        private ChatHandler $handler,
        private LoggerInterface $logger,
    ) {
    }

    public function supportedCapabilities(): array
    {
        return [Capability::DocumentGeneration];
    }

    public function run(TaskNode $node, NodeContext $context): NodeResult
    {
        $inputs = $context->resolveInputs($node);
        $prompt = $this->stringInput($inputs['prompt'] ?? $inputs['text'] ?? null) ?? (string) $context->message->getText();
        if ('' === trim($prompt)) {
            return NodeResult::failed('no prompt for document_generation');
        }

        $language = is_string($context->classification['language'] ?? null)
            ? $context->classification['language']
            : ($context->message->getLanguage() ?: 'en');

        $synthetic = $this->syntheticMessage($context, $prompt, $language);

        // Force the officemaker topic so ChatHandler loads the file-generation
        // prompt (the one that emits {"BFILEPATH":...,"BFILETEXT":...}) and the
        // DocumentGeneratorService turns it into a real OOXML file.
        $classification = [
            'topic' => 'officemaker',
            'intent' => 'document_generation',
            'language' => $language,
        ];

        try {
            // disable_memories: an intermediate generation node must not trigger
            // memory extraction on the synthetic prompt.
            $result = $this->handler->handle($synthetic, $context->thread, $classification, null, ['disable_memories' => true]);
        } catch (\Throwable $e) {
            $this->logger->warning('DocumentGenerationRunner: handler threw', [
                'error' => $e->getMessage(),
            ]);

            return NodeResult::failed('document_generation failed: '.$e->getMessage());
        }

        if (!($result['success'] ?? true)) {
            $error = is_string($result['error'] ?? null) ? $result['error'] : 'unknown handler error';

            return NodeResult::failed('document_generation failed: '.$error);
        }

        $metadata = is_array($result['metadata'] ?? null) ? $result['metadata'] : [];
        $descriptor = $this->fileDescriptor($metadata);
        if (null === $descriptor) {
            return NodeResult::failed('document_generation produced no file'.(isset($metadata['error']) && is_string($metadata['error']) ? ': '.$metadata['error'] : ''));
        }

        $filename = is_string($metadata['generated_file']['filename'] ?? null) ? $metadata['generated_file']['filename'] : 'document';

        return NodeResult::ok('Document created: '.$filename, [$descriptor], $metadata);
    }

    /**
     * Build a node file descriptor from the handler metadata. ChatHandler
     * reports the file under `generated_file` (relative path); fall back to the
     * legacy `file` channel (already an API path) for robustness.
     *
     * @param array<string, mixed> $metadata
     *
     * @return array{path: string, type: string, local_path: string|null}|null
     */
    private function fileDescriptor(array $metadata): ?array
    {
        $generated = $metadata['generated_file'] ?? null;
        if (is_array($generated) && is_string($generated['path'] ?? null) && '' !== $generated['path']) {
            return [
                'path' => '/api/v1/files/uploads/'.$generated['path'],
                'type' => 'document',
                'local_path' => $generated['path'],
            ];
        }

        $file = $metadata['file'] ?? null;
        if (is_array($file) && is_string($file['path'] ?? null) && '' !== $file['path']) {
            return [
                'path' => $file['path'],
                'type' => is_string($file['type'] ?? null) ? $file['type'] : 'document',
                'local_path' => is_string($metadata['local_path'] ?? null) ? $metadata['local_path'] : null,
            ];
        }

        return null;
    }

    private function syntheticMessage(NodeContext $context, string $prompt, string $language): Message
    {
        $m = new Message();
        $m->setUserId((int) $context->message->getUserId());
        $m->setText($prompt);
        $m->setLanguage($language);
        $m->setDirection('IN');
        $m->setFile(0);

        // ChatHandler builds the output filename from the message id and needs a
        // non-null int. This synthetic message is never persisted, so assign a
        // unique pseudo-id (reflection; entity has no setId) to keep filenames
        // unique across concurrent nodes.
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
