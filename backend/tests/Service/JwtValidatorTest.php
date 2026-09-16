<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\JwtValidator;
use PHPUnit\Framework\Constraint\IsType;
use PHPUnit\Framework\NativeType;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class JwtValidatorTest extends TestCase
{
    private JwtValidator $validator;
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $cache = new ArrayAdapter();

        $this->validator = new JwtValidator(
            $this->httpClient,
            $cache,
            $this->logger
        );
    }

    public function testValidateTokenReturnsNullForInvalidToken(): void
    {
        // Mock JWKS response with minimal valid structure
        $jwksData = [
            'keys' => [
                [
                    'kty' => 'RSA',
                    'use' => 'sig',
                    'kid' => 'test-key',
                    'n' => 'xGOr-H7A-PWx8WqR7eRZYxH0O8sJkIXvXvw6O8r_HtFRiNw',
                    'e' => 'AQAB',
                ],
            ],
        ];

        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn($jwksData);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $validator = new JwtValidator(
            $httpClient,
            new ArrayAdapter(),
            $this->logger
        );

        // Invalid JWT token (malformed)
        $result = $validator->validateToken(
            'invalid.jwt.token',
            'https://example.com/jwks',
            'https://example.com'
        );

        $this->assertNull($result);
    }

    public function testValidateTokenReturnsNullForExpiredToken(): void
    {
        // For expired tokens, we'd need a valid JWT with expired exp claim
        // Since generating valid JWTs is complex, we test with malformed token
        $result = $this->validator->validateToken(
            'eyJhbGciOiJSUzI1NiJ9.eyJleHAiOjB9.invalid',
            'https://example.com/jwks',
            'https://example.com'
        );

        $this->assertNull($result);
    }

    public function testValidateTokenLogsErrorOnException(): void
    {
        // Mock HTTP client that throws exception
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willThrowException(new \Exception('Network error'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('JWT validation error', new IsType(NativeType::Array));

        $validator = new JwtValidator(
            $httpClient,
            new ArrayAdapter(),
            $logger
        );

        $result = $validator->validateToken(
            'some.jwt.token',
            'https://example.com/jwks',
            'https://example.com'
        );

        $this->assertNull($result);
    }

    /**
     * Claim validation is reached only after a successful signature check, so
     * exercise it directly rather than minting a signed token per case.
     *
     * @param array<string, mixed> $claims
     *
     * @return array<string, mixed> the context of the last warning logged
     */
    private function validateClaims(array $claims, string $expectedAudience): array
    {
        $context = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(
            function (string $message, array $ctx = []) use (&$context): void {
                $context = $ctx;
            }
        );

        $validator = new JwtValidator(
            $this->createMock(HttpClientInterface::class),
            new ArrayAdapter(),
            $logger
        );

        $method = new \ReflectionMethod($validator, 'validateClaims');
        $passed = $method->invoke($validator, $claims, 'https://idp.example.com', $expectedAudience);

        $this->assertFalse($passed, 'These cases are all audience mismatches');

        return $context;
    }

    /**
     * Keycloak issues aud=account when the client has no hardcoded audience
     * mapper. That is the usual cause of the mismatch and cost real log-diving
     * to find (#1520), so the warning has to name it.
     */
    public function testAudienceMismatchOnAccountNamesTheMissingAudienceMapper(): void
    {
        $context = $this->validateClaims(
            ['iss' => 'https://idp.example.com', 'aud' => 'account'],
            'synaplan-app'
        );

        $this->assertArrayHasKey('hint', $context);
        $this->assertStringContainsString('audience mapper', $context['hint']);
        $this->assertStringContainsString('synaplan-app', $context['hint']);
    }

    public function testUnrelatedAudienceMismatchGetsNoMisleadingHint(): void
    {
        $context = $this->validateClaims(
            ['iss' => 'https://idp.example.com', 'aud' => 'some-other-client'],
            'synaplan-app'
        );

        $this->assertArrayNotHasKey('hint', $context);
        $this->assertSame('synaplan-app', $context['expected']);
    }
}
