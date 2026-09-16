<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Access policy chosen on the last step of the setup wizard.
 *
 * Both fields are required rather than defaulted: this is the one moment where an
 * operator consciously decides who may use the instance, and silently falling
 * back to "open" would be the wrong default to hide in a DTO.
 */
class SetupCompleteRequest
{
    #[Assert\NotNull(message: 'registrationEnabled is required')]
    #[Assert\Type('bool')]
    public bool $registrationEnabled;

    #[Assert\NotNull(message: 'guestChatEnabled is required')]
    #[Assert\Type('bool')]
    public bool $guestChatEnabled;
}
