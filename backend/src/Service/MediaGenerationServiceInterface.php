<?php

declare(strict_types=1);

namespace App\Service;

use App\AI\Exception\ProviderException;
use App\Entity\User;
use App\Service\Exception\NoModelAvailableException;
use App\Service\Exception\RateLimitExceededException;

interface MediaGenerationServiceInterface
{
    /**
     * Generate media (image or video) from a text prompt.
     *
     * @return array{success: true, file: array{url: string, type: string, mimeType: string}, provider: string, model: string}
     *
     * @throws \InvalidArgumentException  on bad input
     * @throws RateLimitExceededException when user exceeds quota
     * @throws NoModelAvailableException  when no model is configured
     * @throws ProviderException          on AI provider failure
     * @throws \RuntimeException          on storage failure
     */
    public function generate(User $user, string $prompt, string $type, ?int $modelId = null): array;
}
