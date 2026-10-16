<?php

declare(strict_types=1);

namespace App\Service\Message;

/**
 * The id stored on an outgoing message is the model that produced the reply.
 * A requested id is only a fallback when the handler did not report one.
 */
final class ChatModelId
{
    public static function persisted(mixed $metadataModelId, mixed $requestedModelId): ?string
    {
        foreach ([$metadataModelId, $requestedModelId] as $candidate) {
            if (!is_int($candidate) && !is_string($candidate)) {
                continue;
            }
            if (is_string($candidate) && !ctype_digit($candidate)) {
                continue;
            }
            $id = (int) $candidate;
            if ($id > 0) {
                return (string) $id;
            }
        }

        return null;
    }
}
