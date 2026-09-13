<?php

declare(strict_types=1);

namespace App\Service\Compute\Contract;

final readonly class ComputeErrorBody
{
    /**
     * @param array<string, mixed>|null $details
     */
    public function __construct(
        public string $code,
        public string $message,
        public ?array $details = null,
    ) {
    }

    public static function fromJson(string $json): self
    {
        $data = ComputeJson::decodeObject($json, ['error']);
        $error = is_array($data['error'] ?? null) ? $data['error'] : [];
        ComputeJson::assertKeys($error, ['code', 'message', 'details']);

        return new self(
            code: (string) ($error['code'] ?? 'invalid_json'),
            message: (string) ($error['message'] ?? ''),
            details: is_array($error['details'] ?? null) ? $error['details'] : null,
        );
    }
}
