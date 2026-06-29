<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\AI\Service\AiFacade;
use App\Controller\PromptController;
use App\Entity\Prompt;
use App\Entity\User;
use App\Repository\FileRepository;
use App\Repository\MessageRepository;
use App\Repository\PromptMetaRepository;
use App\Repository\PromptRepository;
use App\Repository\UserRepository;
use App\Service\Model\PromptModelEligibilityValidator;
use App\Service\ModelConfigService;
use App\Service\Multitask\Plan\TaskPlanValidator;
use App\Service\Multitask\TaskPlanner;
use App\Service\Prompt\TimeContextBuilder;
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
 * Focused unit tests for the planner-prompt endpoints exposed by
 * PromptController (`GET/PUT /api/v1/prompts/planning`). Heavier CRUD flows
 * are covered by the existing integration test suite.
 */
final class PromptControllerTestRoutingTest extends TestCase
{
    private PromptRepository&MockObject $promptRepository;
    private EntityManagerInterface&MockObject $em;
    private PromptController $controller;

    protected function setUp(): void
    {
        $this->promptRepository = $this->createMock(PromptRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);

        // TaskPlanner is final readonly (cannot be mocked). Give it its own repo
        // mock that returns empty topic lists so renderSystemPrompt() works
        // without crashing on a null foreach.
        $plannerRepo = $this->createMock(PromptRepository::class);
        $plannerRepo->method('getAllTopics')->willReturn([]);
        $plannerRepo->method('getTopicsWithDescriptions')->willReturn([]);

        $this->controller = new PromptController(
            $this->promptRepository,
            $this->createMock(PromptMetaRepository::class),
            $this->createMock(PromptService::class),
            $this->createMock(RateLimitService::class),
            $this->em,
            new NullLogger(),
            $this->createMock(AiFacade::class),
            $this->createMock(MessageRepository::class),
            $this->createMock(FileRepository::class),
            $this->createMock(ModelConfigService::class),
            $this->createMock(VectorStorageFacade::class),
            $this->createMock(PromptModelEligibilityValidator::class),
            new TaskPlanner(
                $this->createMock(AiFacade::class),
                $plannerRepo,
                $this->createMock(ModelConfigService::class),
                new TaskPlanValidator(),
                new NullLogger(),
                $this->createMock(UserRepository::class),
                new TimeContextBuilder(),
            ),
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

    private function makeAdmin(int $id = 1): User&MockObject
    {
        $user = $this->makeUser($id);
        $user->method('isAdmin')->willReturn(true);

        return $user;
    }

    private function makePlanPrompt(string $prompt = 'PLAN TEMPLATE [CAPABILITYLIST]'): Prompt&MockObject
    {
        $row = $this->createMock(Prompt::class);
        $row->method('getId')->willReturn(3);
        $row->method('getTopic')->willReturn('tools:plan');
        $row->method('getShortDescription')->willReturn('Planner');
        $row->method('getPrompt')->willReturn($prompt);

        return $row;
    }

    public function testGetPlanningPromptRequiresAuthentication(): void
    {
        $response = $this->controller->getPlanningPrompt(null);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testGetPlanningPromptReturns404WhenMissing(): void
    {
        $this->promptRepository->method('findByTopic')->with('tools:plan', 0)->willReturn(null);

        $response = $this->controller->getPlanningPrompt($this->makeUser());

        self::assertSame(404, $response->getStatusCode());
    }

    public function testGetPlanningPromptReturnsRawAndRenderedPrompt(): void
    {
        $this->promptRepository->method('findByTopic')
            ->with('tools:plan', 0)
            ->willReturn($this->makePlanPrompt());

        $response = $this->controller->getPlanningPrompt($this->makeUser());
        $body = json_decode((string) $response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($body['success']);
        self::assertSame('tools:plan', $body['prompt']['topic']);
        self::assertSame('PLAN TEMPLATE [CAPABILITYLIST]', $body['prompt']['prompt']);
        // The rendered preview must substitute the capability placeholder.
        self::assertStringNotContainsString('[CAPABILITYLIST]', $body['prompt']['renderedPrompt']);
    }

    public function testUpdatePlanningPromptRequiresAuthentication(): void
    {
        $request = Request::create('/api/v1/prompts/planning', 'PUT', content: json_encode(['prompt' => 'x']));

        $response = $this->controller->updatePlanningPrompt($request, null);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testUpdatePlanningPromptRequiresAdmin(): void
    {
        $request = Request::create('/api/v1/prompts/planning', 'PUT', content: json_encode(['prompt' => 'x']));

        $response = $this->controller->updatePlanningPrompt($request, $this->makeUser(5));

        self::assertSame(403, $response->getStatusCode());
    }

    public function testUpdatePlanningPromptValidatesPayload(): void
    {
        $request = Request::create('/api/v1/prompts/planning', 'PUT', content: json_encode([]));

        $response = $this->controller->updatePlanningPrompt($request, $this->makeAdmin());

        self::assertSame(400, $response->getStatusCode());
    }

    public function testUpdatePlanningPromptSavesAndFlushes(): void
    {
        $row = $this->makePlanPrompt('NEW PLAN');
        $row->expects($this->once())->method('setPrompt')->with('NEW PLAN');
        $this->promptRepository->method('findByTopic')->with('tools:plan', 0)->willReturn($row);
        $this->em->expects($this->once())->method('flush');

        $request = Request::create('/api/v1/prompts/planning', 'PUT', content: json_encode(['prompt' => 'NEW PLAN']));
        $response = $this->controller->updatePlanningPrompt($request, $this->makeAdmin());
        $body = json_decode((string) $response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($body['success']);
        self::assertSame('tools:plan', $body['prompt']['topic']);
    }
}
