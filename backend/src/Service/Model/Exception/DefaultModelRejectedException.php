<?php

declare(strict_types=1);

namespace App\Service\Model\Exception;

/**
 * A group policy forbids the default model a member tried to save.
 *
 * Thrown by {@see \App\Service\Model\ModelDefaultsService::save()} before
 * anything is written. The controller answers 409 for a locked setting and
 * 422 for a model outside the allow-list; `code` is the i18n key the
 * frontend shows.
 */
final class DefaultModelRejectedException extends \DomainException
{
    public const SETTING_LOCKED = 'iam.settingLocked';
    public const MODEL_NOT_ALLOWED = 'iam.modelNotAllowed';

    private function __construct(
        string $message,
        public readonly string $reason,
        public readonly string $capability,
    ) {
        parent::__construct($message);
    }

    public static function locked(string $capability): self
    {
        return new self('This setting is set by your administrator', self::SETTING_LOCKED, $capability);
    }

    public static function notAllowed(string $capability): self
    {
        return new self('That model is not allowed for your account.', self::MODEL_NOT_ALLOWED, $capability);
    }

    public function isLocked(): bool
    {
        return self::SETTING_LOCKED === $this->reason;
    }
}
