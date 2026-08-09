<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\AI\Service\AiFacade;
use App\Controller\OpenAICompatibleController;
use App\Entity\User;
use App\Repository\ModelRepository;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use App\Service\Usage\RecordedUsage;
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
