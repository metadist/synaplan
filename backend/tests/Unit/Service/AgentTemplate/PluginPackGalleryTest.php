<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\AgentTemplate;

use App\Bundle\BundleEnvelopeValidator;
use App\Entity\Agent;
use App\Entity\User;
use App\Repository\AgentRepository;
use App\Service\Agent\AgentAccess;
use App\Service\AgentTemplate\Exception\AgentTemplateForbiddenException;
use App\Service\AgentTemplate\Exception\AgentTemplateNotFoundException;
use App\Service\AgentTemplate\PluginPackGallery;
use App\Service\Iam\IamConfig;
use App\Service\Iam\Permission;
use App\Service\Plugin\PluginAgentInstaller;
use App\Service\Plugin\PluginManager;
use App\Service\Plugin\PluginManifest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class PluginPackGalleryTest extends TestCase
{
    public function testPackWithoutTemplateBlockAppearsForAnAdmin(): void
    {
        $gallery = $this->gallery($this->rootWithHello());

        $rows = $gallery->rows($this->user(admin: true));

        self::assertCount(1, $rows);
        self::assertSame('plugin-hello-world-hello-world', $rows[0]['id']);
        self::assertSame('Hello World', $rows[0]['title']);
        self::assertSame('Hello World', $rows[0]['pluginName']);
        self::assertSame('A starter assistant from the Hello World plugin.', $rows[0]['summary']);
        self::assertSame(['Say hello'], $rows[0]['tryIt']);
        self::assertSame('plugin', $rows[0]['category']);
        self::assertSame('', $rows[0]['titleKey']);
        self::assertNull($rows[0]['installedAgentId']);
        self::assertFalse($rows[0]['canEdit']);
        self::assertFalse($rows[0]['sharingOff']);
    }

    public function testNonAdminDoesNotSeeAPackThatIsNotInstalled(): void
    {
        $gallery = $this->gallery($this->rootWithHello());

        self::assertSame([], $gallery->rows($this->user(admin: false)));
    }

    public function testPersonWhoCanUseTheAssistantSeesItWithoutEdit(): void
    {
        $agent = $this->agent(44);
        $agents = $this->createStub(AgentRepository::class);
        $agents->method('findBySource')->willReturn([$agent]);
        $access = $this->createStub(AgentAccess::class);
        $access->method('can')->willReturnCallback(
            static fn (User $user, Agent $agent, Permission $level): bool => Permission::Use === $level,
        );
        $gallery = $this->gallery($this->rootWithHello(), $agents, $access);

        $rows = $gallery->rows($this->user(admin: false, id: 8));

        self::assertCount(1, $rows);
        self::assertSame(44, $rows[0]['installedAgentId']);
        self::assertFalse($rows[0]['canEdit']);
    }

    public function testSharingOffIsReportedOnTheRow(): void
    {
        $iam = $this->createStub(IamConfig::class);
        $iam->method('isSharingEnabled')->willReturn(false);
        $gallery = $this->gallery($this->rootWithHello(), iam: $iam);

        $rows = $gallery->rows($this->user(admin: true));

        self::assertTrue($rows[0]['sharingOff']);
    }

    public function testBrokenPackIsSkipped(): void
    {
        $root = $this->rootWithHello();
        file_put_contents($root.'/hello_world/agents/bad.json', '{');
        $gallery = $this->gallery($root);

        $rows = $gallery->rows($this->user(admin: true));

        self::assertCount(1, $rows);
        self::assertSame('plugin-hello-world-hello-world', $rows[0]['id']);
    }

    public function testIdLongerThanTheRouteIsOmitted(): void
    {
        $root = $this->rootWithHello();
        $long = str_repeat('a', 40);
        $this->writePlugin($root, $long, str_repeat('b', 30), 'Too long');
        $gallery = $this->gallery($root, plugins: $this->plugins([
            $this->manifest('hello_world'),
            $this->manifest($long),
        ]));

        $rows = $gallery->rows($this->user(admin: true));

        self::assertCount(1, $rows);
        self::assertSame('plugin-hello-world-hello-world', $rows[0]['id']);
    }

    public function testAddDelegatesToThePluginInstaller(): void
    {
        $created = $this->agent(44);
        $agents = $this->createStub(AgentRepository::class);
        $agents->method('findBySource')->willReturn([]);
        $agents->method('findByOwnerSlugAndSource')->willReturn($created);
        $installer = $this->createMock(PluginAgentInstaller::class);
        $installer->expects(self::once())->method('enable')->with(self::anything(), 'hello_world')->willReturn(['hello-world']);
        $gallery = $this->gallery($this->rootWithHello(), $agents, installer: $installer);

        $result = $gallery->add($this->user(admin: true), 'plugin-hello-world-hello-world');

        self::assertSame(44, $result->agentId);
        self::assertFalse($result->alreadyInstalled);
        self::assertTrue($result->shared);
        self::assertFalse($result->sharingOff);
        self::assertFalse($result->waitingForReview);
    }

    public function testAddReturnsTheAssistantThePersonCanAlreadyUse(): void
    {
        $agent = $this->agent(44);
        $agents = $this->createStub(AgentRepository::class);
        $agents->method('findBySource')->willReturn([$agent]);
        $access = $this->createStub(AgentAccess::class);
        $access->method('can')->willReturn(true);
        $installer = $this->createMock(PluginAgentInstaller::class);
        $installer->expects(self::never())->method('enable');
        $gallery = $this->gallery($this->rootWithHello(), $agents, $access, $installer);

        $result = $gallery->add($this->user(admin: true), 'plugin-hello-world-hello-world');

        self::assertSame(44, $result->agentId);
        self::assertTrue($result->alreadyInstalled);
    }

    public function testNonAdminCannotAdd(): void
    {
        $installer = $this->createMock(PluginAgentInstaller::class);
        $installer->expects(self::never())->method('enable');
        $gallery = $this->gallery($this->rootWithHello(), installer: $installer);

        $this->expectException(AgentTemplateForbiddenException::class);
        $gallery->add($this->user(admin: false), 'plugin-hello-world-hello-world');
    }

    public function testUnknownPackIsNotFound(): void
    {
        $gallery = $this->gallery($this->rootWithHello());

        $this->expectException(AgentTemplateNotFoundException::class);
        $gallery->add($this->user(admin: true), 'plugin-missing-missing');
    }

    private function gallery(
        string $root,
        ?AgentRepository $agents = null,
        ?AgentAccess $access = null,
        ?PluginAgentInstaller $installer = null,
        ?IamConfig $iam = null,
        ?PluginManager $plugins = null,
    ): PluginPackGallery {
        if (null === $iam) {
            $iam = $this->createStub(IamConfig::class);
            $iam->method('isSharingEnabled')->willReturn(true);
        }

        return new PluginPackGallery(
            $plugins ?? $this->plugins([$this->manifest('hello_world')]),
            $agents ?? $this->createStub(AgentRepository::class),
            $access ?? $this->createStub(AgentAccess::class),
            $installer ?? $this->createStub(PluginAgentInstaller::class),
            $iam,
            new BundleEnvelopeValidator(),
            $this->createStub(LoggerInterface::class),
            $root,
        );
    }

    private function user(bool $admin, int $id = 7): User
    {
        $user = $this->createStub(User::class);
        $user->method('isAdmin')->willReturn($admin);
        $user->method('getId')->willReturn($id);

        return $user;
    }

    private function agent(int $id): Agent
    {
        $agent = new Agent(7, 1, 'hello-world', 'Hello World', ['schema' => 'agent.v1']);
        $agent->setSource(Agent::sourceForPlugin('hello_world'));
        $idProp = new \ReflectionProperty(Agent::class, 'id');
        $idProp->setValue($agent, $id);

        return $agent;
    }

    /**
     * @param list<PluginManifest> $manifests
     */
    private function plugins(array $manifests): PluginManager
    {
        $plugins = $this->createStub(PluginManager::class);
        $plugins->method('listAvailablePlugins')->willReturn($manifests);

        return $plugins;
    }

    private function manifest(string $name): PluginManifest
    {
        return PluginManifest::fromArray([
            'name' => $name,
            'version' => '1.0.0',
            'provides' => ['agents' => ['agents/*.json']],
        ]);
    }

    private function rootWithHello(): string
    {
        $root = sys_get_temp_dir().'/synaplan-plugin-gallery-'.uniqid('', true);
        $this->writePlugin($root, 'hello_world', 'hello-world', 'Hello World');

        return $root;
    }

    private function writePlugin(string $root, string $pluginId, string $key, string $name): void
    {
        $dir = $root.'/'.$pluginId.'/agents';
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/hello.json', json_encode([
            'schema' => 'synaplan-bundle.v1',
            'createdAt' => '2026-09-09T00:00:00Z',
            'sourceInstance' => 'sha256:hello-world-plugin',
            'sourceVersion' => '1.0.0',
            'scope' => 'user',
            'sections' => [[
                'kind' => 'agents',
                'version' => 1,
                'items' => [[
                    'key' => $key,
                    'name' => $name,
                    'description' => 'A starter assistant from the Hello World plugin.',
                    'definition' => [
                        'behaviour' => ['starterPrompts' => ['Say hello']],
                    ],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR));
    }
}
