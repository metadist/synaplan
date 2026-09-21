<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Runner;

use App\Entity\Message;
use App\Service\File\DocumentImageCatalog;
use App\Service\File\FileGenerationEnvelope;
use App\Service\Message\Handler\ChatHandler;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\TaskRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Multitask\Skill\SkillDescriptor;
use App\Service\UrlContentService;
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
    /** A document illustrated by a whole gallery is not what the planner means. */
    private const MAX_UPSTREAM_IMAGES = 4;

    /**
     * Asked instead of writing the file when the message names a source URL
     * no fetch could read. Returned as a successful text-only result (not
     * failed()): reply nodes only see `.text`, so a failure here would hide
     * the cause behind a generic fallback while the file stayed missing.
     */
    private const UNREAD_SOURCE_TEXT = [
        'en' => 'I could not read %s, so no file was created. Check that the link opens in a browser, then ask again.',
        'de' => 'Ich konnte %s nicht lesen, daher wurde keine Datei erstellt. Prüfe, ob der Link im Browser funktioniert, und frag dann erneut.',
        'es' => 'No pude leer %s, así que no se creó ningún archivo. Comprueba que el enlace funcione en un navegador y vuelve a preguntar.',
        'fr' => 'Je n\'ai pas pu lire %s, donc aucun fichier n\'a été créé. Vérifiez que le lien s\'ouvre dans un navigateur, puis redemandez.',
        'tr' => '%s okunamadı, bu yüzden dosya oluşturulmadı. Bağlantının tarayıcıda açıldığını kontrol edin, sonra tekrar sorun.',
    ];

    public function __construct(
        private ChatHandler $handler,
        private LoggerInterface $logger,
        private UrlContentService $urlContent,
    ) {
    }

    public function supportedCapabilities(): array
    {
        return [Capability::DocumentGeneration];
    }

    /**
     * @return list<SkillDescriptor>
     */
    public function describe(): array
    {
        return [
            new SkillDescriptor(Capability::DocumentGeneration, 'Generate an Office document (CSV/XLSX/DOCX/PPTX). Word documents support a real table of contents — when the user asks for one, keep that wish in the prompt input (e.g. "with a table of contents").'),
        ];
    }

    public function run(TaskNode $node, NodeContext $context): NodeResult
    {
        $inputs = $context->resolveInputs($node);
        $prompt = $this->stringInput($inputs['prompt'] ?? $inputs['text'] ?? null) ?? (string) $context->message->getText();
        if ('' === trim($prompt)) {
            return NodeResult::failed('no prompt for document_generation');
        }

        // A format named in the ORIGINAL request ("als Excel") wins even when
        // the planner's prompt no longer mentions it. Appending the directive
        // keeps the envelope consistent; GeneratedDocumentStore enforces the
        // same rule deterministically as a backstop (#2051).
        $namedFormat = FileGenerationEnvelope::requestedFormat((string) $context->message->getText());
        if (null !== $namedFormat) {
            $prompt .= "\n\nThe file MUST be a .{$namedFormat} file (the user explicitly asked for this format).";
        }

        $language = is_string($context->classification['language'] ?? null)
            ? $context->classification['language']
            : ($context->message->getLanguage() ?: 'en');

        $unreadUrl = $this->namedButUnreadSourceUrl($context);
        if (null !== $unreadUrl) {
            $template = self::UNREAD_SOURCE_TEXT[$language] ?? self::UNREAD_SOURCE_TEXT['en'];

            return NodeResult::ok(sprintf($template, $unreadUrl), [], [
                'document_generation' => [
                    'created' => false,
                    'reason' => 'source_unread',
                    'url' => $unreadUrl,
                ],
            ]);
        }

        $synthetic = $this->syntheticMessage($context, $prompt, $language);

        // Force the officemaker topic so ChatHandler loads the file-generation
        // prompt (the one that emits {"BFILEPATH":...,"BFILETEXT":...}) and the
        // DocumentGeneratorService turns it into a real OOXML file.
        $classification = [
            'topic' => 'officemaker',
            'intent' => 'document_generation',
            'language' => $language,
        ];

        $options = ['disable_memories' => true];
        $upstreamImages = $this->upstreamImagePaths($node, $context, $inputs);
        if ([] !== $upstreamImages) {
            // Hand the pictures produced by an upstream node to the officemaker
            // prompt, so a document can embed the image the same turn generated
            // instead of guessing a marker for it (#1382).
            $options['document_images'] = $upstreamImages;
        }

        try {
            // disable_memories: an intermediate generation node must not trigger
            // memory extraction on the synthetic prompt.
            $result = $this->handler->handle($synthetic, $context->thread, $classification, null, $options);
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
            // The handler returned success but carried no usable file — e.g. a
            // prose-wrapped or malformed envelope that could not be salvaged.
            // Surface why in the worker log instead of failing the node
            // silently (#1406), mirroring MediaGenerationRunner.
            $this->logger->warning('DocumentGenerationRunner: node produced no file', [
                'node_id' => $node->id,
                'metadata_error' => is_scalar($metadata['error'] ?? null) ? $metadata['error'] : null,
                'metadata_keys' => array_keys($metadata),
            ]);

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

    /**
     * Upload-dir-relative paths of the images this node may embed: the ones the
     * planner wired explicitly (`inputs.images` / `inputs.image`) plus every
     * image any node it depends on produced.
     *
     * @param array<string, mixed> $inputs already-resolved node inputs
     *
     * @return list<string>
     */
    private function upstreamImagePaths(TaskNode $node, NodeContext $context, array $inputs): array
    {
        $descriptors = [];
        foreach (['images', 'image'] as $key) {
            $value = $inputs[$key] ?? null;
            if (is_array($value)) {
                $descriptors = array_merge($descriptors, isset($value['path']) ? [$value] : $value);
            }
        }

        foreach ($node->dependsOn as $dependencyId) {
            $result = $context->getResult($dependencyId);
            if (null !== $result) {
                $descriptors = array_merge($descriptors, $result->files);
            }
        }

        $paths = [];
        foreach ($descriptors as $descriptor) {
            if (!is_array($descriptor)) {
                continue;
            }

            $relative = $this->relativeImagePath($descriptor);
            if (null !== $relative && !in_array($relative, $paths, true)) {
                $paths[] = $relative;
            }
        }

        return array_slice($paths, 0, self::MAX_UPSTREAM_IMAGES);
    }

    /**
     * @param array<string, mixed> $descriptor node file descriptor
     */
    private function relativeImagePath(array $descriptor): ?string
    {
        $relative = is_string($descriptor['local_path'] ?? null) ? $descriptor['local_path'] : null;
        if (null === $relative || '' === trim($relative)) {
            $relative = is_string($descriptor['path'] ?? null) ? $descriptor['path'] : null;
        }

        if (null === $relative || '' === trim($relative)) {
            return null;
        }

        $servePrefix = '/api/v1/files/uploads/';
        if (str_starts_with($relative, $servePrefix)) {
            $relative = substr($relative, strlen($servePrefix));
        }

        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if (!in_array($extension, DocumentImageCatalog::SUPPORTED_EXTENSIONS, true)) {
            return null;
        }

        return ltrim($relative, '/');
    }

    /**
     * First URL the message treats as a source that no fetch could read — or
     * null when generation may proceed: no URL named, at least one page read,
     * the URL is incidental (no load/use verb), or no read ran at all
     * (URL reading off — not this node's call).
     */
    private function namedButUnreadSourceUrl(NodeContext $context): ?string
    {
        $read = $context->classification['url_pages_read'] ?? null;
        if (!is_int($read) || $read > 0) {
            return null;
        }
        $text = (string) $context->message->getText();
        $urls = $this->urlContent->extractUrls($text);
        if ([] === $urls) {
            return null;
        }
        if (!self::textDemandsSourceRead($text)) {
            return null;
        }

        return mb_strlen($urls[0]) > 80 ? mb_substr($urls[0], 0, 80).'…' : $urls[0];
    }

    private static function textDemandsSourceRead(string $text): bool
    {
        return 1 === preg_match('/\b(lad\w*|load\w*|lies|lese\w*|read\w*|fetch\w*|hol\w*|nutz\w*|us(e|ing)|verwend\w*|zusammenfass\w*|summariz\w*|summaris\w*)\b/i', $text)
            || 1 === preg_match('/\b(aus|von|from)\s+https?:\/\//i', $text)
            || 1 === preg_match('/basierend auf|based on/i', $text);
    }

    private function syntheticMessage(NodeContext $context, string $prompt, string $language): Message
    {
        $m = new Message();
        $m->setUserId((int) $context->message->getUserId());
        $m->setText($prompt);
        $m->setLanguage($language);
        $m->setDirection('IN');
        $m->setFile($context->message->getFile());
        $m->setFilePath($context->message->getFilePath());
        $m->setFileType($context->message->getFileType());
        $m->setFileText($context->message->getFileText());
        foreach ($context->message->getFiles() as $file) {
            $m->addFile($file);
        }

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
