<?php

declare(strict_types=1);

namespace App\Service\Message\Chat;

use App\Entity\Model;

/**
 * The model that answers a chat turn, and which images go with the request.
 *
 * `hasImages` without `includeImages` means no available model can look at
 * the attached images; the handler then refuses the turn instead of
 * answering blind. `includeGeneratedImages` lets the model see pictures the
 * assistant generated earlier in the conversation.
 */
final readonly class ChatModelChoice
{
    public function __construct(
        public ?int $modelId,
        public ?string $provider,
        public ?string $modelName,
        public ?int $effectiveUserId,
        public bool $hasImages,
        public bool $includeImages,
        public bool $includeGeneratedImages,
        public ?Model $model = null,
    ) {
    }

    public function maxTokens(): ?int
    {
        return $this->model?->getMaxTokens();
    }

    /**
     * @return list<string>
     */
    public function features(): array
    {
        return $this->model?->getFeatures() ?? [];
    }
}
