<?php

declare(strict_types=1);

namespace App\DTO;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request DTO for the update-notice master switch.
 *
 * Nullable with a NotNull constraint on purpose: an omitted field must be a
 * validation error, not a silent "false" that turns the check off.
 */
#[OA\Schema(
    schema: 'AdminUpdateSettingsRequest',
    required: ['checkEnabled'],
)]
final class AdminUpdateSettingsRequest
{
    #[Assert\NotNull(message: 'checkEnabled is required')]
    #[Assert\Type(type: 'bool', message: 'checkEnabled must be a boolean')]
    #[OA\Property(description: 'Whether the daily update check may run', example: true)]
    public ?bool $checkEnabled = null;
}
