<?php

namespace App\AI\Exception;

class ProviderException extends \RuntimeException
{
    public function __construct(
        string $message,
        private string $providerName,
        private ?array $context = null,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
    
    public function getProviderName(): string
    {
        return $this->providerName;
    }
    
    public function getContext(): ?array
    {
        return $this->context;
    }
}

