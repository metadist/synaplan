<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\AI\Service\AiFacade;
use App\Controller\PromptController;
use App\Entity\User;
use App\Repository\FileRepository;
use App\Repository\MessageRepository;
use App\Repository\PromptMetaRepository;
use App\Repository\PromptRepository;
use App\Service\Message\SynapseIndexer;
use App\Service\Message\SynapseRouter;
use App\Service\Model\PromptModelEligibilityValidator;
use App\Service\ModelConfigService;
use App\Service\PromptService;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use App\Service\RateLimitService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;

/**
 * Focused unit tests for the Synapse dry-run endpoint exposed by
 * PromptController (`POST /api/v1/prompts/test`).
 */
final class PromptControllerTestRoutingTest extends TestCase
{
    private SynapseRouter&MockObject $router;
    private PromptController $controller;

    protected function setUp(): void
    {
        $this->router = $this->createMock(SynapseRouter::class);

        $this->controller = new PromptController(
            $this->createMock(PromptRepository::class),
            $this->createMock(PromptMetaRepository::class),
            $this->createMock(PromptService::class),
            $this->createMock(RateLimitService::class),
            $this->createMock(EntityManagerInterface::class),
            new NullLogger(),
            $this->createMock(AiFacade::class),
            $this->createMock(MessageRepository::class),
            $this->createMock(FileRepository::class),
            $this->createMock(ModelConfigService::class),
            $this->createMock(VectorStorageFacade::class),
            $this->createMock(SynapseIndexer::class),
            $this->router,
            $this->createMock(PromptModelEligibilityValidator::class),
        );

        $container = new Container();
        $container->set('serializer', new class {
            public function serialize(mixed $data, string $format): string
            {
                return json_encode($data, JSON_THROW_ON_ERROR);
            }
        });
        $this->controller->setContainer($container);
    }

    private function makeUser(int $id = 1): User&MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);

        return $user;
    }

    public function testTestRoutingRequiresAuthentication(): void
    {
        $request = Request::create('/api/v1/prompts/test', 'POST', content: json_encode(['text' => 'hi']));

        $response = $this->controller->testRouting($request, null);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testTestRoutingRequiresTextField(): void
    {
        $request = Request::create('/api/v1/prompts/test', 'POST', content: json_encode([]));

        $response = $this->controller->testRouting($request, $this->makeUser());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('Missing required field: text', $body['error']);
    }

    public function testTestRoutingDelegatesToRouterDryRun(): void
    {
        $user = $this->makeUser(7);

        $this->router->expects($this->once())
            ->method('dryRun')
            ->with('How do I write a PHP loop?', 7)
            ->willReturn([
                'query' => 'How do I write a PHP loop?',
                'router_available' => true,
                'classification' => [
                    'use_case' => 'coding',
                    'canonical_topic' => 'general',
                    'confidence' => 0.85,
                    'is_compound' => false,
                    'steps' => [],
                    'model_version' => 'v20260528',
                    'router_latency_ms' => 2.1,
                    'alias_target' => 'general',
                    'implied_media' => null,
                ],
                'fallback_reason' => null,
                'latency_ms' => 11.4,
                'error' => null,
            ]);

        $request = Request::create(
            '/api/v1/prompts/test',
            'POST',
            content: json_encode(['text' => 'How do I write a PHP loop?'])
        );
        $response = $this->controller->testRouting($request, $user);
        $body = json_decode((string) $response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($body['success']);
        self::assertTrue($body['router_available']);
        self::assertSame('coding', $body['classification']['use_case']);
        self::assertSame('general', $body['classification']['canonical_topic']);
    }

    public function testTestRoutingHandlesRouterUnavailable(): void
    {
        $this->router->expects($this->once())
            ->method('dryRun')
            ->with('test', 1)
            ->willReturn([
                'query' => 'test',
                'router_available' => false,
                'classification' => null,
                'fallback_reason' => 'router_unavailable_or_disabled',
                'latency_ms' => 0.5,
                'error' => null,
            ]);

        $request = Request::create(
            '/api/v1/prompts/test',
            'POST',
            content: json_encode(['text' => 'test'])
        );
        $response = $this->controller->testRouting($request, $this->makeUser());
        $body = json_decode((string) $response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($body['success']);
        self::assertFalse($body['router_available']);
        self::assertSame('router_unavailable_or_disabled', $body['fallback_reason']);
    }
}
