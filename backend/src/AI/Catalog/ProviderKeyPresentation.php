<?php

declare(strict_types=1);

namespace App\AI\Catalog;

/**
 * How a provider's key row is shown on Admin → AI keys and on the
 * system-config tab. Presentation only: nothing here changes a request.
 */
final readonly class ProviderKeyPresentation
{
    /**
     * @param int     $listOrder              position on Admin → AI keys; 0 for a key that is not listed there
     * @param ?string $displayName            the key row's label where it differs from the adapter's display name
     * @param bool    $freeTier               the provider offers a free tier
     * @param bool    $recommended            the wizard's recommendation
     * @param ?string $fieldDescription       the system-config password-field text of the key
     * @param ?string $secretFieldDescription the same for the secret half of a key + secret pair
     */
    public function __construct(
        public int $listOrder = 0,
        public ?string $displayName = null,
        public bool $freeTier = false,
        public bool $recommended = false,
        public ?string $fieldDescription = null,
        public ?string $secretFieldDescription = null,
    ) {
    }
}
