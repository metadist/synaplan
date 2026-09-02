<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\AI\Service\AiFacade;
use App\AI\StructuredOutput\StructuredOutputConfig;
use App\AI\StructuredOutput\StructuredOutputSchema;
use App\Controller\UserMemoryController;
use App\Entity\User;
use App\Service\ModelConfigService;
use App\Service\PromptService;
use App\Service\RateLimitService;
use App\Service\UserMemoryService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;

/**
 * Focused unit coverage for `UserMemoryController::parseMemory`'s
 * structured-output wiring — mirrors the sibling *StructuredOutputTest
 * classes for the other JSON-returning AI call sites.
 */
final class UserMemoryControllerStructuredOutputTest extends TestCase
{
    private AiFacade&MockObject $aiFacade;
    private UserMemoryService&MockObject $memoryService;
    private ModelConfigService&MockObject $modelConfigService;

    protected function setUp(): void
    {
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->memoryService = $this->createMock(UserMemoryService::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);

        $this->modelConfigService->method('getMemoryModelConfig')
            ->willReturn(['provider' => 'test', 'model' => 'test-model', 'model_id' => 1]);
        $this->memoryService->method('searchMemories')->willReturn([]);
        $this->memoryService->method('getUserMemories')->willReturn([]);
    }

    private function buildController(StructuredOutputConfig $structuredOutputConfig): UserMemoryController
    {
        $promptService = $this->createMock(PromptService::class);
        $promptService->method('getPromptWithMetadata')->willReturn(['prompt' => null]);

        $controller = new UserMemoryController(
            $this->memoryService,
            $this->aiFacade,
            $promptService,
            $this->modelConfigService,
            $this->createMock(RateLimitService::class),
            $structuredOutputConfig,
        );

        $container = new Container();
        $container->set('serializer', new class {
            public function serialize(mixed $data, string $format): string
            {
                return json_encode($data, \JSON_THROW_ON_ERROR);
            }
        });
        $controller->setContainer($container);

        return $controller;
    }

    private function makeUser(int $id = 1): User&MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('isAdmin')->willReturn(false);

        return $user;
    }

    public function testParseMemoryForwardsTheUserMemoryActionSchema(): void
    {
        $structuredOutputConfig = $this->createMock(StructuredOutputConfig::class);
        $structuredOutputConfig->method('isEnabled')->willReturn(true);
        $controller = $this->buildController($structuredOutputConfig);

        $options = null;
        $this->aiFacade->method('chat')->willReturnCallback(
            function (array $messages, ?int $userId, array $opts) use (&$options): array {
                $options = $opts;

                return ['content' => '{"actions": []}'];
            }
        );

        $request = new Request([], [], [], [], [], [], json_encode(['input' => 'I like Python'], \JSON_THROW_ON_ERROR));
        $controller->parseMemory($request, $this->makeUser());

        self::assertInstanceOf(StructuredOutputSchema::class, $options['structured_output'] ?? null);
        self::assertSame('user_memory_actions', $options['structured_output']->name);
    }

    public function testParseMemoryOmitsTheSchemaWhenTheKillSwitchIsOff(): void
    {
        $structuredOutputConfig = $this->createMock(StructuredOutputConfig::class);
        $structuredOutputConfig->method('isEnabled')->willReturn(false);
        $controller = $this->buildController($structuredOutputConfig);

        $options = null;
        $this->aiFacade->method('chat')->willReturnCallback(
            function (array $messages, ?int $userId, array $opts) use (&$options): array {
                $options = $opts;

                return ['content' => '{"actions": []}'];
            }
        );

        $request = new Request([], [], [], [], [], [], json_encode(['input' => 'I like Python'], \JSON_THROW_ON_ERROR));
        $controller->parseMemory($request, $this->makeUser());

        self::assertArrayNotHasKey('structured_output', $options ?? []);
    }
}
