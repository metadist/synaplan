<?php

declare(strict_types=1);

namespace App\Service\Tool\Execution;

use App\Entity\User;
use App\Service\Tool\Policy\PolicyContext;

/**
 * The arguments {@see ToolExecutionGate::inspect()} receives today.
 * Each caller copies its current call. AU25 drops this and the invoker
 * fills the request itself.
 */
final readonly class GateRequest
{
    /**
     * @param array<string, mixed>|null                                                                  $assistantTools
     * @param array{method: string, url: string, headers: array<string, string>, body: string|null}|null $resolvedRequest
     */
    public function __construct(
        public ?User $actor,
        public PolicyContext $context,
        public string $requestedBy,
        public bool $allowUnattended = false,
        public ?string $nodeApproval = null,
        public bool $requireApproval = false,
        public ?array $assistantTools = null,
        public ?string $assistantKey = null,
        public ?array $resolvedRequest = null,
        public bool $skip = false,
    ) {
    }
}
