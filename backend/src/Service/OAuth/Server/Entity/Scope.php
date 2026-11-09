<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server\Entity;

use League\OAuth2\Server\Entities\ScopeEntityInterface;

final readonly class Scope implements ScopeEntityInterface
{
    public function __construct(private string $identifier)
    {
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function jsonSerialize(): string
    {
        return $this->identifier;
    }
}
