<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\AgentTemplate;

use App\Bundle\BundleEnvelopeValidator;
use App\Entity\Agent;
use App\Entity\Prompt;
use App\Entity\Share;
use App\Entity\User;
use App\Repository\AgentRepository;
use App\Repository\ModelRepository;
use App\Repository\PromptRepository;
use App\Service\Agent\AgentPublisher;
use App\Service\Agent\AgentService;
use App\Service\Agent\Definition\AgentDefinition;
use App\Service\Agent\Definition\AgentDefinitionValidator;
use App\Service\AgentTemplate\AgentTemplateCatalog;
use App\Service\AgentTemplate\AgentTemplateInstaller;
use App\Service\AgentTemplate\AgentTemplateInstallRequest;
use App\Service\AgentTemplate\AgentTemplateModelBinder;
use App\Service\Iam\Exception\ShareNotAllowedException;
use App\Service\Iam\IamConfig;
use App\Service\Iam\Permission;
use App\Service\Iam\ResourceKind\AgentKind;
use App\Service\Iam\ShareService;
use App\Service\ModelConfigService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class AgentTemplateInstallerTest extends TestCase
{
    public function testUpdateKeepsLocalizedStarterPromptsAndScheduleToggle(): void
    {
        $backend = dirname(__DIR__, 4);
        $catalog = new AgentTemplateCatalog(
            $backend.'/templates/agents',
            new BundleEnvelopeValidator(),
            new AgentDefinitionValidator(),
        );

        $defaults = $this->createStub(ModelConfigService::class);
        $defaults->method('getDefaultModel')->willReturn(null);
        $binder = new AgentTemplateModelBinder($defaults, $this->createStub(ModelRepository::class));

        $currentDraft = AgentDefinition::defaults()->toArray();
        $currentDraft['behaviour']['starterPrompts'] = ['Was braucht heute eine Entscheidung?'];
        $currentDraft['triggers']['schedules'] = [[
            'id' => 'morning',
            'name' => 'Weekday morning briefing',
            'cron' => '30 7 * * 1-5',
            'tz' => 'Europe/Berlin',
            'instruction' => 'Prepare today\'s briefing from the mailbox.',
            'allowUnattended' => true,
            'enabled' => false,
        ]];
        $currentDraft['knowledge']['folders'] = ['4:prices'];

        $agent = new Agent(4, 15, 'daily-briefing', 'Mein Briefing', $currentDraft);
        $agent->setSource('template:daily-briefing@2026.10.1');
        (new \ReflectionProperty(Agent::class, 'id'))->setValue($agent, 99);

        $agents = $this->createMock(AgentRepository::class);
        $agents->expects(self::once())->method('findInstalledTemplate')->with(4, 'template:daily-briefing@')->willReturn($agent);
        $agents->expects(self::atLeastOnce())->method('save')->with($agent);

        $agentService = $this->createMock(AgentService::class);
        $agentService->expects(self::once())->method('update')->willReturnCallback(
            static function (Agent $target, array $patch): Agent {
                $target->setDraft($patch['draft']);

                return $target;
            }
        );

        $prompt = new Prompt();
        $prompt->setPrompt('Old instruction');
        $prompts = $this->createMock(PromptRepository::class);
        $prompts->expects(self::once())->method('find')->with(15)->willReturn($prompt);

        $installer = new AgentTemplateInstaller(
            $catalog,
            $binder,
            $agents,
            $agentService,
            $this->createStub(AgentPublisher::class),
            $prompts,
            $this->createStub(ShareService::class),
            $this->createStub(IamConfig::class),
            $this->createStub(EntityManagerInterface::class),
        );

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(4);

        $result = $installer->update($user, 'daily-briefing');

        self::assertSame(99, $result->agentId);
        self::assertSame('template:daily-briefing@2026.11.1', $agent->getSource());
        self::assertSame(['Was braucht heute eine Entscheidung?'], $agent->getDraft()['behaviour']['starterPrompts']);
        self::assertFalse($agent->getDraft()['triggers']['schedules'][0]['enabled']);
        self::assertSame(['4:prices'], $agent->getDraft()['knowledge']['folders']);
        self::assertStringContainsString('do not invent messages', $prompt->getPrompt());
    }

    public function testInstallForEveryoneUsesNormalPersonShareGrantAndRespectsPolicy(): void
    {
        $backend = dirname(__DIR__, 4);
        $catalog = new AgentTemplateCatalog(
            $backend.'/templates/agents',
            new BundleEnvelopeValidator(),
            new AgentDefinitionValidator(),
        );
        $defaults = $this->createStub(ModelConfigService::class);
        $defaults->method('getDefaultModel')->willReturn(null);
        $binder = new AgentTemplateModelBinder($defaults, $this->createStub(ModelRepository::class));

        $agent = new Agent(1, 20, 'offer-writer', 'Offer writer', AgentDefinition::defaults()->toArray());
        (new \ReflectionProperty(Agent::class, 'id'))->setValue($agent, 42);

        $agents = $this->createStub(AgentRepository::class);
        $agents->method('findInstalledTemplate')->willReturn($agent);

        $iam = $this->createStub(IamConfig::class);
        $iam->method('isSharingEnabled')->willReturn(true);

        $shares = $this->createMock(ShareService::class);
        $shares->expects(self::once())
            ->method('grant')
            ->with(
                self::isInstanceOf(User::class),
                AgentKind::KEY,
                '42',
                Share::SUBJECT_EVERYONE,
                0,
                Permission::Use->value,
            )
            ->willThrowException(new ShareNotAllowedException('Sharing with everyone is turned off.'));
        $shares->expects(self::never())->method('grantPlatformDistribution');

        $installer = new AgentTemplateInstaller(
            $catalog,
            $binder,
            $agents,
            $this->createStub(AgentService::class),
            $this->createStub(AgentPublisher::class),
            $this->createStub(PromptRepository::class),
            $shares,
            $iam,
            $this->createStub(EntityManagerInterface::class),
        );

        $admin = $this->createStub(User::class);
        $admin->method('getId')->willReturn(1);
        $admin->method('isAdmin')->willReturn(true);

        $result = $installer->install($admin, 'offer-writer', new AgentTemplateInstallRequest('everyone', null, null, []));

        self::assertSame(42, $result->agentId);
        self::assertTrue($result->alreadyInstalled);
        self::assertFalse($result->shared);
        self::assertTrue($result->sharingOff);
    }
}
