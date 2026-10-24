<?php

declare(strict_types=1);

namespace App\Service\Research\Eval;

/**
 * Where a live arm sends the question. Empty values mean "do not send".
 */
final readonly class EvalLiveTarget
{
    /**
     * @param list<string> $judges
     */
    public function __construct(
        public ?string $folder,
        public ?string $gatewayUrl,
        public ?string $apiKey,
        public ?string $gatewayModel,
        public ?string $b4Endpoint,
        public array $judges,
        public bool $webSearch = false,
    ) {
    }
}
