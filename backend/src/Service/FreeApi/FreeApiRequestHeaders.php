<?php

declare(strict_types=1);

namespace App\Service\FreeApi;

use App\AI\Provider\OpenAiCompatibleRequestHeaders;

/**
 * Per-call headers for the Demo endpoint only: which person on this install
 * the call is for (pseudonymous, advisory) and which surface it came from.
 * Every other OpenAI-compatible endpoint gets nothing from here.
 *
 * The end-user value is keyed with a secret derived from APP_SECRET. The
 * install id travels on every call and user ids are small integers, so a
 * plain hash of the two could be reversed by the server.
 */
final readonly class FreeApiRequestHeaders implements OpenAiCompatibleRequestHeaders
{
    private const END_USER_KEY_LABEL = 'synaplan-freeapi-end-user';

    public function __construct(
        private FreeApiCallContext $context,
        private FreeApiState $state,
        #[\SensitiveParameter]
        private string $appSecret,
    ) {
    }

    public function forCall(string $endpointName, array $options): array
    {
        if (FreeApiConfig::ENDPOINT_NAME !== $endpointName) {
            return [];
        }

        $headers = ['X-Synaplan-Surface' => $this->context->surface()];
        $userId = $this->context->userId();
        $installId = $this->state->existingInstallId();
        if (null !== $userId && null !== $installId) {
            $headers['X-Synaplan-End-User'] = hash_hmac('sha256', $installId.':'.$userId, hash_hmac('sha256', self::END_USER_KEY_LABEL, $this->appSecret));
        }

        return $headers;
    }
}
