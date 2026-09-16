<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Agent;

use App\Entity\Agent;
use App\Entity\AgentVersion;
use App\Entity\User;
use App\Repository\AgentRepository;
use App\Repository\ModelRepository;
use App\Service\Agent\AgentAccess;
use App\Service\Agent\AgentConfig;
use App\Service\Agent\AgentService;
use App\Service\Agent\AssistantAliasResolver;
use App\Service\Iam\Permission;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AssistantAliasResolverTest extends TestCase
{
    private const PUBLISHED_AT = 1_757_400_000;

    public function testListAliasesOnlyWhenEnabledAndApiEvent(): void
    {
        $user = $this->user(3);
        $agent = $this->agent(11, 3, 'contract-review');
        $published = $this->version(2, ['triggers' => ['events' => [['kind' => 'api', 'enabled' => true]], 'schedules' => []]]);

        $access = $this->createMock(AgentAccess::class);
        $access->expects(self::once())->method('can')->with($user, $agent, Permission::Use)->willReturn(true);

        $service = $this->createMock(AgentService::class);
        $service->method('galleryCards')->willReturn([['id' => 11, 'slug' => 'contract-review']]);
        $service->expects(self::once())->method('publishedVersion')->with($agent)->willReturn($published);

        $resolver = $this->resolver(true, $this->agents([11 => $agent]), $access, $service);

        $aliases = $resolver->listAliases($user);
        self::assertCount(1, $aliases);
        self::assertSame('assistant:contract-review', $aliases[0]['id']);
        self::assertSame('model', $aliases[0]['object']);
        self::assertSame(self::PUBLISHED_AT, $aliases[0]['created']);
        self::assertSame('synaplan', $aliases[0]['owned_by']);
    }

    public function testListAliasesEmptyWhenAgentsOff(): void
    {
        $resolver = $this->resolver(
            false,
            $this->createMock(AgentRepository::class),
            $this->createMock(AgentAccess::class),
            $this->createMock(AgentService::class),
        );

        self::assertSame([], $resolver->listAliases($this->user(3)));
    }

    public function testListAliasesSkipsDraftsAndDisabledEvents(): void
    {
        $user = $this->user(3);
        $draftOnly = $this->agent(11, 3, 'draft-only');
        $apiOff = $this->agent(12, 3, 'api-off');
        $apiOn = $this->agent(13, 3, 'api-on');

        $access = $this->createMock(AgentAccess::class);
        $access->method('can')->willReturn(true);

        $service = $this->createMock(AgentService::class);
        $service->method('galleryCards')->willReturn([['id' => 11], ['id' => 12], ['id' => 13]]);
        $service->method('publishedVersion')->willReturnCallback(fn (Agent $agent): ?AgentVersion => match ($agent->getId()) {
            12 => $this->version(1, ['triggers' => ['events' => [['kind' => 'api', 'enabled' => false]], 'schedules' => []]]),
            13 => $this->version(1, ['triggers' => ['events' => [['kind' => 'api']], 'schedules' => []]]),
            default => null,
        });

        $resolver = $this->resolver(true, $this->agents([11 => $draftOnly, 12 => $apiOff, 13 => $apiOn]), $access, $service);

        self::assertSame(['assistant:api-on'], array_column($resolver->listAliases($user), 'id'));
    }

    public function testListAliasesRequiresUsePermission(): void
    {
        $user = $this->user(3);
        $agent = $this->agent(11, 9, 'shared-read-only');

        $access = $this->createMock(AgentAccess::class);
        $access->method('can')->willReturn(false);

        $service = $this->createMock(AgentService::class);
        $service->method('galleryCards')->willReturn([['id' => 11]]);
        $service->method('publishedVersion')->willReturn(
            $this->version(1, ['triggers' => ['events' => [['kind' => 'api']], 'schedules' => []]]),
        );

        $resolver = $this->resolver(true, $this->agents([11 => $agent]), $access, $service);

        self::assertSame([], $resolver->listAliases($user));
    }

    public function testListAssistantsFiltersByEventKind(): void
    {
        $user = $this->user(3);
        $mcp = $this->agent(11, 3, 'mcp-bot');
        $desktop = $this->agent(12, 3, 'desk-bot');

        $access = $this->createMock(AgentAccess::class);
        $access->method('can')->willReturn(true);

        $service = $this->createMock(AgentService::class);
        $service->method('galleryCards')->willReturn([
            ['id' => 11, 'slug' => 'mcp-bot', 'name' => 'MCP', 'origin' => 'mine'],
            ['id' => 12, 'slug' => 'desk-bot', 'name' => 'Desk', 'origin' => 'shared'],
        ]);
        $service->method('publishedVersion')->willReturnCallback(fn (Agent $agent): ?AgentVersion => match ($agent->getId()) {
            11 => $this->version(3, ['triggers' => ['events' => [['kind' => 'mcp', 'enabled' => true]], 'schedules' => []]]),
            12 => $this->version(1, ['triggers' => ['events' => [['kind' => 'desktop', 'enabled' => true]], 'schedules' => []]]),
            default => null,
        });

        $resolver = $this->resolver(true, $this->agents([11 => $mcp, 12 => $desktop]), $access, $service);

        $mcpOnly = $resolver->listAssistants($user, [AssistantAliasResolver::EVENT_MCP]);
        self::assertSame(['mcp-bot'], array_column($mcpOnly, 'slug'));
        self::assertSame(3, $mcpOnly[0]['version']);
        self::assertSame('MCP', $mcpOnly[0]['name']);
        self::assertSame('mine', $mcpOnly[0]['origin']);

        $both = $resolver->listAssistants($user, [AssistantAliasResolver::EVENT_MCP, AssistantAliasResolver::EVENT_DESKTOP]);
        self::assertSame(['mcp-bot', 'desk-bot'], array_column($both, 'slug'));
        self::assertSame('shared', $both[1]['origin']);
    }

    public function testResolveUsableRejectsDesktopOnlyAgentForPlainMcpSession(): void
    {
        $user = $this->user(3);
        $agent = $this->agent(12, 3, 'desk-bot');

        $access = $this->createMock(AgentAccess::class);
        $access->method('can')->willReturn(true);

        $service = $this->createMock(AgentService::class);
        $service->method('publishedVersion')->willReturn(
            $this->version(1, ['triggers' => ['events' => [['kind' => 'desktop']], 'schedules' => []]]),
        );

        $resolver = $this->resolver(true, $this->agents([12 => $agent]), $access, $service);

        self::assertNull($resolver->resolveUsable($user, 12, [AssistantAliasResolver::EVENT_MCP]));
        self::assertSame($agent, $resolver->resolveUsable($user, '12', [AssistantAliasResolver::EVENT_MCP, AssistantAliasResolver::EVENT_DESKTOP]));
    }

    public function testResolveAliasUnknownIsNull(): void
    {
        $agents = $this->createMock(AgentRepository::class);
        $agents->method('findPublishedBySlug')->willReturn([]);

        $resolver = $this->resolver(
            true,
            $agents,
            $this->createMock(AgentAccess::class),
            $this->createMock(AgentService::class),
        );

        self::assertNull($resolver->resolveAlias($this->user(3), 'assistant:missing'));
        self::assertFalse($resolver->isAlias('gpt-4o'));
        self::assertTrue($resolver->isAlias('assistant:contract-review'));
    }

    public function testResolveAliasPinsPublishedInstruction(): void
    {
        $user = $this->user(3);
        $agent = $this->agent(11, 3, 'contract-review');
        $version = $this->version(1, [
            'triggers' => ['events' => [['kind' => 'api']], 'schedules' => []],
            'models' => ['chat' => null],
        ], 'Review contracts.');

        $agents = $this->createMock(AgentRepository::class);
        $agents->expects(self::once())->method('findPublishedBySlug')->with('contract-review')->willReturn([$agent]);
        $access = $this->createMock(AgentAccess::class);
        $access->method('can')->willReturn(true);
        $service = $this->createMock(AgentService::class);
        $service->method('publishedVersion')->willReturn($version);

        $resolver = $this->resolver(true, $agents, $access, $service);

        $resolved = $resolver->resolveAlias($user, 'assistant:contract-review');
        self::assertNotNull($resolved);
        self::assertSame($agent, $resolved['agent']);
        self::assertSame('Review contracts.', $resolved['instruction']);
        self::assertNull($resolved['chatModel']);
    }

    public function testResolveAliasIsNullWithoutApiEvent(): void
    {
        $user = $this->user(3);
        $agent = $this->agent(11, 3, 'contract-review');

        $agents = $this->createMock(AgentRepository::class);
        $agents->method('findPublishedBySlug')->willReturn([$agent]);
        $access = $this->createMock(AgentAccess::class);
        $access->method('can')->willReturn(true);
        $service = $this->createMock(AgentService::class);
        $service->method('publishedVersion')->willReturn(
            $this->version(1, ['triggers' => ['events' => [['kind' => 'mcp']], 'schedules' => []]]),
        );

        $resolver = $this->resolver(true, $agents, $access, $service);

        self::assertNull($resolver->resolveAlias($user, 'assistant:contract-review'));
    }

    private function resolver(bool $enabled, AgentRepository $agents, AgentAccess $access, AgentService $service): AssistantAliasResolver
    {
        $config = $this->createMock(AgentConfig::class);
        $config->method('isEnabled')->willReturn($enabled);

        return new AssistantAliasResolver($config, $agents, $access, $service, $this->createMock(ModelRepository::class));
    }

    /**
     * @param array<int, Agent> $byId
     */
    private function agents(array $byId): AgentRepository&MockObject
    {
        $agents = $this->createMock(AgentRepository::class);
        $agents->method('find')->willReturnCallback(static fn (mixed $id): ?Agent => $byId[(int) $id] ?? null);

        return $agents;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function version(int $number, array $definition, string $promptText = ''): AgentVersion&MockObject
    {
        $version = $this->createMock(AgentVersion::class);
        $version->method('getVersion')->willReturn($number);
        $version->method('getDefinition')->willReturn($definition);
        $version->method('getPromptText')->willReturn($promptText);
        $version->method('getCreated')->willReturn(self::PUBLISHED_AT);

        return $version;
    }

    private function user(int $id): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);

        return $user;
    }

    private function agent(int $id, int $ownerId, string $slug): Agent
    {
        $agent = new Agent($ownerId, 1, $slug, $slug, []);
        $idProp = new \ReflectionProperty(Agent::class, 'id');
        $idProp->setValue($agent, $id);

        return $agent;
    }
}
