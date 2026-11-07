<?php

declare(strict_types=1);

namespace Plugin\Synafastbill\Api;

use Plugin\Synafastbill\Api\Exception\FastBillConfigurationException;

/**
 * FastBill Basic-auth credentials.
 *
 * FastBill authenticates with the account e-mail as the username and the API
 * key as the password (see https://apidocs.fastbill.com/ → Authentifizierung).
 * BOTH parts are required; an API key alone is not sufficient.
 */
final readonly class FastBillCredentials
{
    public function __construct(
        public string $email,
        public string $apiKey,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== trim($this->email) && '' !== trim($this->apiKey);
    }

    /**
     * Validated lazily (not in the constructor) so that credential-free actions
     * such as `/fastbill help` work before the user has configured anything;
     * only an actual API call requires complete credentials.
     */
    public function authorizationHeader(): string
    {
        if ('' === trim($this->email)) {
            throw new FastBillConfigurationException('FastBill account e-mail is required (used as the Basic-auth username).');
        }
        if ('' === trim($this->apiKey)) {
            throw new FastBillConfigurationException('FastBill API key is required.');
        }

        return 'Basic '.base64_encode($this->email.':'.$this->apiKey);
    }

    /**
     * A short, non-reversible hint for logs/UI (never the full key).
     */
    public function maskedApiKey(): string
    {
        $length = mb_strlen($this->apiKey);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4).mb_substr($this->apiKey, -4);
    }
}
