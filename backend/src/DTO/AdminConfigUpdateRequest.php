<?php

declare(strict_types=1);

namespace App\DTO;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request DTO for updating a configuration value.
 */
#[OA\Schema(
    schema: 'AdminConfigUpdateRequest',
    required: ['key', 'value'],
)]
final class AdminConfigUpdateRequest
{
    #[Assert\NotBlank(normalizer: 'trim', message: 'Key is required')]
    #[Assert\Length(max: 128, maxMessage: 'Key cannot exceed 128 characters')]
    #[Assert\Regex(
        pattern: '/^[A-Z][A-Z0-9_]*$/',
        message: 'Key must be uppercase with underscores (e.g., OLLAMA_BASE_URL)'
    )]
    #[OA\Property(description: 'Configuration key', example: 'OLLAMA_BASE_URL')]
    public string $key = '';

    #[Assert\NotNull(message: 'Value is required')]
    #[Assert\Length(max: 4096, maxMessage: 'Value cannot exceed 4096 characters')]
    #[OA\Property(description: 'Configuration value', example: 'http://localhost:11434')]
    public string $value = '';
}
