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
     * @param string|null $resolution For type=video: '720p', '1080p' or '4K'.
     *                                Falls back to the model's default_resolution when omitted/unsupported.
     *                                Ignored for type=image.
     *
     * The `resolution` key is only present for video generations and reflects the
     * resolution actually used (after normalization and provider negotiation), which
     * may differ from the caller's input.
     *
     * @return array{success: true, file: array{url: string, type: string, mimeType: string}, provider: string, model: string, resolution?: string}
     *
     * @throws \InvalidArgumentException  on bad input
     * @throws RateLimitExceededException when user exceeds quota
     * @throws NoModelAvailableException  when no model is configured
     * @throws ProviderException          on AI provider failure
     * @throws \RuntimeException          on storage failure
     */
    public function generate(User $user, string $prompt, string $type, ?int $modelId = null, ?string $resolution = null): array;

    /**
     * Generate an image from 1-2 input images and a text prompt (pic2pic).
     *
     * @param User     $user       Authenticated user
     * @param string   $prompt     Instruction (e.g. "Put the object from image 1 into the scene of image 2")
     * @param string[] $imagePaths Absolute paths to 1-2 uploaded images
     * @param int|null $modelId    Specific model ID (uses user default PIC2PIC if omitted)
     *
     * @return array{success: true, file: array{url: string, type: string, mimeType: string}, provider: string, model: string}
     *
     * @throws \InvalidArgumentException  on bad input
     * @throws RateLimitExceededException when user exceeds quota
     * @throws NoModelAvailableException  when no model is configured
     * @throws ProviderException          on AI provider failure
     * @throws \RuntimeException          on storage failure
     */
    public function generateFromImages(User $user, string $prompt, array $imagePaths, ?int $modelId = null): array;

    /**
     * Start async video generation (returns immediately with a job ID).
     *
     * @param string|null $resolution Output resolution ('720p', '1080p', '4K').
     *                                Falls back to the model's default_resolution when omitted/unsupported.
     *
     * @return array{jobId: string, status: string, provider: string, model: string, resolution?: string}
     */
    public function startVideoGeneration(User $user, string $prompt, ?int $modelId = null, ?string $resolution = null): array;

    /**
     * Check status of an async video generation job.
     *
     * @return array{status: string, file?: array{url: string, type: string, mimeType: string}, provider?: string, model?: string, error?: string, elapsed_seconds?: int}
     */
    public function checkVideoJob(User $user, string $jobId): array;
}
