<?php

declare(strict_types=1);

namespace App\Service\FreeApi;

/**
 * The answer of `POST /v1/free/keys` (contract/v1/issuance.json), validated.
 */
final readonly class FreeApiIssuedKey
{
    private const MAX_KEY_LENGTH = 256;

    /**
     * @param array<string, int|float>              $limits
     * @param list<array{id: string, name: string}> $aliases
     */
    private function __construct(
        public string $key,
        public int $keyId,
        public int $expiresAt,
        public int $daysLeft,
        public array $limits,
        public array $aliases,
        public int $protocolMin,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     *
     * @throws FreeApiException when a required field is missing or malformed
     */
    public static function fromResponse(array $body): self
    {
        $key = $body['key'] ?? null;
        if (!\is_string($key) || '' === $key || \strlen($key) > self::MAX_KEY_LENGTH || 1 !== preg_match('/^[\x21-\x7e]+$/', $key)) {
            throw FreeApiException::invalidResponse('key');
        }
        foreach (['key_id', 'expires_at'] as $field) {
            if (!\is_int($body[$field] ?? null)) {
                throw FreeApiException::invalidResponse($field);
            }
        }

        $limits = [];
        foreach (\is_array($body['limits'] ?? null) ? $body['limits'] : [] as $name => $value) {
            if (\is_string($name) && (\is_int($value) || \is_float($value))) {
                $limits[$name] = $value;
            }
        }

        $aliases = [];
        foreach (\is_array($body['aliases'] ?? null) ? $body['aliases'] : [] as $alias) {
            if (\is_array($alias) && \is_string($alias['id'] ?? null) && \is_string($alias['name'] ?? null)) {
                $aliases[] = ['id' => $alias['id'], 'name' => $alias['name']];
            }
        }

        return new self(
            $key,
            $body['key_id'],
            $body['expires_at'],
            \is_int($body['days_left'] ?? null) ? $body['days_left'] : 0,
            $limits,
            $aliases,
            \is_int($body['protocol_min'] ?? null) ? $body['protocol_min'] : 1,
        );
    }
}
