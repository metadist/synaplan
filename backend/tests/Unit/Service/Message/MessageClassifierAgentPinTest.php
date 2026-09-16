<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\AI\ToolCalling\ToolCallingCapability;
use App\Entity\Message;
use App\Repository\ConfigRepository;
use App\Repository\MessageMetaRepository;
use App\Service\Agent\AgentPinResolver;
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
    private AgentPinResolver&MockObject $agentPin;
    private MessageClassifier $classifier;

    protected function setUp(): void
    {
        $this->sorter = $this->createMock(MessageSorter::class);
        $this->agentPin = $this->createMock(AgentPinResolver::class);

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
            $this->agentPin,
        );
    }

    public function testAPinnedTurnSkipsTheSorterAndCarriesOnlyTheProfile(): void
    {
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
        $message = $this->message(4, 'Please review this NDA');
        $this->agentPin->expects(self::once())->method('resolve')
            ->with($message, ['agentId' => 7])
            ->willReturn($profile);
        $this->sorter->expects(self::never())->method('classify');

        $result = $this->classifier->classify($message, [], null, true, ['agentId' => 7]);

        self::assertTrue($result['skip_sorting']);
        self::assertSame('agent', $result['source']);
        self::assertSame('agent:contract-review', $result['topic']);
        self::assertSame(20, $result['prompt_id']);
        self::assertSame(7, $result['agent_id']);
        self::assertNull($result['agent_version_id']);
        self::assertSame(11, $result['model_id']);
        self::assertSame($profile, $result['runtime_profile']);
        self::assertArrayNotHasKey('sorting_usage', $result);

        // The profile is the one seam: RAG scope is never copied into scalars.
        self::assertArrayNotHasKey('rag_group_key', $result);
        self::assertArrayNotHasKey('rag_limit', $result);
        self::assertArrayNotHasKey('rag_min_score', $result);
    }

    public function testSorterIsCalledWhenNothingIsPinned(): void
    {
        $this->agentPin->method('resolve')->willReturn(null);
        $this->sorter->expects(self::once())->method('classify')->willReturn([
            'topic' => 'general',
            'language' => 'en',
            'intent' => 'chat',
            'source' => 'ai_sorting',
        ]);

        $result = $this->classifier->classify($this->message(4, 'hello there'), []);

        self::assertSame('general', $result['topic']);
        self::assertSame('ai_sorting', $result['source']);
        self::assertArrayNotHasKey('runtime_profile', $result);
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
        $message->method('getChatId')->willReturn(null);

        return $message;
    }
}
