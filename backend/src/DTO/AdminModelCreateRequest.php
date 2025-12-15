<?php

declare(strict_types=1);

namespace App\DTO;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    schema: 'AdminModelCreateRequest',
    required: ['service', 'tag', 'providerId', 'name'],
)]
final class AdminModelCreateRequest
{
    /**
     * @var string Capability tag (lowercase)
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
     * @var string Pricing unit identifiers
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

    #[Assert\NotBlank(normalizer: 'trim', message: 'Service is required')]
    #[Assert\Length(max: 32)]
    public string $service = '';

    #[Assert\NotBlank(normalizer: 'trim', message: 'Tag is required')]
    #[Assert\Choice(choices: self::ALLOWED_TAGS, normalizer: 'strtolower', message: 'Invalid tag')]
    #[Assert\Length(max: 24)]
    public string $tag = '';

    #[Assert\NotBlank(normalizer: 'trim', message: 'Provider ID is required')]
    #[Assert\Length(max: 96)]
    public string $providerId = '';

    #[Assert\NotBlank(normalizer: 'trim', message: 'Name is required')]
    #[Assert\Length(max: 48)]
    public string $name = '';

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
}


