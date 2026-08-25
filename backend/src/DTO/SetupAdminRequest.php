<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * First administrator created by the setup wizard.
 *
 * The constraints are deliberately identical to {@see RegisterRequest}: the
 * account created here is a normal local account, and a weaker rule for the very
 * first — and most privileged — password would be the wrong trade-off. Kept as a
 * separate DTO because the wizard has no reCAPTCHA token (no key is configured
 * yet at this point in an install's life).
 */
class SetupAdminRequest
{
    #[Assert\NotBlank(message: 'Email is required')]
    #[Assert\Email(message: 'Invalid email format')]
    #[Assert\Length(max: 128)]
    public string $email;

    #[Assert\NotBlank(message: 'Password is required')]
    #[Assert\Length(
        min: 8,
        max: 64,
        minMessage: 'Password must be at least 8 characters',
        maxMessage: 'Password cannot be longer than 64 characters'
    )]
    #[Assert\Regex(
        pattern: '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/',
        message: 'Password must contain at least one uppercase letter, one lowercase letter, and one number'
    )]
    public string $password;
}
