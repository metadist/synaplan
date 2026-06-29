<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Runner;

use App\Entity\Message;
use App\Service\File\FileHelper;
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

        // Live progress: forward the handler's media-generation status updates to
        // the node's progress sink so the task card renders a moving bar.
        $progress = function (array $update) use ($context, $node): void {
            if ('generating' !== ($update['status'] ?? '')) {
                return;
            }
            $meta = is_array($update['metadata'] ?? null) ? $update['metadata'] : [];
            $context->emitProgress($node->id, array_merge([
                'capability' => $node->capability->value,
                'kind' => $node->capability->uiKind(),
            ], $meta));
        };

        // Stop button: pass the turn + node identity so the handler can probe the
        // shared cancellation store and abort the provider poll on demand.
        //
        // record_media_usage (issue #1146): a multitask media node is billed by
        // the provider but the DAG path never recorded that cost in BUSELOG —
        // a budget bypass. Have the handler record IMAGES/VIDEOS/AUDIOS itself
        // (on success AND on cancel) so multitask media spend counts against the
        // user's budget like single-chat media does.
        $handlerOptions = ['disable_memories' => true, 'record_media_usage' => true];
        $trackId = $context->options['track_id'] ?? null;
        if (is_scalar($trackId) && '' !== (string) $trackId) {
            $handlerOptions['track_id'] = (string) $trackId;
            $handlerOptions['node_id'] = $node->id;
        }

        // Media-to-media chaining (issue #1144): when this node depends on an
        // upstream node's file output (e.g. planner emits
        // `inputs.image = "$n1.file"` / `dependsOn: ["n1"]`), NodeContext has
        // already resolved that reference into a file descriptor. The synthetic
        // message only carries the user's own uploads, so MediaGenerationHandler
        // would never see the upstream image and would silently fall back to
        // TEXT2VID / TEXT2PIC. Lift any resolved upstream image file paths out of
        // the inputs and pass them to the handler, which makes
        // collectAttachedImagePaths() find them and triggers the correct
        // IMG2VID (animate) / PIC2PIC (edit) path.
        $referenceImagePaths = $this->collectReferenceImagePaths($inputs);
        if ([] !== $referenceImagePaths) {
            $handlerOptions['reference_image_paths'] = $referenceImagePaths;
            $this->logger->info('MediaGenerationRunner: forwarding upstream file(s) as media reference', [
                'capability' => $node->capability->value,
                'node_id' => $node->id,
                'reference_count' => count($referenceImagePaths),
            ]);
        }

        // Image-by-URL (e.g. "make a video from https://…/photo.jpg"): the
        // synthetic message carries the planner's CLEANED prompt and only file
        // attachments, so a URL pasted in the ORIGINAL user message would be
        // lost and the request would degrade to TEXT2VID. Surface those URLs
        // explicitly so the handler can route to IMG2VID and hand the provider a
        // ready-to-fetch reference frame.
        $referenceImageUrls = FileHelper::extractImageUrls((string) $context->message->getText());
        foreach (FileHelper::extractImageUrls($prompt) as $promptImageUrl) {
            if (!in_array($promptImageUrl, $referenceImageUrls, true)) {
                $referenceImageUrls[] = $promptImageUrl;
            }
        }
        if ([] !== $referenceImageUrls) {
            $handlerOptions['reference_image_urls'] = $referenceImageUrls;
            $this->logger->info('MediaGenerationRunner: forwarding image URL(s) from user text as media reference', [
                'capability' => $node->capability->value,
                'node_id' => $node->id,
                'reference_count' => count($referenceImageUrls),
            ]);
        }

        try {
            // disable_memories: an intermediate generation node must not trigger
            // memory extraction on the synthetic prompt.
            $result = $this->handler->handle($synthetic, $context->thread, $classification, $progress, $handlerOptions);
        } catch (\Throwable $e) {
            $this->logger->warning('MediaGenerationRunner: handler threw', [
                'capability' => $node->capability->value,
                'error' => $e->getMessage(),
            ]);

            return NodeResult::failed($node->capability->value.' failed: '.$e->getMessage());
        }

        $metadata = $result['metadata'] ?? [];
        $mediaJob = $metadata['media_job'] ?? null;
        if (is_array($mediaJob) && is_string($mediaJob['job_id'] ?? null) && '' !== $mediaJob['job_id']) {
            return NodeResult::running($metadata);
        }

        $file = $metadata['file'] ?? null;
        if (!is_array($file) || empty($file['path'])) {
            // [i2v-debug] The handler returned but carried no usable file. This is
            // the silent dead-end behind a task card that never leaves "Rendering…":
            // surface why (provider error vs. empty result) so the cause is visible
            // in the worker log instead of only an opaque failed node.
            $this->logger->warning('MediaGenerationRunner: node produced no file', [
                'capability' => $node->capability->value,
                'node_id' => $node->id,
                'metadata_error' => is_scalar($metadata['error'] ?? null) ? $metadata['error'] : null,
                'metadata_keys' => array_keys($metadata),
            ]);

            $errorSuffix = is_scalar($metadata['error'] ?? null) ? ': '.(string) $metadata['error'] : '';

            return NodeResult::failed($node->capability->value.' produced no file'.$errorSuffix);
        }

        $descriptor = [
            'path' => $file['path'],
            'type' => $file['type'] ?? $mediaType,
            'local_path' => is_string($metadata['local_path'] ?? null) ? $metadata['local_path'] : null,
        ];

        // [i2v-debug] The worker reached terminal success for this media node. If
        // this line appears but the task card stays at "Rendering… 95%", the
        // generation worked and the bug is in DELIVERY (the closing
        // task_update/task_file never reaching the browser), not in generation.
        // debug level: this fires for every successful media node, so it must
        // not pollute production info logs (only warning above stays loud).
        $this->logger->debug('MediaGenerationRunner: node produced file', [
            'capability' => $node->capability->value,
            'node_id' => $node->id,
            'path' => $file['path'],
            'type' => $descriptor['type'],
        ]);

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

    /**
     * Pull every resolved upstream file path out of a node's inputs so they can
     * be handed to MediaGenerationHandler as reference images (issue #1144).
     *
     * NodeContext resolves `$nX.file` / `$nX.files` / `$message.files` into file
     * descriptors shaped like `['path' => ..., 'type' => ..., 'local_path' => ...]`.
     * The prompt/text inputs are skipped; everything else is scanned (a value may
     * be a single descriptor or a list of them). The handler filters by image
     * extension and on-disk existence, so we only need to surface candidate paths.
     *
     * @param array<string, mixed> $inputs
     *
     * @return list<string>
     */
    private function collectReferenceImagePaths(array $inputs): array
    {
        $paths = [];
        foreach ($inputs as $key => $value) {
            if ('prompt' === $key || 'text' === $key) {
                continue;
            }
            $this->extractDescriptorPaths($value, $paths);
        }

        return array_values(array_unique($paths));
    }

    /**
     * Recursively collect file paths from a resolved input value (a descriptor,
     * a list of descriptors, or anything else which is ignored).
     *
     * @param list<string> $paths accumulator (by reference)
     */
    private function extractDescriptorPaths(mixed $value, array &$paths): void
    {
        if (!is_array($value)) {
            return;
        }

        // Single file descriptor: prefer the local (relative/absolute) path, fall
        // back to the public display path. The handler normalises either form.
        $candidate = null;
        if (isset($value['local_path']) && is_string($value['local_path']) && '' !== $value['local_path']) {
            $candidate = $value['local_path'];
        } elseif (isset($value['path']) && is_string($value['path']) && '' !== $value['path']) {
            $candidate = $value['path'];
        }

        if (null !== $candidate) {
            $paths[] = $candidate;

            return;
        }

        // Otherwise it may be a list of descriptors — recurse into each element.
        foreach ($value as $item) {
            $this->extractDescriptorPaths($item, $paths);
        }
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
