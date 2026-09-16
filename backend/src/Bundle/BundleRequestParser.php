<?php

declare(strict_types=1);

namespace App\Bundle;

/**
 * Reads an incoming import body: either the `synaplan-bundle.v1` document
 * itself, or a `{ bundle, options }` wrapper carrying import options.
 *
 * Shared by every endpoint that accepts a bundle so they all enforce the
 * same limits. The size is checked before decoding, and a wrapped envelope
 * is re-encoded so the validator's byte and depth limits apply to exactly
 * the document being imported.
 */
final readonly class BundleRequestParser
{
    /**
     * @return array{bundle: string, options: ImportOptions}
     *
     * @throws BundleTooLargeException body exceeds the size limit
     * @throws BundleEnvelopeException body is not a bundle document
     */
    public function parse(string $content): array
    {
        if (strlen($content) > BundleEnvelopeValidator::MAX_BYTES) {
            throw new BundleTooLargeException(BundleEnvelopeValidator::MAX_BYTES);
        }
        $decoded = json_decode($content, true, BundleEnvelopeValidator::MAX_DEPTH + 2);
        $body = is_array($decoded) ? $decoded : [];

        if (isset($body['bundle']) && is_array($body['bundle'])) {
            return [
                'bundle' => json_encode($body['bundle'], JSON_THROW_ON_ERROR),
                'options' => self::optionsFrom($body['options'] ?? null),
            ];
        }
        if (isset($body['schema'])) {
            return ['bundle' => $content, 'options' => new ImportOptions()];
        }

        throw new BundleEnvelopeException('$', 'send a synaplan-bundle.v1 document');
    }

    private static function optionsFrom(mixed $options): ImportOptions
    {
        $conflict = is_array($options) && is_string($options['conflict'] ?? null)
            ? $options['conflict']
            : ImportOptions::CONFLICT_SKIP;

        return new ImportOptions($conflict);
    }
}
