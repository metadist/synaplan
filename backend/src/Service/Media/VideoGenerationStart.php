<?php

declare(strict_types=1);

namespace App\Service\Media;

/**
 * The public video request returns as soon as the job exists. The render
 * itself is polled later and never holds this request.
 */
final class VideoGenerationStart
{
    /**
     * @param array{jobId: string, status: string, provider: string, model: string, resolution?: string} $started
     *
     * @return array{success: true, status: 'processing', jobId: string, provider: string, model: string, resolution: string|null}
     */
    public static function accepted(array $started): array
    {
        return [
            'success' => true,
            'status' => 'processing',
            'jobId' => $started['jobId'],
            'provider' => $started['provider'],
            'model' => $started['model'],
            'resolution' => $started['resolution'] ?? null,
        ];
    }
}
