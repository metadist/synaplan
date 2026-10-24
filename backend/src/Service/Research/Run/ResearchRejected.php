<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

/** A research request the caller can correct, with a sentence and the field. */
final class ResearchRejected extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        public readonly ?string $field,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @return array{error: array{code: string, field: string|null, message: string}}
     */
    public function body(): array
    {
        return [
            'error' => [
                'code' => $this->errorCode,
                'field' => $this->field,
                'message' => $this->getMessage(),
            ],
        ];
    }
}
