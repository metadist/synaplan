<?php

declare(strict_types=1);

namespace App\Service\FreeApi;

/**
 * A Synaplan Demo call that did not succeed. `errorCode` is one of the
 * contract's `freeapi_*` codes, or a local one: `freeapi_unreachable`,
 * `freeapi_invalid_response`, `freeapi_invalid_key`, `freeapi_not_configured`,
 * `freeapi_not_active`, `freeapi_own_endpoint`, `freeapi_already_active`,
 * `freeapi_chat_ready`.
 *
 * The message is plain English for the console and the log; the UI renders
 * its own copy per code.
 */
final class FreeApiException extends \RuntimeException
{
    public const UNREACHABLE = 'freeapi_unreachable';
    public const INVALID_RESPONSE = 'freeapi_invalid_response';
    public const NOT_CONFIGURED = 'freeapi_not_configured';
    public const NOT_ACTIVE = 'freeapi_not_active';
    public const POW_REQUIRED = 'freeapi_pow_required';
    public const INVALID_KEY = 'freeapi_invalid_key';
    public const OWN_ENDPOINT = 'freeapi_own_endpoint';
    public const ALREADY_ACTIVE = 'freeapi_already_active';
    public const CHAT_READY = 'freeapi_chat_ready';

    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $httpStatus = 0,
        public readonly ?int $retryAfterSeconds = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function notConfigured(): self
    {
        return new self('The Synaplan Demo is turned off on this server. Set FEATURE_FREEAPI_ENABLED=true and FREEAPI_BASE_URL.', self::NOT_CONFIGURED);
    }

    public static function notActive(): self
    {
        return new self('The Synaplan Demo is not active on this server.', self::NOT_ACTIVE);
    }

    public static function unreachable(\Throwable $previous): self
    {
        return new self('The Synaplan Demo server could not be reached. Nothing was changed; try again later.', self::UNREACHABLE, 0, null, $previous);
    }

    public static function invalidResponse(string $what, int $httpStatus = 0): self
    {
        return new self(sprintf('The Synaplan Demo server sent an answer this version cannot read (%s). Nothing was changed.', $what), self::INVALID_RESPONSE, $httpStatus);
    }
}
