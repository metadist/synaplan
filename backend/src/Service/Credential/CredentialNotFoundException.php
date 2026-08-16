<?php

declare(strict_types=1);

namespace App\Service\Credential;

final class CredentialNotFoundException extends \RuntimeException
{
    public function __construct(int $credentialId)
    {
        parent::__construct(sprintf('Credential %d was not found for this owner', $credentialId));
    }
}
