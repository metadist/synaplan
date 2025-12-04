<?php

namespace App\AI\Exception;

class ProviderException extends \RuntimeException
{
    public function __construct(
        string $message,
        private string $providerName = 'unknown',
        private ?array $context = null,
        int $code = 0,
        ?\Throwable $previous = null,
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

    /**
     * Create user-friendly exception for missing API key.
     */
    public static function missingApiKey(string $provider, string $envVarName): self
    {
        $message = "API key not configured for provider '{$provider}'. ";
        $message .= "Please set the {$envVarName} environment variable.";

        $context = [
            'env_var' => $envVarName,
            'provider_type' => 'external',
            'setup_instructions' => "Add {$envVarName}=your-api-key to your .env.local file",
        ];

        return new self($message, $provider, $context);
    }

    /**
     * Create user-friendly exception with installation instructions.
     */
    public static function noModelAvailable(string $modelType, string $provider, ?string $requestedModel = null, ?\Throwable $previous = null): self
    {
        if ($requestedModel) {
            $message = "Model '{$requestedModel}' not found for provider '{$provider}'. ";
        } else {
            $message = "No {$modelType} model available for provider '{$provider}'. ";
        }

        if ('ollama' === strtolower($provider)) {
            if ($requestedModel) {
                // Show download command for the requested model
                $message .= "Download it using: docker compose exec ollama ollama pull {$requestedModel}";
                $context = [
                    'requested_model' => $requestedModel,
                    'install_command' => "docker compose exec ollama ollama pull {$requestedModel}",
                    'suggested_models' => [
                        'quick' => ['qwen2.5:3b', 'phi4:latest'],
                        'medium' => ['llama3.2:latest', 'mistral:latest'],
                        'large' => ['qwen2.5:14b', 'llama3.1:8b'],
                    ],
                ];
            } else {
                // Generic message if no specific model was requested
                $message .= 'Download a model using: docker compose exec ollama ollama pull <model-name>';
                $context = [
                    'suggested_models' => [
                        'quick' => ['qwen2.5:3b', 'phi4:latest'],
                        'medium' => ['llama3.2:latest', 'mistral:latest'],
                        'large' => ['qwen2.5:14b', 'llama3.1:8b'],
                    ],
                    'install_command' => 'docker compose exec ollama ollama pull qwen2.5:3b',
                ];
            }
        } else {
            $context = null;
        }

        return new self($message, $provider, $context, 0, $previous);
    }
}
