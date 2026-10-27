<?php

declare(strict_types=1);

namespace App\AI\Pipeline;

use App\AI\Stream\StreamChunk;

/**
 * Hook that also observes each normalized chunk of a streamed {@see AiCall}
 * (`03_contracts.md` §1.2).
 */
interface StreamingAiCallHookInterface extends AiCallHookInterface
{
    /**
     * Sees each normalized chunk; may not block.
     */
    public function onChunk(AiCall $call, StreamChunk $chunk): void;
}
