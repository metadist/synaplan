<?php

declare(strict_types=1);

namespace App\AI\Interface;

/**
 * A video provider that supports a non-blocking submit → poll → download
 * lifecycle, so a long render can be advanced in short steps by a background
 * worker instead of blocking the request that started it.
 *
 * This is what makes the Redis-backed media-job system possible: the worker
 * submits once, polls once per cycle (re-arming nothing, holding no
 * connection), and finalizes — never the multi-minute blocking loop that used
 * to time out inside the FrankenPHP request.
 *
 * Credentials note: some providers (e.g. Veo) authenticate from a platform key
 * and ignore `$options`; others (e.g. Higgsfield) resolve per-user OR platform
 * credentials from `$options` on every call. The worker therefore re-injects
 * credentials (via {@see \App\AI\Service\AiFacade}) into `$options` for poll,
 * download and cancel — not just submit. The `operationName` returned by
 * {@see startVideoOperation()} is an opaque handle (for some providers a JSON
 * blob) that the same provider knows how to interpret in the later calls.
 */
interface SupportsAsyncVideo extends VideoGenerationProviderInterface
{
    /**
     * Submit a render and return immediately with an opaque handle.
     *
     * @param array<string, mixed> $options provider options (prompt extras,
     *                                      reference image, resolution,
     *                                      credentials, …)
     *
     * @return array{operationName: string, model?: string, duration?: int, resolution?: ?string}
     */
    public function startVideoOperation(string $prompt, array $options = []): array;

    /**
     * Poll a previously-submitted render exactly once (non-blocking).
     *
     * @param string               $operationName opaque handle from {@see startVideoOperation()}
     * @param array<string, mixed> $options       re-injected credentials etc
     *
     * @return array{done: bool, videoUri: ?string, error: ?string, status?: ?string, percent?: ?int}
     */
    public function pollVideoOperationOnce(string $operationName, array $options = []): array;

    /**
     * Download the produced video as raw bytes.
     *
     * @param array<string, mixed> $options re-injected credentials etc
     */
    public function downloadVideoRaw(string $videoUri, array $options = []): string;

    /**
     * Best-effort: ask the provider to cancel an in-flight render so we stop
     * billing for output nobody is waiting for. Must not throw.
     *
     * @param array<string, mixed> $options re-injected credentials etc
     */
    public function cancelVideoOperation(string $operationName, array $options = []): void;
}
