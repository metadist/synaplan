<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\AI\Service\AiFacade;
use App\AI\StructuredOutput\StructuredOutputConfig;
use App\AI\StructuredOutput\StructuredOutputSchema;
use App\DTO\UserMemoryDTO;
use App\Entity\User;
use App\Entity\Widget;
use App\Repository\PromptRepository;
use App\Service\ModelConfigService;
use App\Service\PromptService;
use App\Service\RateLimitService;
use App\Service\UrlContentService;
use App\Service\WidgetService;
use App\Service\WidgetSetupService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WidgetSetupServiceStructuredOutputTest extends TestCase
{
    private AiFacade&MockObject $aiFacade;
    private ModelConfigService&MockObject $modelConfigService;
    private WidgetSetupService $service;
    private \ReflectionMethod $generatePromptMetadataMethod;

    protected function setUp(): void
    {
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);

        $this->modelConfigService->method('getSummaryModelConfig')
            ->willReturn(['model_id' => 1]);
        $this->modelConfigService->method('getDefaultModel')
            ->willReturn(1);
        $this->modelConfigService->method('getProviderForModel')
            ->willReturn('test');
        $this->modelConfigService->method('getModelName')
            ->willReturn('test-model');

        $this->service = new WidgetSetupService(
            $this->createMock(EntityManagerInterface::class),
            $this->aiFacade,
            $this->createMock(PromptService::class),
            $this->createMock(PromptRepository::class),
            $this->createMock(WidgetService::class),
            $this->modelConfigService,
            $this->createMock(RateLimitService::class),
            $this->createMock(UrlContentService::class),
            new NullLogger(),
            $this->alwaysOnStructuredOutputConfig(),
        );

        $this->generatePromptMetadataMethod = new \ReflectionMethod(WidgetSetupService::class, 'generatePromptMetadata');
    }

    private function alwaysOnStructuredOutputConfig(): StructuredOutputConfig
    {
        $config = $this->createMock(StructuredOutputConfig::class);
        $config->method('isEnabled')->willReturn(true);

        return $config;
    }

    private function makeUser(int $id): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);

        return $user;
    }

    public function testSuggestMemoriesForWidgetForwardsTheWidgetMemorySuggestionSchema(): void
    {
        $options = null;
        $this->aiFacade->method('chat')->willReturnCallback(
            function (array $messages, ?int $userId, array $opts) use (&$options): array {
                $options = $opts;

                return ['content' => '{"suggestions": []}'];
            }
        );

        $memories = [new UserMemoryDTO(id: 1, category: 'business', key: 'location', value: 'Berlin')];

        $this->service->suggestMemoriesForWidget($this->makeUser(7), $memories);

        self::assertInstanceOf(StructuredOutputSchema::class, $options['structured_output'] ?? null);
        self::assertSame('widget_memory_suggestions', $options['structured_output']->name);
    }

    /**
     * The object-wrapped schema response (`{"suggestions": [...]}`) must
     * parse exactly like the legacy bare-array response — no parsing
     * change was needed because the existing regex already grabs the
     * innermost `[...]` regardless of what wraps it.
     */
    public function testWrappedSchemaResponseParsesTheSameAsABareArray(): void
    {
        $this->aiFacade->method('chat')->willReturn([
            'content' => '{"suggestions": [{"id": 1, "widgetField": "Location", "responseType": "text", "meta": {}}]}',
        ]);

        $memories = [new UserMemoryDTO(id: 1, category: 'business', key: 'location', value: 'Berlin')];

        $result = $this->service->suggestMemoriesForWidget($this->makeUser(7), $memories);

        self::assertCount(1, $result);
        self::assertSame('Location', $result[0]['widgetField']);
        self::assertSame('text', $result[0]['responseType']);
    }

    public function testGeneratePromptMetadataForwardsTheWidgetPromptMetadataSchema(): void
    {
        $options = null;
        $this->aiFacade->method('chat')->willReturnCallback(
            function (array $messages, ?int $userId, array $opts) use (&$options): array {
                $options = $opts;

                return ['content' => '{"title": "Car Dealer Assistant", "description": "Helps customers find cars."}'];
            }
        );

        $widget = new Widget();
        $widget->setName('My Widget');

        $result = $this->generatePromptMetadataMethod->invoke(
            $this->service,
            $this->makeUser(7),
            $widget,
            [1 => 'Sells cars', 2 => 'Car buyers'],
        );

        self::assertInstanceOf(StructuredOutputSchema::class, $options['structured_output'] ?? null);
        self::assertSame('widget_prompt_metadata', $options['structured_output']->name);
        self::assertSame('Car Dealer Assistant', $result['title']);
    }

    public function testSuggestMemoriesForWidgetOmitsTheSchemaWhenTheKillSwitchIsOff(): void
    {
        $structuredOutputConfig = $this->createMock(StructuredOutputConfig::class);
        $structuredOutputConfig->method('isEnabled')->willReturn(false);

        $service = new WidgetSetupService(
            $this->createMock(EntityManagerInterface::class),
            $this->aiFacade,
            $this->createMock(PromptService::class),
            $this->createMock(PromptRepository::class),
            $this->createMock(WidgetService::class),
            $this->modelConfigService,
            $this->createMock(RateLimitService::class),
            $this->createMock(UrlContentService::class),
            new NullLogger(),
            $structuredOutputConfig,
        );

        $options = null;
        $this->aiFacade->method('chat')->willReturnCallback(
            function (array $messages, ?int $userId, array $opts) use (&$options): array {
                $options = $opts;

                return ['content' => '{"suggestions": []}'];
            }
        );

        $memories = [new UserMemoryDTO(id: 1, category: 'business', key: 'location', value: 'Berlin')];

        $service->suggestMemoriesForWidget($this->makeUser(7), $memories);

        self::assertArrayNotHasKey('structured_output', $options ?? []);
    }

    public function testGeneratePromptMetadataOmitsTheSchemaWhenTheKillSwitchIsOff(): void
    {
        $structuredOutputConfig = $this->createMock(StructuredOutputConfig::class);
        $structuredOutputConfig->method('isEnabled')->willReturn(false);

        $service = new WidgetSetupService(
            $this->createMock(EntityManagerInterface::class),
            $this->aiFacade,
            $this->createMock(PromptService::class),
            $this->createMock(PromptRepository::class),
            $this->createMock(WidgetService::class),
            $this->modelConfigService,
            $this->createMock(RateLimitService::class),
            $this->createMock(UrlContentService::class),
            new NullLogger(),
            $structuredOutputConfig,
        );

        $options = null;
        $this->aiFacade->method('chat')->willReturnCallback(
            function (array $messages, ?int $userId, array $opts) use (&$options): array {
                $options = $opts;

                return ['content' => '{"title": "Car Dealer Assistant", "description": "Helps customers find cars."}'];
            }
        );

        $widget = new Widget();
        $widget->setName('My Widget');

        $method = new \ReflectionMethod(WidgetSetupService::class, 'generatePromptMetadata');
        $method->invoke($service, $this->makeUser(7), $widget, [1 => 'Sells cars', 2 => 'Car buyers']);

        self::assertArrayNotHasKey('structured_output', $options ?? []);
    }
}
