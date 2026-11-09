<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\AI\Exception\ProviderException;
use App\Entity\User;

/**
 * The web media endpoint queues a video when the provider can poll.
 * Every other caller of {@see MediaGenerationService::generate()} still
 * receives the finished file, including paired desktop projects.
 */
final readonly class WebMediaAnswer
{
    public function __construct(
        private MediaGenerationServiceInterface $media,
    ) {
    }

    /**
     * @return array{body: array<string, mixed>, status: int}
     */
    public function answer(User $user, string $prompt, string $type, ?int $modelId, ?string $resolution): array
    {
        if ('video' === strtolower(trim($type))) {
            try {
                $started = $this->media->startVideoGeneration($user, $prompt, $modelId, $resolution);

                return ['body' => VideoGenerationStart::accepted($started), 'status' => 202];
            } catch (ProviderException $e) {
                if (!str_starts_with($e->getMessage(), 'Async video generation is not supported')) {
                    throw $e;
                }
            }
        }

        return [
            'body' => $this->media->generate($user, $prompt, $type, $modelId, $resolution),
            'status' => 200,
        ];
    }
}
