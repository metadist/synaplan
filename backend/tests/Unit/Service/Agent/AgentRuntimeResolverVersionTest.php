<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Agent;

use App\Entity\Agent;
use App\Entity\AgentVersion;
use App\Entity\Prompt;
use App\Entity\User;
use App\Repository\AgentRepository;
use App\Repository\AgentVersionRepository;
use App\Repository\MessageMetaRepository;
use App\Repository\ModelRepository;
use App\Repository\PromptRepository;
use App\Service\Agent\AgentAccess;
use App\Service\Agent\AgentRuntimeResolver;
use App\Service\Agent\Definition\AgentDefinition;
use App\Service\Agent\Definition\AgentDefinitionValidator;
use App\Service\Agent\Exception\AgentArchivedException;
use App\Service\Agent\Exception\AgentNotAccessibleException;
use App\Service\ModelConfigService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AgentRuntimeResolverVersionTest extends TestCase
{
    private AgentRepository&MockObject $agents;
    private AgentVersionRepository&MockObject $versions;
    private AgentAccess&MockObject $access;
    private MessageMetaRepository&MockObject $metas;
    private AgentRuntimeResolver $resolver;

    protected function setUp(): void
    {
        $this->agents = $this->createMock(AgentRepository::class);
        $this->versions = $this->createMock(AgentVersionRepository::class);
        $this->access = $this->createMock(AgentAccess::class);
        $this->metas = $this->createMock(MessageMetaRepository::class);
        $prompts = $this->createMock(PromptRepository::class);
        $prompt = new Prompt();
        $prompt->setTopic('agent:contract-review');
        $prompt->setPrompt('Live draft text');
        $prompts->method('find')->willReturn($prompt);

        $this->resolver = new AgentRuntimeResolver(
            $this->agents,
            $this->versions,
            $prompts,
            $this->createMock(ModelRepository::class),
            $this->createMock(ModelConfigService::class),
            new AgentDefinitionValidator(),
            $this->access,
            $this->metas,
        );
    }

    public function testOwnerDraftIgnoresPublishedSnapshot(): void
    {
        $agent = $this->publishedAgent();
        $this->agents->method('find')->willReturn($agent);
        $this->versions->expects(self::never())->method('find');

        $profile = $this->resolver->resolve(7, $this->user(4), true);

        self::assertNull($profile->agentVersionId);
        self::assertSame('Live draft text', $profile->systemPrompt);
    }

    public function testMemberUsesPublishedVersion(): void
    {
        $agent = $this->publishedAgent();
        $version = new AgentVersion(7, 2, AgentDefinition::defaults()->toArray(), 'Published text', 4, 'v2');
        (new \ReflectionProperty(AgentVersion::class, 'id'))->setValue($version, 88);

        $this->agents->method('find')->willReturn($agent);
        $this->access->method('can')->willReturn(true);
        $this->versions->method('find')->willReturn($version);

        $profile = $this->resolver->resolve(7, $this->user(9), false);

        self::assertSame(88, $profile->agentVersionId);
        self::assertSame('Published text', $profile->systemPrompt);
    }

    public function testArchivedNewChatIs410(): void
    {
        $agent = $this->publishedAgent();
        $agent->setStatus(Agent::STATUS_ARCHIVED);
        $this->agents->method('find')->willReturn($agent);
        $this->access->method('can')->willReturn(true);

        $this->expectException(AgentArchivedException::class);
        $this->resolver->resolve(7, $this->user(9), false, null);
    }

    public function testArchivedExistingChatContinues(): void
    {
        $agent = $this->publishedAgent();
        $agent->setStatus(Agent::STATUS_ARCHIVED);
        $version = new AgentVersion(7, 2, AgentDefinition::defaults()->toArray(), 'Published text', 4);
        (new \ReflectionProperty(AgentVersion::class, 'id'))->setValue($version, 88);

        $this->agents->method('find')->willReturn($agent);
        $this->access->method('can')->willReturn(true);
        $this->metas->method('chatHasAgent')->willReturn(true);
        $this->versions->method('find')->willReturn($version);

        $profile = $this->resolver->resolve(7, $this->user(9), false, 55);
        self::assertSame(88, $profile->agentVersionId);
    }

    public function testNoUseIs404(): void
    {
        $this->agents->method('find')->willReturn($this->publishedAgent());
        $this->access->method('can')->willReturn(false);

        $this->expectException(AgentNotAccessibleException::class);
        $this->resolver->resolve(7, $this->user(9), false);
    }

    private function publishedAgent(): Agent
    {
        $agent = new Agent(4, 20, 'contract-review', 'Contract review', AgentDefinition::defaults()->toArray());
        (new \ReflectionProperty(Agent::class, 'id'))->setValue($agent, 7);
        $agent->setPublishedVersionId(3);
        $agent->setStatus(Agent::STATUS_PUBLISHED);

        return $agent;
    }

    private function user(int $id): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);

        return $user;
    }
}
