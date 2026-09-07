<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Agent;

use App\Entity\Agent;
use App\Entity\Prompt;
use App\Entity\User;
use App\Repository\AgentRepository;
use App\Repository\ModelRepository;
use App\Repository\PromptRepository;
use App\Service\Agent\AgentRuntimeResolver;
use App\Service\Agent\Definition\AgentDefinition;
use App\Service\Agent\Definition\AgentDefinitionValidator;
use App\Service\Agent\Exception\AgentNotAccessibleException;
use App\Service\ModelConfigService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AgentRuntimeResolverTest extends TestCase
{
    private AgentRepository&MockObject $agents;
    private PromptRepository&MockObject $prompts;
    private ModelRepository&MockObject $models;
    private ModelConfigService&MockObject $modelConfig;
    private AgentRuntimeResolver $resolver;

    protected function setUp(): void
    {
        $this->agents = $this->createMock(AgentRepository::class);
        $this->prompts = $this->createMock(PromptRepository::class);
        $this->models = $this->createMock(ModelRepository::class);
        $this->modelConfig = $this->createMock(ModelConfigService::class);
        $this->resolver = new AgentRuntimeResolver(
            $this->agents,
            $this->prompts,
            $this->models,
            $this->modelConfig,
            new AgentDefinitionValidator(),
        );
    }

    public function testForeignOwnerThrows(): void
    {
        $owner = $this->user(9);
        $agent = new Agent(1, 20, 'contract-review', 'Contract review', AgentDefinition::defaults()->toArray());
        $this->agents->expects(self::once())->method('find')->with(7)->willReturn($agent);

        $this->expectException(AgentNotAccessibleException::class);
        $this->resolver->resolve(7, $owner, true);
    }

    public function testOwnFolderGroupKeyAndModelFallbackNote(): void
    {
        $owner = $this->user(4);
        $draft = AgentDefinition::defaults()->toArray();
        $draft['models']['chat'] = 'missing:model:chat';
        $agent = new Agent(4, 20, 'contract-review', 'Contract review', $draft);

        $prompt = new Prompt();
        $prompt->setOwnerId(4);
        $prompt->setTopic('agent:contract-review');
        $prompt->setPrompt('Review contracts carefully.');

        $this->agents->expects(self::once())->method('find')->with(7)->willReturn($agent);
        $this->prompts->expects(self::once())->method('find')->with(20)->willReturn($prompt);
        $this->models->method('find')->willReturn(null);
        $this->modelConfig->method('getDefaultModel')->willReturnCallback(
            static fn (string $capability): int => 'VECTORIZE' === $capability ? 88 : 99
        );

        $profile = $this->resolver->resolve(7, $owner, true);

        self::assertSame('agent:contract-review', $profile->promptTopic);
        self::assertSame('TASKPROMPT:agent:contract-review', $profile->primaryRagGroupKey());
        self::assertSame(99, $profile->modelIds['chat']);
        self::assertContains('model_fallback:chat', $profile->notes);
        self::assertSame('Review contracts carefully.', $profile->systemPrompt);
        self::assertNull($profile->agentVersionId);
    }

    public function testMissingAgentThrows(): void
    {
        $this->agents->method('find')->willReturn(null);

        $this->expectException(AgentNotAccessibleException::class);
        $this->resolver->resolve(1, $this->user(1), true);
    }

    private function user(int $id): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);

        return $user;
    }
}
