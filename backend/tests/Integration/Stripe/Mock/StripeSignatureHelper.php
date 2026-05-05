<?php

declare(strict_types=1);

namespace App\Tests\Integration\Stripe\Mock;

/**
 * Builds Stripe-Signature headers for tests so the webhook controller's
 * \Stripe\Webhook::constructEvent() accepts our locally crafted payloads.
 *
 * Mirrors the algorithm in StripeWebhookSignature: HMAC-SHA256 over
 * "{timestamp}.{payload}" using the configured webhook secret. Stripe's
 * tolerance window is 5 minutes; using time() means tests are valid for the
 * duration of the run.
 */
final class StripeSignatureHelper
{
    public static function header(string $payload, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $signedPayload = $timestamp.'.'.$payload;
        $signature = hash_hmac('sha256', $signedPayload, $secret);

        return sprintf('t=%d,v1=%s', $timestamp, $signature);
    }
}
