<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Memory;

use App\AI\Service\AiFacade;
use App\AI\StructuredOutput\StructuredOutputConfig;
use App\AI\StructuredOutput\StructuredOutputSchema;
use App\Entity\User;
use App\Service\Memory\MemoryInputParser;
use App\Service\Memory\UserMemoryService;
use App\Service\ModelConfigService;
use App\Service\Prompt\PromptService;
use App\Service\RateLimitService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Focused unit coverage for `MemoryInputParser::parse`'s structured-output
 * wiring — mirrors the sibling *StructuredOutputTest classes for the other
 * JSON-returning AI call sites.
 */
final class MemoryInputParserStructuredOutputTest extends TestCase
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

    private function buildParser(StructuredOutputConfig $structuredOutputConfig): MemoryInputParser
    {
        $promptService = $this->createMock(PromptService::class);
        $promptService->method('getPromptWithMetadata')->willReturn(['prompt' => null]);

        return new MemoryInputParser(
            $this->memoryService,
            $this->aiFacade,
            $promptService,
            $this->modelConfigService,
            $this->createMock(RateLimitService::class),
            $structuredOutputConfig,
        );
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
        $parser = $this->buildParser($structuredOutputConfig);

        $options = null;
        $this->aiFacade->method('chat')->willReturnCallback(
            function (array $messages, ?int $userId, array $opts) use (&$options): array {
                $options = $opts;

                return ['content' => '{"actions": []}'];
            }
        );

        $parser->parse($this->makeUser(), 'I like Python', null);

        self::assertInstanceOf(StructuredOutputSchema::class, $options['structured_output'] ?? null);
        self::assertSame('user_memory_actions', $options['structured_output']->name);
    }

    public function testParseMemoryOmitsTheSchemaWhenTheKillSwitchIsOff(): void
    {
        $structuredOutputConfig = $this->createMock(StructuredOutputConfig::class);
        $structuredOutputConfig->method('isEnabled')->willReturn(false);
        $parser = $this->buildParser($structuredOutputConfig);

        $options = null;
        $this->aiFacade->method('chat')->willReturnCallback(
            function (array $messages, ?int $userId, array $opts) use (&$options): array {
                $options = $opts;

                return ['content' => '{"actions": []}'];
            }
        );

        $parser->parse($this->makeUser(), 'I like Python', null);

        self::assertArrayNotHasKey('structured_output', $options ?? []);
    }
}
