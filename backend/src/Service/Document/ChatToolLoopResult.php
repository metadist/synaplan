<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Service\Document\Tool\DocumentSession;

final readonly class ChatToolLoopResult
{
    /**
     * @param array<string, mixed> $usage
     */
    public function __construct(
        public string $content,
        public DocumentSession $session,
        public array $usage = [],
    ) {
    }
}
