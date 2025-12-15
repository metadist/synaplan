<?php

declare(strict_types=1);

namespace App\DTO;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(schema: 'AdminModelsImportApplyRequest', required: ['sql'])]
final class AdminModelsImportApplyRequest
{
    #[Assert\NotBlank(normalizer: 'trim', message: 'sql is required')]
    #[Assert\Length(max: 200000, maxMessage: 'sql is too large')]
    public string $sql = '';

    /**
     * Safety switch: if false, DELETE statements will be rejected.
     */
    public bool $allowDelete = false;
}


