<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\AI\Exception\ProviderException;
use App\AI\OpenAI\OpenAiGatewayToolLoop;
use App\AI\Service\AiFacade;
use App\Controller\OpenAICompatibleController;
use App\Entity\User;
use App\Repository\ModelRepository;
use App\Service\Api\OpenAiToolCallingGate;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use App\Service\Usage\RecordedUsage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Vision through the OpenAI-compatible API.
 *
 * A user turn that carries an image is a list of content parts, not a string.
 * Metering used to receive that array as `input_text` and `strlen()` it, so a
 * request that had already been answered correctly by the provider died with
 * "strlen(): Argument #1 ($string) must be of type string, array given" — a
 * hard 500 on a successful completion.
 *
 * Pinned here: the metered text is the readable text of the turn, and metering
 * can never fail the request.
 */
final class OpenAICompatibleControllerMultimodalTest extends TestCase
{
    private const IMAGE_MESSAGE = [
        'role' => 'user',
        'content' => [
            ['type' => 'text', 'text' => 'What is on this page?'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,iVBORw0KGgo=']],
        ],
    ];

    public function testMultimodalTurnIsMeteredAsReadableText(): void
    {
        $user = $this->makeUser();

        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->method('chat')->willReturn([
            'content' => 'A handwritten maths exercise.',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 7, 'total_tokens' => 19],
        ]);

        $metered = null;
        $rateLimits = $this->createMock(RateLimitService::class);
        $rateLimits->expects($this->once())
            ->method('recordUsage')
            ->willReturnCallback(static function (User $u, string $action, array $metadata) use (&$metered): RecordedUsage {
                $metered = $metadata;

                return new RecordedUsage('0.000000', '0.000000', 0, 0, 0);
            });

        $response = $this->invokeNonStream($this->controller($aiFacade, $rateLimits), $user);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($metered);
        self::assertIsString($metered['input_text']);
        self::assertSame("What is on this page?\n[image]", $metered['input_text']);
    }

    public function testMeteringFailureDoesNotFailTheCompletion(): void
    {
        $user = $this->makeUser();

        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->method('chat')->willReturn([
            'content' => 'A handwritten maths exercise.',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
            'usage' => [],
        ]);

        $rateLimits = $this->createMock(RateLimitService::class);
        $rateLimits->method('recordUsage')->willThrowException(new \TypeError('strlen(): Argument #1 ($string) must be of type string, array given'));

        $response = $this->invokeNonStream($this->controller($aiFacade, $rateLimits), $user);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('A handwritten maths exercise.', $body['choices'][0]['message']['content']);
    }

    /**
     * The other half of the report: a valid image the provider itself refused
     * came back as `HTTP 500: Anthropic API Error: Could not process image`.
     * A provider rejecting the request is not an internal error — relaying 500
     * hides the cause and invites clients to retry something that cannot work.
     *
     * @param array{status: int, type: string, code: string} $expected
     */
    #[DataProvider('upstreamFailures')]
    public function testProviderRejectionsKeepTheirStatus(int $upstreamStatus, array $expected): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->method('chat')->willThrowException(new ProviderException(
            'Anthropic API Error: Could not process image (type: invalid_request_error)',
            'anthropic',
            null,
            $upstreamStatus,
        ));

        $response = $this->invokeNonStream(
            $this->controller($aiFacade, $this->createMock(RateLimitService::class)),
            $this->makeUser(),
        );

        self::assertSame($expected['status'], $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        self::assertSame($expected['type'], $body['error']['type']);
        self::assertSame($expected['code'], $body['error']['code']);
        self::assertStringContainsString('Could not process image', (string) $body['error']['message']);
    }

    /**
     * @return array<string, array{int, array{status: int, type: string, code: string}}>
     */
    public static function upstreamFailures(): array
    {
        return [
            'unreadable image' => [400, ['status' => 400, 'type' => 'invalid_request_error', 'code' => 'upstream_error']],
            'bad provider key' => [401, ['status' => 401, 'type' => 'authentication_error', 'code' => 'invalid_api_key']],
            'provider rate limit' => [429, ['status' => 429, 'type' => 'rate_limit_error', 'code' => 'rate_limit_exceeded']],
            'provider outage' => [503, ['status' => 503, 'type' => 'server_error', 'code' => 'internal_error']],
        ];
    }

    public function testLocalFailuresRemainInternalErrors(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->method('chat')->willThrowException(new \RuntimeException('database is gone'));

        $response = $this->invokeNonStream(
            $this->controller($aiFacade, $this->createMock(RateLimitService::class)),
            $this->makeUser(),
        );

        self::assertSame(500, $response->getStatusCode());
    }

    private function controller(AiFacade $aiFacade, RateLimitService $rateLimits): OpenAICompatibleController
    {
        return new OpenAICompatibleController(
            $aiFacade,
            $this->createMock(ModelRepository::class),
            $this->createMock(ModelConfigService::class),
            $rateLimits,
            $this->createConfiguredMock(MessagesGatewayConfig::class, ['isSessionSummaryEnabled' => false]),
            $this->createMock(MessageBusInterface::class),
            new NullLogger(),
            $this->createMock(OpenAiToolCallingGate::class),
            $this->createMock(OpenAiGatewayToolLoop::class),
        );
    }

    private function makeUser(): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(7);

        return $user;
    }

    private function invokeNonStream(
        OpenAICompatibleController $controller,
        User $user,
    ): \Symfony\Component\HttpFoundation\JsonResponse {
        $method = new \ReflectionMethod($controller, 'handleNonStream');

        return $method->invoke(
            $controller,
            $user,
            [self::IMAGE_MESSAGE],
            ['model' => 'claude-sonnet-4-5', 'provider' => 'anthropic'],
            'chatcmpl-synaplan-test',
            1700000000,
            'claude-sonnet-4-5',
            42,
        );
    }
}
