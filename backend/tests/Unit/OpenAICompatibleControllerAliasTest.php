<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\AI\OpenAI\OpenAiGatewayToolLoop;
use App\AI\Service\AiFacade;
use App\Controller\OpenAICompatibleController;
use App\Entity\User;
use App\Repository\ModelRepository;
use App\Service\Agent\AssistantAliasResolver;
use App\Service\Api\OpenAiToolCallingGate;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class OpenAICompatibleControllerAliasTest extends TestCase
{
    public function testListModelsAppendsAssistantAlias(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(3);

        $aliases = $this->createMock(AssistantAliasResolver::class);
        $aliases->expects(self::once())->method('listAliases')->with($user)->willReturn([
            [
                'id' => 'assistant:contract-review',
                'object' => 'model',
                'created' => 1700000000,
                'owned_by' => 'synaplan',
            ],
        ]);

        $models = $this->createMock(ModelRepository::class);
        $models->method('findBy')->willReturn([]);

        $controller = new OpenAICompatibleController(
            $this->createMock(AiFacade::class),
            $models,
            $this->createMock(ModelConfigService::class),
            $this->createMock(RateLimitService::class),
            $this->createMock(MessagesGatewayConfig::class),
            $this->createMock(MessageBusInterface::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(OpenAiToolCallingGate::class),
            $this->createMock(OpenAiGatewayToolLoop::class),
            $aliases,
        );

        $response = $controller->listModels($user);
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('assistant:contract-review', $data['data'][0]['id']);
    }
}
