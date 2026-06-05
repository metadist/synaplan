<?php

declare(strict_types=1);

namespace App\Service\Media;

/**
 * Canonicalises the `metadata.file` block returned by
 * {@see \App\Service\Message\Handler\MediaGenerationHandler} into the
 * `{path, type}` shape we persist on a {@see \App\Entity\Message} row.
 *
 * Background — issue #626
 * -----------------------
 * Every inbound channel (email, public widget, WhatsApp, …) eventually
 * mirrors AI-generated media onto the DB row so the web chat history
 * endpoint can render the media player even when the message was sent
 * via a non-web channel. Before extraction this normalisation lived as
 * a verbatim `private` method in both `WebhookController` and
 * `WidgetPublicController`; the duplication was flagged on PR #947 and
 * is the kind of thing that silently rots when a third channel handler
 * is added.
 *
 * The normaliser is intentionally tiny and side-effect free:
 *  - Non-array / missing input → `null`. Callers must keep their
 *    `file=0` flag.
 *  - Empty / whitespace-only path → `null`. Some providers return a
 *    description-only result; flipping `file=1` for those would render
 *    a broken media player in the frontend.
 *  - `path` must be a string. Anything else (int, null, object) is
 *    rejected the same way.
 *  - `type` is best-effort: it falls back to the empty string so the
 *    frontend's legacy extension-based fallback still works.
 *  - Both fields are trimmed.
 *
 * Construct it as a `final readonly` service with no state so it stays
 * trivially injectable into controllers and other services.
 */
final readonly class GeneratedFileMetadataNormalizer
{
    /**
     * Normalise a `metadata.file` payload from MediaGenerationHandler.
     *
     * @param mixed $fileMeta expected shape: `['path' => string, 'type' => string]`
     *
     * @return array{path: string, type: string}|null
     */
    public function normalize(mixed $fileMeta): ?array
    {
        if (!is_array($fileMeta)) {
            return null;
        }

        $path = isset($fileMeta['path']) && is_string($fileMeta['path']) ? trim($fileMeta['path']) : '';
        $type = isset($fileMeta['type']) && is_string($fileMeta['type']) ? trim($fileMeta['type']) : '';

        if ('' === $path) {
            return null;
        }

        return ['path' => $path, 'type' => $type];
    }
}
