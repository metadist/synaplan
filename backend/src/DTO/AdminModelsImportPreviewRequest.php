<?php

declare(strict_types=1);

namespace App\DTO;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[OA\Schema(schema: 'AdminModelsImportPreviewRequest')]
final class AdminModelsImportPreviewRequest
{
    /**
     * @var string[]
     */
    #[Assert\Type(type: 'array', message: 'urls must be an array')]
    #[Assert\All(constraints: [
        new Assert\Type(type: 'string', message: 'Each url must be a string'),
        new Assert\Url(message: 'Invalid URL'),
        new Assert\Length(max: 2048),
    ])]
    public array $urls = [];

    #[Assert\Type(type: 'string')]
    #[Assert\Length(max: 200000, maxMessage: 'textDump is too large')]
    public string $textDump = '';

    public bool $allowDelete = false;

    #[Assert\Callback]
    public function validateInputSources(ExecutionContextInterface $context): void
    {
        $hasUrls = !empty($this->urls);
        $hasText = '' !== trim($this->textDump);

        if ($hasUrls || $hasText) {
            return;
        }

        $context->buildViolation('Either urls or textDump must be provided')->addViolation();
    }
}


