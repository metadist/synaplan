<?php

declare(strict_types=1);

namespace App\Service\Chat\Run;

/**
 * The error frame a client already knows how to read.
 *
 * `errorReason` is set so the sentence is shown. A bare error string is
 * replaced by the generic translation.
 */
final class PumpFrames
{
    public const DID_NOT_START = "data: {\"status\":\"error\",\"error\":\"The answer did not start. Try again in a moment.\",\"errorReason\":\"upstream_unavailable\"}\n\n";

    public const LOST_PUMP = "data: {\"status\":\"error\",\"error\":\"The answer stopped because the server restarted. Try again.\",\"errorReason\":\"upstream_unavailable\"}\n\n";
}
