<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Plugin;

use App\Bundle\BundleEnvelopeValidator;
use App\Entity\Agent;
use App\Entity\Share;
use App\Entity\User;
use App\Repository\AgentRepository;
use App\Repository\PromptRepository;
use App\Service\Agent\AgentPublisher;
use App\Service\Agent\AgentService;
use App\Service\Agent\Definition\AgentDefinitionValidator;
use App\Service\Iam\IamConfig;
use App\Service\Iam\Permission;
use App\Service\Iam\ResourceKind\AgentKind;
use App\Service\Iam\ShareService;
use App\Service\Plugin\PluginAgentInstaller;
use App\Service\Plugin\PluginManager;
use App\Service\Plugin\PluginManifest;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class PluginAgentInstallerTest extends TestCase
{
    public function testEnableImportsPublishesAndSharesEveryone(): void
    {
        $admin = $this->createMock(User::class);
        $admin->method('isAdmin')->willReturn(true);
        $admin->method('getId')->willReturn(7);

        $created = new Agent(7, 1, 'hello-world', 'Hello World', ['schema' => 'agent.v1']);
        $created->setSource(Agent::sourceForPlugin('hello_world'));
        $idProp = new \ReflectionProperty(Agent::class, 'id');
        $idProp->setAccessible(true);
        $idProp->setValue($created, 44);

        $plugins = $this->createMock(PluginManager::class);
        $plugins->method('listAvailablePlugins')->willReturn([
            PluginManifest::fromArray([
                'name' => 'hello_world',
                'provides' => ['agents' => ['agents/*.json']],
            ]),
        ]);

        $agents = $this->createMock(AgentRepository::class);
        $agents->method('findByOwnerSlugAndSource')->willReturn(null);

        $agentService = $this->createMock(AgentService::class);
        $agentService->expects(self::once())->method('importDraft')->willReturn($created);

        $publisher = $this->createMock(AgentPublisher::class);
        $publisher->expects(self::once())->method('publish')->with($created, $admin, 'Plugin pack');

        $shares = $this->createMock(ShareService::class);
        $shares->expects(self::once())->method('grant')->with(
            $admin,
            AgentKind::KEY,
            '44',
            Share::SUBJECT_EVERYONE,
            0,
            Permission::Use->value,
        );

        $iam = $this->createMock(IamConfig::class);
        $iam->method('isSharingEnabled')->willReturn(true);

        $installer = new PluginAgentInstaller(
            $plugins,
            $agents,
            $agentService,
            $publisher,
            new AgentDefinitionValidator(),
            $this->createMock(PromptRepository::class),
            $shares,
            $iam,
            $this->createMock(EntityManagerInterface::class),
            new BundleEnvelopeValidator(),
            $this->createMock(LoggerInterface::class),
            $this->packDir(),
        );

        $slugs = $installer->enable($admin, 'hello_world');

        self::assertSame(['hello-world'], $slugs);
    }

    public function testDisableRevokesEveryoneShareOnly(): void
    {
        $admin = $this->createMock(User::class);
        $admin->method('isAdmin')->willReturn(true);
        $admin->method('getId')->willReturn(7);

        $agent = new Agent(7, 1, 'hello-world', 'Hello World', ['schema' => 'agent.v1']);
        $idProp = new \ReflectionProperty(Agent::class, 'id');
        $idProp->setAccessible(true);
        $idProp->setValue($agent, 44);

        $agents = $this->createMock(AgentRepository::class);
        $agents->method('findByOwnerAndSource')->willReturn([$agent]);

        $shares = $this->createMock(ShareService::class);
        $shares->expects(self::once())->method('revoke')->with(
            $admin,
            AgentKind::KEY,
            '44',
            Share::SUBJECT_EVERYONE,
            0,
        );

        $iam = $this->createMock(IamConfig::class);
        $iam->method('isSharingEnabled')->willReturn(true);

        $installer = new PluginAgentInstaller(
            $this->createMock(PluginManager::class),
            $agents,
            $this->createMock(AgentService::class),
            $this->createMock(AgentPublisher::class),
            new AgentDefinitionValidator(),
            $this->createMock(PromptRepository::class),
            $shares,
            $iam,
            $this->createMock(EntityManagerInterface::class),
            new BundleEnvelopeValidator(),
            $this->createMock(LoggerInterface::class),
            sys_get_temp_dir(),
        );

        $installer->disable($admin, 'hello_world');
    }

    public function testNonAdminDoesNothing(): void
    {
        $user = $this->createMock(User::class);
        $user->method('isAdmin')->willReturn(false);

        $agentService = $this->createMock(AgentService::class);
        $agentService->expects(self::never())->method('importDraft');

        $installer = new PluginAgentInstaller(
            $this->createMock(PluginManager::class),
            $this->createMock(AgentRepository::class),
            $agentService,
            $this->createMock(AgentPublisher::class),
            new AgentDefinitionValidator(),
            $this->createMock(PromptRepository::class),
            $this->createMock(ShareService::class),
            $this->createMock(IamConfig::class),
            $this->createMock(EntityManagerInterface::class),
            new BundleEnvelopeValidator(),
            $this->createMock(LoggerInterface::class),
            sys_get_temp_dir(),
        );

        self::assertSame([], $installer->enable($user, 'hello_world'));
    }

    private function packDir(): string
    {
        $root = sys_get_temp_dir().'/synaplan-plugin-pack-'.uniqid('', true);
        mkdir($root.'/hello_world/agents', 0777, true);
        file_put_contents($root.'/hello_world/agents/hello.json', json_encode([
            'schema' => 'synaplan-bundle.v1',
            'createdAt' => '2026-09-09T00:00:00Z',
            'sourceInstance' => 'sha256:hello-world-plugin',
            'sourceVersion' => '1.0.0',
            'scope' => 'user',
            'sections' => [
                [
                    'kind' => 'agents',
                    'version' => 1,
                    'items' => [
                        [
                            'key' => 'hello-world',
                            'name' => 'Hello World',
                            'instruction' => 'Greet the person.',
                            'description' => 'Starter assistant',
                            'icon' => '',
                            'definition' => [
                                'schema' => 'agent.v1',
                                'models' => ['chat' => null, 'vision' => null, 'vectorize' => null],
                                'knowledge' => ['ownFolder' => true, 'folders' => [], 'includeUserFiles' => false, 'ragLimit' => 8, 'ragMinScore' => 0.6],
                                'tools' => ['internet' => true, 'files' => true, 'mcpServers' => [], 'allow' => [], 'deny' => []],
                                'skills' => ['allow' => [], 'deny' => []],
                                'parameters' => ['temperature' => 0.7, 'maxTokens' => 4000, 'language' => 'auto', 'responseSchema' => null],
                                'behaviour' => ['greeting' => 'Hello', 'starterPrompts' => [], 'memory' => 'user'],
                                'triggers' => ['events' => [], 'schedules' => []],
                            ],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        return $root;
    }
}
