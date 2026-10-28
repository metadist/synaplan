<?php

declare(strict_types=1);

namespace App\AI\Pipeline;

/**
 * One AI capability call passing through {@see AiCallPipeline} (`03_contracts.md` §1.1).
 */
final readonly class AiCall
{
    public function __construct(
        public AiCapability $capability,
        public string $provider,
        public ?string $model,
        public AiCallContext $context,
        public AiCallPayload $payload,
        public AiCallOptions $options,
    ) {
    }

    public function withContext(AiCallContext $context): self
    {
        return new self(
            capability: $this->capability,
            provider: $this->provider,
            model: $this->model,
            context: $context,
            payload: $this->payload,
            options: $this->options,
        );
    }

    public function withOptions(AiCallOptions $options): self
    {
        return new self(
            capability: $this->capability,
            provider: $this->provider,
            model: $this->model,
            context: $this->context,
            payload: $this->payload,
            options: $options,
        );
    }
}
