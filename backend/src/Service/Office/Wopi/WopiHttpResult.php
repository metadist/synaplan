<?php

declare(strict_types=1);

namespace App\Service\Office\Wopi;

/**
 * One WOPI response. `filePath` is set only for GetFile; the controller
 * streams it and does not JSON-encode the bytes.
 */
final readonly class WopiHttpResult
{
    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string>     $headers
     */
    public function __construct(
        public int $status,
        public ?array $json = null,
        public array $headers = [],
        public ?string $filePath = null,
        public ?string $downloadName = null,
    ) {
    }
}
