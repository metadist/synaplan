<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Agent;

use App\Entity\Agent;
use App\Entity\Prompt;
use App\Entity\User;
use App\Repository\AgentRepository;
use App\Repository\AgentVersionRepository;
use App\Repository\PromptRepository;
use App\Repository\UserRepository;
use App\Service\Agent\AgentAccess;
use App\Service\Agent\AgentCascadeCleanup;
use App\Service\Agent\AgentSerializer;
use App\Service\Agent\AgentService;
use App\Service\Agent\Definition\AgentDefinition;
use App\Service\Agent\Definition\AgentDefinitionValidator;
use App\Service\Iam\ShareService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class AgentImportReplaceTest extends TestCase
{
    public function testPublishedCardStaysUntilPublish(): void
    {
        $agent = new Agent(4, 9, 'ping', 'Old name', AgentDefinition::defaults()->toArray());
        $agent->setDescription('Old description');
        $agent->setIcon('star');
        $agent->setStatus(Agent::STATUS_PUBLISHED);
        $agent->setPublishedVersionId(3);

        $prompt = new Prompt();
        $prompt->setPrompt('old instruction');

        $service = $this->service($agent, $prompt);
        $owner = $this->createStub(User::class);
        $owner->method('getId')->willReturn(4);

        $service->replaceImportedDraft(
            $owner,
            'ping',
            'New name',
            AgentDefinition::defaults()->toArray(),
            'new instruction',
            null,
            '',
        );

        self::assertSame('Old name', $agent->getName());
        self::assertSame('Old description', $agent->getDescription());
        self::assertSame('star', $agent->getIcon());
        self::assertSame(3, $agent->getPublishedVersionId());
        self::assertSame('new instruction', $prompt->getPrompt());
    }

    public function testDraftClearsMissingDescriptionAndIcon(): void
    {
        $agent = new Agent(4, 9, 'ping', 'Old name', AgentDefinition::defaults()->toArray());
        $agent->setDescription('Old description');
        $agent->setIcon('star');

        $prompt = new Prompt();
        $prompt->setPrompt('old instruction');

        $service = $this->service($agent, $prompt);
        $owner = $this->createStub(User::class);
        $owner->method('getId')->willReturn(4);

        $service->replaceImportedDraft(
            $owner,
            'ping',
            'New name',
            AgentDefinition::defaults()->toArray(),
            'new instruction',
            null,
            '',
        );

        self::assertSame('New name', $agent->getName());
        self::assertNull($agent->getDescription());
        self::assertSame('', $agent->getIcon());
        self::assertNull($agent->getPublishedVersionId());
    }

    private function service(Agent $agent, Prompt $prompt): AgentService
    {
        $agents = $this->createMock(AgentRepository::class);
        $agents->expects(self::once())->method('findOneBy')->with(['ownerId' => 4, 'slug' => 'ping'])->willReturn($agent);
        $prompts = $this->createMock(PromptRepository::class);
        $prompts->expects(self::once())->method('find')->with(9)->willReturn($prompt);

        return new AgentService(
            $agents,
            $this->createStub(AgentVersionRepository::class),
            $prompts,
            new AgentDefinitionValidator(),
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(AgentAccess::class),
            $this->createStub(ShareService::class),
            $this->createStub(AgentCascadeCleanup::class),
            $this->createStub(UserRepository::class),
            $this->createStub(AgentSerializer::class),
        );
    }
}
