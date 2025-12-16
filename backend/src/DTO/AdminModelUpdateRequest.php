<?php

declare(strict_types=1);

namespace App\DTO;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[OA\Schema(
    schema: 'AdminModelUpdateRequest',
)]
final class AdminModelUpdateRequest
{
    /**
     * @var string[]
     */
    private const ALLOWED_TAGS = [
        'chat',
        'vectorize',
        'pic2text',
        'text2pic',
        'text2vid',
        'sound2text',
        'text2sound',
        'analyze',
        'sort',
    ];

    /**
     * @var string[]
     */
    private const ALLOWED_UNITS = [
        'per1M',
        'perpic',
        'persec',
        'perhour',
        'per1000chars',
        'permin',
        '-',
    ];

    #[Assert\Length(min: 1, max: 32)]
    public ?string $service = null;

    #[Assert\Length(min: 1, max: 24)]
    #[Assert\Choice(choices: self::ALLOWED_TAGS, message: 'Invalid tag')]
    public ?string $tag = null;

    #[Assert\Length(min: 1, max: 96)]
    public ?string $providerId = null;

    #[Assert\Length(min: 1, max: 48)]
    public ?string $name = null;

    #[Assert\Choice(choices: [0, 1], message: 'Selectable must be 0 or 1')]
    public ?int $selectable = null;

    #[Assert\Choice(choices: [0, 1], message: 'Active must be 0 or 1')]
    public ?int $active = null;

    #[Assert\GreaterThanOrEqual(value: 0, message: 'Price in must be >= 0')]
    public ?float $priceIn = null;

    #[Assert\Choice(choices: self::ALLOWED_UNITS, message: 'Invalid inUnit')]
    public ?string $inUnit = null;

    #[Assert\GreaterThanOrEqual(value: 0, message: 'Price out must be >= 0')]
    public ?float $priceOut = null;

    #[Assert\Choice(choices: self::ALLOWED_UNITS, message: 'Invalid outUnit')]
    public ?string $outUnit = null;

    #[Assert\Range(min: 0, max: 10, notInRangeMessage: 'Quality must be between 0 and 10')]
    public ?float $quality = null;

    #[Assert\Range(min: 0, max: 10, notInRangeMessage: 'Rating must be between 0 and 10')]
    public ?float $rating = null;

    public ?string $description = null;

    #[Assert\Type(type: 'array', message: 'json must be an object')]
    public ?array $json = null;

    #[Assert\Callback]
    public function validateAtLeastOneField(ExecutionContextInterface $context): void
    {
        $fields = [
            $this->service,
            $this->tag,
            $this->providerId,
            $this->name,
            $this->selectable,
            $this->active,
            $this->priceIn,
            $this->inUnit,
            $this->priceOut,
            $this->outUnit,
            $this->quality,
            $this->rating,
            $this->description,
            $this->json,
        ];

        foreach ($fields as $val) {
            if (null !== $val) {
                return;
            }
        }

        $context->buildViolation('At least one field must be provided')->addViolation();
    }
}
