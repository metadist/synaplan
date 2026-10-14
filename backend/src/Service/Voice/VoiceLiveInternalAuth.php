<?php

declare(strict_types=1);

namespace App\Service\Voice;

use App\Exception\VoiceLiveRejectedException;
use Symfony\Component\HttpFoundation\Request;

/**
 * HMAC for the gateway's callbacks. The signature covers the timestamp and
 * the raw body, so a replay older than a minute is rejected.
 */
final readonly class VoiceLiveInternalAuth
{
    private const SKEW_SECONDS = 60;

    public function __construct(
        private string $secret,
    ) {
    }

    public function assertValid(Request $request): void
    {
        if (strlen($this->secret) < 32) {
            throw $this->unauthorized();
        }

        $timestamp = (string) $request->headers->get('X-Voice-Timestamp', '');
        $signature = (string) $request->headers->get('X-Voice-Signature', '');
        if (!ctype_digit($timestamp) || '' === $signature) {
            throw $this->unauthorized();
        }
        if (abs(time() - (int) $timestamp) > self::SKEW_SECONDS) {
            throw $this->unauthorized();
        }

        $expected = hash_hmac('sha256', $timestamp."\n".$request->getContent(), $this->secret);
        if (!hash_equals($expected, strtolower($signature))) {
            throw $this->unauthorized();
        }
    }

    private function unauthorized(): VoiceLiveRejectedException
    {
        return new VoiceLiveRejectedException(401, ['error' => 'Unauthorized']);
    }
}
