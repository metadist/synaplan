<?php

declare(strict_types=1);

namespace App\AI\Exception;

/**
 * The provider validated the model's OWN generation against the requested
 * JSON schema and rejected it (Groq: HTTP 400 `json_validate_failed`,
 * "Generated JSON does not match the expected schema").
 *
 * This is deliberately its own type: the provider is healthy, the credentials
 * are fine and the request was well-formed — the model simply produced a
 * shape the schema forbids (typically by echoing input fields into its
 * answer). That makes it the one provider error which is worth healing
 * in-process rather than surfacing to the user:
 *
 *   - {@see \App\AI\StructuredOutput\StructuredOutputRecovery} can often
 *     SALVAGE the rejected generation without a second request (the
 *     required fields are all there, only extra keys need dropping), or
 *     retry once with a corrective turn; {@see \App\AI\Service\AiFacade::chat()}
 *     runs that loop.
 *   - {@see \App\AI\Health\FailureClassifier} files it as a request-caused
 *     failure so a burst of them cannot switch a model off, and
 *     {@see \App\Service\CircuitBreaker} does not count it as an outage.
 *   - Call sites with a safe default ({@see \App\Service\Message\MessageSorter})
 *     fall back instead of failing the whole turn.
 */
final class StructuredOutputViolationException extends ProviderException
{
    private const HTTP_BAD_REQUEST = 400;

    /**
     * @param string      $providerName     provider that rejected the generation
     * @param string      $validationError  the provider's own description of the mismatch
     * @param string|null $failedGeneration the rejected model output verbatim (Groq's
     *                                      `failed_generation`), when the provider returned it
     * @param string|null $schemaName       the {@see \App\AI\StructuredOutput\StructuredOutputSchema::$name}
     *                                      requested, when known
     */
    public function __construct(
        string $providerName,
        private readonly string $validationError,
        private readonly ?string $failedGeneration = null,
        private readonly ?string $schemaName = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            ucfirst($providerName).' rejected the generated JSON against the requested schema: '.$validationError,
            $providerName,
            [
                'structured_output_violation' => true,
                'schema' => $schemaName,
                'failed_generation_length' => null === $failedGeneration ? 0 : strlen($failedGeneration),
            ],
            self::HTTP_BAD_REQUEST,
            $previous,
        );
    }

    public function getValidationError(): string
    {
        return $this->validationError;
    }

    public function getFailedGeneration(): ?string
    {
        return $this->failedGeneration;
    }

    public function getSchemaName(): ?string
    {
        return $this->schemaName;
    }
}
