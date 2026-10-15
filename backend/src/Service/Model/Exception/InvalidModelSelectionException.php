<?php

declare(strict_types=1);

namespace App\Service\Model\Exception;

/**
 * A routing-model selection that cannot be stored: not a model id, or a
 * model that is gone or inactive.
 *
 * Thrown by {@see \App\Service\Model\RoutingModelsService}. Mapped to HTTP
 * 400 at the controller boundary.
 */
final class InvalidModelSelectionException extends \InvalidArgumentException
{
    public static function notAnId(): self
    {
        return new self('Invalid model id');
    }

    public static function unavailable(): self
    {
        return new self('Model not found or inactive');
    }
}
