<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A free account tried to create or receive an API key while billing is on.
 */
final class ApiKeyPlanRequiredException extends \RuntimeException
{
    public const CODE = 'API_PLAN_REQUIRED';

    public const MESSAGE = 'API keys are part of a paid plan.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
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
