<?php

declare(strict_types=1);

namespace App\Service\Model\Exception;

/**
 * Thrown by {@see \App\Service\Model\PromptModelEligibilityValidator}
 * when the prompt's `aiModel` references a model id that no longer
 * exists in `BMODELS` or has been deactivated.
 *
 * Mapped to HTTP 400 at the controller boundary.
 */
final class InvalidPromptModelException extends \InvalidArgumentException
{
    public function __construct(public readonly int $modelId)
    {
        parent::__construct(sprintf(
            'AI model %d is not available — it has been removed or deactivated. Please pick a different model.',
            $modelId,
        ));
    }
}
