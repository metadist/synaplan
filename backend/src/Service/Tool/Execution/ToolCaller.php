<?php

declare(strict_types=1);

namespace App\Service\Tool\Execution;

use App\Service\Document\Tool\DocumentSession;

/**
 * Who is asking for the tool. The document session is set only for a
 * document edit; other callers leave it empty.
 */
final readonly class ToolCaller
{
    public function __construct(
        public ?int $userId,
        public string $channel,
        public bool $unattended = false,
        public ?string $requestId = null,
        public ?DocumentSession $document = null,
    ) {
    }
}
