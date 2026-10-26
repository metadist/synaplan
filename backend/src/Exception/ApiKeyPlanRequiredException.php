<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * An account may not create or receive an API key: a free account while
 * billing is on, or any account whose address is not yet verified (a
 * pay-first buyer before opening our mail could otherwise keep a key past
 * the mailbox owner's takeover).
 */
final class ApiKeyPlanRequiredException extends \RuntimeException
{
    public const CODE = 'API_PLAN_REQUIRED';

    public const MESSAGE = 'API keys are part of a paid plan.';

    public const CODE_ADDRESS_UNVERIFIED = 'API_ADDRESS_UNVERIFIED';

    public const MESSAGE_ADDRESS_UNVERIFIED = 'Confirm your email address first: open the link we sent you, then create the key.';

    public const CODE_SESSION_ENDED = 'API_SESSION_ENDED';

    public const MESSAGE_SESSION_ENDED = 'This sign-in ended when the email address was confirmed. Sign in again, then create the key.';

    public function __construct(
        private readonly string $reasonCode = self::CODE,
        string $message = self::MESSAGE,
    ) {
        parent::__construct($message);
    }

    public static function addressUnverified(): self
    {
        return new self(self::CODE_ADDRESS_UNVERIFIED, self::MESSAGE_ADDRESS_UNVERIFIED);
    }

    public static function sessionEnded(): self
    {
        return new self(self::CODE_SESSION_ENDED, self::MESSAGE_SESSION_ENDED);
    }

    /**
     * @return array{success: false, error: string, code: string, message: string}
     */
    public function body(): array
    {
        return [
            'success' => false,
            'error' => $this->getMessage(),
            'code' => $this->reasonCode,
            'message' => $this->getMessage(),
        ];
    }

    /**
     * @return array{success: false, error: string, code: string, message: string}
     */
    public static function responseBody(): array
    {
        return [
            'success' => false,
            'error' => self::MESSAGE,
            'code' => self::CODE,
            'message' => self::MESSAGE,
        ];
    }
}
