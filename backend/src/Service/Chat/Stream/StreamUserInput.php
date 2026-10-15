<?php

declare(strict_types=1);

namespace App\Service\Chat\Stream;

/**
 * What the person sent: the text, the attached files, a quoted excerpt, or
 * the answer they asked to continue.
 */
final readonly class StreamUserInput
{
    /**
     * @param list<int>   $fileIds
     * @param string|null $continueMessageId the cut-off answer to continue, as sent
     */
    public function __construct(
        public string $message,
        public array $fileIds = [],
        public ?string $continueMessageId = null,
        public ?StreamQuote $quote = null,
    ) {
    }
}
