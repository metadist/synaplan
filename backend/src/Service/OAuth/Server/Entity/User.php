<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server\Entity;

use League\OAuth2\Server\Entities\UserEntityInterface;

final readonly class User implements UserEntityInterface
{
    public function __construct(private int $id)
    {
    }

    public function getIdentifier(): string
    {
        return (string) $this->id;
    }
}
