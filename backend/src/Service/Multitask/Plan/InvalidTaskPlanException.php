<?php

declare(strict_types=1);

namespace App\Service\Multitask\Plan;

/**
 * Thrown when a decoded task-plan payload cannot be turned into a valid
 * {@see TaskPlan}. Carries the structured validation errors for logging.
 */
final class InvalidTaskPlanException extends \RuntimeException
{
    /**
     * @param list<string> $errors
     */
    public function __construct(
        private readonly array $errors,
        string $message = 'Invalid task plan',
    ) {
        parent::__construct($message.': '.implode('; ', $errors));
    }

    /**
     * @return list<string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
