<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\StructuredOutput;

use App\AI\Exception\StructuredOutputViolationException;
use App\AI\StructuredOutput\Schema\SortClassificationSchema;
use App\AI\StructuredOutput\StructuredOutputViolationDetector;
use GuzzleHttp\Psr7\Response;
use OpenAI\Exceptions\ErrorException;
use PHPUnit\Framework\TestCase;

final class StructuredOutputViolationDetectorTest extends TestCase
{
    private const GROQ_MESSAGE = "Generated JSON does not match the expected schema. Please adjust your prompt. See 'failed_generation' for more details. Error: jsonschema: '' does not validate with /additionalProperties: additionalProperties 'BDATETIME', 'BTEXT' not allowed";

    private const FAILED_GENERATION = '{"BDATETIME":"20260903124200","BTEXT":"hi","BTOPIC":"general"}';

    /**
     * Build the exception exactly as the SDK's HttpTransporter does: the
     * `error` object goes into the exception, the full body stays on the
     * PSR-7 response — already read once by the transporter.
     */
    private static function groqError(array $error, int $status = 400): ErrorException
    {
        $body = json_encode(['error' => $error], JSON_THROW_ON_ERROR);
        $response = new Response($status, ['Content-Type' => 'application/json'], $body);
        // The transporter consumed the stream before throwing.
        (string) $response->getBody();

        return new ErrorException($error, $response);
    }

    public function testRecognisesGroqsValidationErrorByCodeAndCarriesTheFailedGeneration(): void
    {
        $sdkError = self::groqError([
            'message' => self::GROQ_MESSAGE,
            'type' => 'invalid_request_error',
            'code' => 'json_validate_failed',
            'failed_generation' => self::FAILED_GENERATION,
        ]);
        $schema = SortClassificationSchema::build(['general'], ['en']);

        $violation = StructuredOutputViolationDetector::fromSdkError($sdkError, 'groq', $schema);

        self::assertInstanceOf(StructuredOutputViolationException::class, $violation);
        self::assertSame('groq', $violation->getProviderName());
        self::assertSame(self::GROQ_MESSAGE, $violation->getValidationError());
        self::assertSame(self::FAILED_GENERATION, $violation->getFailedGeneration());
        self::assertSame('sort_classification', $violation->getSchemaName());
        self::assertSame(400, $violation->getUpstreamStatus());
        self::assertSame($sdkError, $violation->getPrevious());
        self::assertTrue($violation->getContext()['structured_output_violation']);
    }

    public function testFallsBackToTheMessageWordingWhenTheCodeIsMissing(): void
    {
        $sdkError = self::groqError(['message' => self::GROQ_MESSAGE, 'type' => 'invalid_request_error']);

        $violation = StructuredOutputViolationDetector::fromSdkError($sdkError, 'groq', null);

        self::assertInstanceOf(StructuredOutputViolationException::class, $violation);
        self::assertNull($violation->getFailedGeneration());
        self::assertNull($violation->getSchemaName());
    }

    public function testIgnoresEveryOtherSdkError(): void
    {
        $sdkError = self::groqError([
            'message' => 'The model `llama-old` has been decommissioned',
            'type' => 'invalid_request_error',
            'code' => 'model_decommissioned',
        ]);

        self::assertNull(StructuredOutputViolationDetector::fromSdkError($sdkError, 'groq', null));
        self::assertNull(StructuredOutputViolationDetector::fromSdkError(new \RuntimeException('Generated JSON does not match the expected schema'), 'groq', null));
    }

    public function testPassesAnAlreadyTypedViolationThrough(): void
    {
        $typed = new StructuredOutputViolationException('groq', 'mismatch', '{}', 'x');

        self::assertSame($typed, StructuredOutputViolationDetector::fromSdkError($typed, 'groq', null));
    }

    public function testSurvivesABodyThatIsNotJson(): void
    {
        $response = new Response(400, ['Content-Type' => 'application/json'], 'not json');
        $sdkError = new ErrorException(['message' => self::GROQ_MESSAGE, 'code' => 'json_validate_failed'], $response);

        $violation = StructuredOutputViolationDetector::fromSdkError($sdkError, 'groq', null);

        self::assertInstanceOf(StructuredOutputViolationException::class, $violation);
        self::assertNull($violation->getFailedGeneration());
    }
}
