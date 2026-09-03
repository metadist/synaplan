<?php

declare(strict_types=1);

namespace App\Service\Document\Tool;

final readonly class DocumentToolResult
{
    /**
     * @param array<string, scalar|array<int, scalar>> $labelParams
     */
    public function __construct(
        public bool $ok,
        public string $message,
        public string $labelKey,
        public array $labelParams = [],
        public bool $isError = false,
        public bool $mutates = true,
    ) {
    }

    /**
     * @param array<string, scalar|array<int, scalar>> $labelParams
     */
    public static function ok(string $message, string $labelKey, array $labelParams = []): self
    {
        return new self(true, $message, $labelKey, $labelParams, false, true);
    }

    /**
     * Read-only tool result: never counts as a document mutation.
     *
     * @param array<string, scalar|array<int, scalar>> $labelParams
     */
    public static function read(string $message, string $labelKey, array $labelParams = []): self
    {
        return new self(true, $message, $labelKey, $labelParams, false, false);
    }

    /**
     * @param array<string, scalar|array<int, scalar>> $labelParams
     */
    public static function error(string $message, string $labelKey, array $labelParams = []): self
    {
        return new self(false, $message, $labelKey, $labelParams, true, false);
    }
}
