<?php

declare(strict_types=1);

namespace App\Service\WhatsApp;

/**
 * Decides whether a call to the WhatsApp webhook comes from Meta.
 *
 * Meta signs every POST with the app secret and sends the result as
 * `X-Hub-Signature-256: sha256=<hex>`. A body without that signature, or with
 * one made from another key, could come from anyone who knows the URL. The
 * GET handshake carries the verify token the operator entered at Meta.
 * Without a configured value nothing is accepted.
 */
final readonly class WhatsAppWebhookAuth
{
    public const SIGNATURE_HEADER = 'X-Hub-Signature-256';

    private const SIGNATURE_PREFIX = 'sha256=';

    public function __construct(
        #[\SensitiveParameter]
        private string $appSecret,
        #[\SensitiveParameter]
        private string $verifyToken,
    ) {
    }

    /** True when $signature is Meta's signature of exactly this body. */
    public function acceptsSignature(string $payload, ?string $signature): bool
    {
        if ('' === $this->appSecret || null === $signature || !str_starts_with($signature, self::SIGNATURE_PREFIX)) {
            return false;
        }

        return hash_equals(
            hash_hmac('sha256', $payload, $this->appSecret),
            strtolower(substr($signature, strlen(self::SIGNATURE_PREFIX))),
        );
    }

    /** True when $token is the verify token configured here. */
    public function acceptsVerifyToken(?string $token): bool
    {
        return '' !== $this->verifyToken && null !== $token && hash_equals($this->verifyToken, $token);
    }
}
