<?php

declare(strict_types=1);

namespace App\Service\Auth;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Server-side check of a Cloudflare Turnstile token.
 *
 * Configured by `TURNSTILE_SECRET_KEY` (the matching `TURNSTILE_SITE_KEY` goes
 * to the browser through the runtime config). When configured it takes
 * precedence over reCAPTCHA v3 in {@see SignupChallenge}.
 *
 * Fails closed: a missing token, a Cloudflare error, a network problem or an
 * unexpected answer all count as "not verified". reCAPTCHA used to fail open on
 * API errors, which is one of the reasons it did not hold on 2026-10-24.
 */
final readonly class TurnstileVerifier
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    private const TIMEOUT_SECONDS = 5.0;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $secretKey,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== trim($this->secretKey);
    }

    public function verify(string $token, ?string $remoteIp): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }
        if ('' === trim($token)) {
            $this->logger->info('Turnstile: no token supplied');

            return false;
        }

        try {
            $response = $this->httpClient->request('POST', self::VERIFY_URL, [
                'timeout' => self::TIMEOUT_SECONDS,
                'body' => [
                    'secret' => $this->secretKey,
                    'response' => $token,
                    'remoteip' => (string) $remoteIp,
                ],
            ]);
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->error('Turnstile: verification request failed, refusing', ['error' => $e->getMessage()]);

            return false;
        }

        if (true !== ($data['success'] ?? false)) {
            $this->logger->warning('Turnstile: token rejected', [
                'error_codes' => $data['error-codes'] ?? [],
            ]);

            return false;
        }

        return true;
    }
}
