<?php

declare(strict_types=1);

namespace App\AI\Stream;

/**
 * Incremental decoder. feed() may be given any byte slice; the chunks must
 * match a single feed of the whole body.
 */
interface StreamDecoderInterface
{
    /**
     * @return list<DecodedEvent>
     */
    public function feed(string $bytes): array;

    /**
     * @return list<DecodedEvent> the rest at the end of the stream
     */
    public function finish(): array;
}
