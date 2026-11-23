<?php

declare(strict_types=1);

namespace App\Service\Email;

use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * Decides who may hand an email to the inbound webhook. The webhook maps the
 * `from` address to an account and answers as that account, so the caller
 * has to be the instance's own relay: an administrator's credential (API
 * key), or the built-in mailbox poller, which signs the timestamp and the
 * raw body with a key derived from APP_SECRET. A signature older than a
 * minute is refused.
 */
final readonly class InboundEmailRelayAuth
{
    public const TIMESTAMP_HEADER = 'X-Synaplan-Timestamp';
    public const SIGNATURE_HEADER = 'X-Synaplan-Signature';

    private const KEY_PURPOSE = 'inbound-email-relay';
    private const SKEW_SECONDS = 60;

    public function __construct(
        private string $appSecret,
    ) {
    }

    public function trusts(Request $request, ?User $caller): bool
    {
        if ($caller instanceof User) {
            return in_array('ROLE_ADMIN', $caller->getRoles(), true);
        }

        return $this->isSignedByThisInstance($request);
    }

    /**
     * @return array<string, string> the headers the poller sends with $body
     */
    public function headers(string $body, ?int $signedAt = null): array
    {
        $timestamp = (string) ($signedAt ?? time());

        return [
            self::TIMESTAMP_HEADER => $timestamp,
            self::SIGNATURE_HEADER => $this->signature($timestamp, $body),
        ];
    }

    private function isSignedByThisInstance(Request $request): bool
    {
        if ('' === $this->appSecret) {
            return false;
        }

        $timestamp = (string) $request->headers->get(self::TIMESTAMP_HEADER, '');
        $signature = (string) $request->headers->get(self::SIGNATURE_HEADER, '');
        if (!ctype_digit($timestamp) || '' === $signature) {
            return false;
        }
        if (abs(time() - (int) $timestamp) > self::SKEW_SECONDS) {
            return false;
        }

        return hash_equals($this->signature($timestamp, $request->getContent()), strtolower($signature));
    }

    private function signature(string $timestamp, string $body): string
    {
        $key = hash_hmac('sha256', self::KEY_PURPOSE, $this->appSecret, true);

        return hash_hmac('sha256', $timestamp."\n".$body, $key);
    }
}
