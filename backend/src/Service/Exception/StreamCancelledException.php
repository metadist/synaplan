<?php

declare(strict_types=1);

namespace App\Service\Exception;

/**
 * Raised inside the SSE stream callback when the running turn was EXPLICITLY
 * cancelled by the user (Stop button → /stop-stream or the guest counterpart
 * → /api/v1/guest/stop-stream, both flagging the MediaCancellationStore).
 *
 * Deliberately distinct from a bare client disconnect: since the
 * detach-on-navigation change (issues #1142/#1223/#1225) a disconnect never
 * aborts the turn, so "cancelled" and "disconnected" must not share an
 * exception or log message. Also distinct from
 * {@see \App\AI\Exception\ProviderCancelledException}, which is scoped to a
 * single media node and extends ProviderException (rendered as a provider
 * outcome), whereas this one ends the whole streamed turn silently.
 */
final class StreamCancelledException extends \RuntimeException
{
}
