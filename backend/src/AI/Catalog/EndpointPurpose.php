<?php

declare(strict_types=1);

namespace App\AI\Catalog;

/**
 * What a provider endpoint is used for. The Messages gateway needs the one
 * Chat Completions URL; the egress allowlist needs every host.
 */
enum EndpointPurpose: string
{
    /** The full `…/chat/completions` URL the Messages gateway posts to. */
    case ChatCompletions = 'chat_completions';

    /** Any other host the adapter calls: base URI, media, audio, a queue. */
    case Api = 'api';
}
