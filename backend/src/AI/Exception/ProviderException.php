<?php

namespace App\AI\Exception;

class ProviderException extends \RuntimeException
{
    private const HTTP_STATUS_MIN = 400;
    private const HTTP_STATUS_MAX = 599;

    /** Longest provider reply kept in a message, context or log line. */
    private const TEXT_EXCERPT_CHARS = 300;

    /** Provider names whose spelling ucfirst() gets wrong. */
    private const DISPLAY_NAMES = ['openai' => 'OpenAI', 'xai' => 'xAI'];

    /**
     * @param int $code the upstream HTTP status when the provider rejected the
     *                  request, so callers can relay it instead of flattening
     *                  every provider error into a 500
     */
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

    /**
     * The upstream HTTP status, when this exception came from a provider
     * response rather than from a local failure.
     */
    public function getUpstreamStatus(): ?int
    {
        $code = $this->getCode();

        return $code >= self::HTTP_STATUS_MIN && $code <= self::HTTP_STATUS_MAX ? $code : null;
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
     * Create exception for content blocked by provider safety filters.
     *
     * @param string      $provider     Provider name (e.g. 'google')
     * @param string      $blockReason  Provider's block reason code (e.g. 'SAFETY', 'RECITATION')
     * @param string|null $textResponse Any text the provider returned alongside the block
     */
    public static function contentBlocked(string $provider, string $blockReason, ?string $textResponse = null): self
    {
        $context = [
            'block_reason' => $blockReason,
            'text_response' => $textResponse,
        ];

        return new self(
            "Content blocked by {$provider} ({$blockReason})",
            $provider,
            $context,
        );
    }

    /**
     * The image model answered without an image, e.g. Gemini with only a text
     * part and finishReason STOP. The message names the provider and quotes
     * the reply, so a REST caller learns what the model said.
     *
     * @param string|null $textResponse First text the provider returned, if any
     * @param string|null $finishReason Provider finish reason, if any
     */
    public static function noImage(string $provider, string $model, ?string $textResponse, ?string $finishReason): self
    {
        $displayName = self::displayName($provider);
        $excerpt = null !== $textResponse ? mb_substr(trim($textResponse), 0, self::TEXT_EXCERPT_CHARS) : '';

        $message = '' !== $excerpt
            ? sprintf('%s returned text instead of an image (%s): "%s"', $displayName, $model, $excerpt)
            : sprintf('%s returned no image (%s%s)', $displayName, $model, null !== $finishReason ? ', finish reason '.$finishReason : '');

        return new self($message, $provider, [
            'text_response' => '' !== $excerpt ? $excerpt : null,
            'finish_reason' => $finishReason,
            'model' => $model,
        ]);
    }

    /** Provider name as people read it: "OpenAI", not "Openai". */
    public static function displayName(string $provider): string
    {
        return self::DISPLAY_NAMES[strtolower($provider)] ?? ucfirst($provider);
    }

    /**
     * Fields for an error log line: the provider and, when it told us, the
     * model, finish reason and an excerpt of its reply.
     *
     * @return array<string, string>
     */
    public function logContext(): array
    {
        $context = ['provider' => $this->providerName];
        foreach (['model', 'finish_reason', 'block_reason'] as $key) {
            if (is_string($this->context[$key] ?? null) && '' !== $this->context[$key]) {
                $context[$key] = $this->context[$key];
            }
        }
        if (is_string($this->context['text_response'] ?? null) && '' !== $this->context['text_response']) {
            $context['text_response'] = mb_substr($this->context['text_response'], 0, self::TEXT_EXCERPT_CHARS);
        }

        return $context;
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
