<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\AI\ToolCalling\ToolCallingCapability;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\MessageMetaRepository;
use App\Repository\UserRepository;
use App\Service\Agent\AgentConfig;
use App\Service\Agent\AgentRuntimeResolver;
use App\Service\Message\Capability\SystemCapabilityRegistry;
use App\Service\Message\MessageClassifier;
use App\Service\Message\MessageSorter;
use App\Service\Message\Routing\EmbeddingRouterConfig;
use App\Service\Message\Routing\EmbeddingRouterService;
use App\Service\Message\Routing\NativeToolRoutingConfig;
use App\Service\ModelConfigService;
use App\Service\Runtime\RuntimeProfile;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class MessageClassifierAgentPinTest extends TestCase
{
    private MessageSorter&MockObject $sorter;
    private AgentRuntimeResolver&MockObject $resolver;
    private AgentConfig&MockObject $agentConfig;
    private UserRepository&MockObject $users;
    private MessageClassifier $classifier;

    protected function setUp(): void
    {
        $this->sorter = $this->createMock(MessageSorter::class);
        $this->resolver = $this->createMock(AgentRuntimeResolver::class);
        $this->agentConfig = $this->createMock(AgentConfig::class);
        $this->users = $this->createMock(UserRepository::class);

        $configRepo = $this->createMock(ConfigRepository::class);
        $configRepo->method('getValue')->willReturn('0');

        $this->classifier = new MessageClassifier(
            $this->sorter,
            $this->createMock(MessageMetaRepository::class),
            $this->createMock(ModelConfigService::class),
            $configRepo,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(LoggerInterface::class),
            new SystemCapabilityRegistry(),
            $this->createMock(EmbeddingRouterService::class),
            new EmbeddingRouterConfig($configRepo),
            new NativeToolRoutingConfig($configRepo),
            new ToolCallingCapability(),
            agentConfig: $this->agentConfig,
            agentRuntimeResolver: $this->resolver,
            userRepository: $this->users,
        );
    }

    public function testAgentIdSkipsTheSorter(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(4);
        $this->users->expects(self::once())->method('find')->with(4)->willReturn($user);
        $this->agentConfig->expects(self::once())->method('isEnabled')->with(4)->willReturn(true);

        $profile = new RuntimeProfile(
            promptId: 20,
            promptTopic: 'agent:contract-review',
            systemPrompt: 'Review contracts.',
            modelIds: ['chat' => 11],
            ragScopes: [['ownerId' => 4, 'groupKey' => 'TASKPROMPT:agent:contract-review']],
            toolFlags: [],
            skillAllow: null,
            skillDeny: null,
            parameters: [],
            agentId: 7,
            agentVersionId: null,
            notes: [],
            ragLimit: 8,
            ragMinScore: 0.6,
        );
        $this->resolver->expects(self::once())->method('resolve')->with(7, $user, true)->willReturn($profile);
        $this->sorter->expects(self::never())->method('classify');

        $message = $this->message(4, 'Please review this NDA');
        $result = $this->classifier->classify($message, [], null, true, ['agentId' => 7]);

        self::assertTrue($result['skip_sorting']);
        self::assertSame('agent', $result['source']);
        self::assertSame('agent:contract-review', $result['topic']);
        self::assertSame(20, $result['prompt_id']);
        self::assertSame(7, $result['agent_id']);
        self::assertNull($result['agent_version_id']);
        self::assertSame('TASKPROMPT:agent:contract-review', $result['rag_group_key']);
        self::assertArrayNotHasKey('sorting_usage', $result);
        self::assertSame($profile, $result['runtime_profile']);
    }

    public function testSorterIsCalledWithoutAgentId(): void
    {
        $this->sorter->expects(self::once())->method('classify')->willReturn([
            'topic' => 'general',
            'language' => 'en',
            'intent' => 'chat',
            'source' => 'ai_sorting',
        ]);
        $this->resolver->expects(self::never())->method('resolve');

        $result = $this->classifier->classify($this->message(4, 'hello there'), []);

        self::assertSame('general', $result['topic']);
        self::assertSame('ai_sorting', $result['source']);
    }

    private function message(int $userId, string $text): Message
    {
        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(1);
        $message->method('getUserId')->willReturn($userId);
        $message->method('getText')->willReturn($text);
        $message->method('getLanguage')->willReturn('en');
        $message->method('getFile')->willReturn(0);
        $message->method('getFiles')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());
        $message->method('getTopic')->willReturn('CHAT');

        return $message;
    }
}
