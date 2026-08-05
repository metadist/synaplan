<?php

declare(strict_types=1);

namespace App\DTO;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request DTO for acknowledging an available release.
 */
#[OA\Schema(
    schema: 'AdminUpdateDismissRequest',
    required: ['version'],
)]
final class AdminUpdateDismissRequest
{
    #[Assert\NotBlank(normalizer: 'trim', message: 'Version is required')]
    #[Assert\Length(max: 64, maxMessage: 'Version cannot exceed 64 characters')]
    #[Assert\Regex(
        pattern: '/^\d+(\.\d+){0,3}([-+][0-9A-Za-z.\-]+)?$/',
        message: 'Version must look like 4.0.13 or 4.1.0-rc.1'
    )]
    #[OA\Property(description: 'The release version the admin acknowledged', example: '4.0.13')]
    public string $version = '';
}
