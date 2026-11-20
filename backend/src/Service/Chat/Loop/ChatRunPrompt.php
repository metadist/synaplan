<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

/**
 * What the model of a new chat run is asked with: the provider and model the
 * turn chose, the answer language, the base system prompt, and the
 * transcript after it, and the model's price for the cost estimate.
 */
final readonly class ChatRunPrompt
{
    /**
     * @param list<array<string, mixed>> $messages
     */
    public function __construct(
        public ?string $provider,
        public ?string $model,
        public string $locale,
        public string $basePrompt,
        public array $messages,
        public ?ChatRunRates $rates = null,
    ) {
    }
}
