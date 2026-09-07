<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Agent;

use App\Entity\Message;
use App\Entity\MessageMeta;
use App\Entity\User;
use App\Repository\MessageMetaRepository;
use App\Repository\UserRepository;
use App\Service\Agent\AgentConfig;
use App\Service\Agent\AgentPinResolver;
use App\Service\Agent\AgentRuntimeResolver;
use App\Service\Runtime\RuntimeProfile;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AgentPinResolverTest extends TestCase
{
    private AgentConfig&MockObject $agentConfig;
    private AgentRuntimeResolver&MockObject $runtime;
    private UserRepository&MockObject $users;
    private MessageMetaRepository&MockObject $meta;
    private AgentPinResolver $resolver;

    protected function setUp(): void
    {
        $this->agentConfig = $this->createMock(AgentConfig::class);
        $this->runtime = $this->createMock(AgentRuntimeResolver::class);
        $this->users = $this->createMock(UserRepository::class);
        $this->meta = $this->createMock(MessageMetaRepository::class);

        $this->resolver = new AgentPinResolver($this->agentConfig, $this->runtime, $this->users, $this->meta);
    }

    public function testUnpinnedTurnResolvesToNothing(): void
    {
        $this->meta->method('findOneBy')->willReturn(null);
        $this->runtime->expects(self::never())->method('resolve');

        self::assertNull($this->resolver->resolve($this->message(4, 12, 90), []));
    }

    public function testRequestPinWinsAndPassesDraftAndChat(): void
    {
        $user = $this->user(4);
        $this->agentConfig->expects(self::once())->method('isEnabled')->with(4)->willReturn(true);
        $this->users->expects(self::once())->method('find')->with(4)->willReturn($user);
        $this->meta->expects(self::never())->method('findOneBy');

        $profile = $this->profile(7);
        $this->runtime->expects(self::once())->method('resolve')
            ->with(7, $user, true, 90)
            ->willReturn($profile);

        $result = $this->resolver->resolve($this->message(4, 12, 90), ['agentId' => 7, 'agentDraft' => true]);

        self::assertSame($profile, $result);
    }

    public function testMessageMetaPinIsUsedWhenTheRequestCarriesNone(): void
    {
        $meta = new MessageMeta();
        $meta->setMetaKey(AgentPinResolver::META_KEY);
        $meta->setMetaValue('9');
        $this->meta->expects(self::once())->method('findOneBy')
            ->with(['messageId' => 12, 'metaKey' => AgentPinResolver::META_KEY])
            ->willReturn($meta);

        $user = $this->user(4);
        $this->agentConfig->method('isEnabled')->willReturn(true);
        $this->users->method('find')->willReturn($user);
        $this->runtime->expects(self::once())->method('resolve')
            ->with(9, $user, false, null)
            ->willReturn($this->profile(9));

        $result = $this->resolver->resolve($this->message(4, 12, null), ['agentId' => 0]);

        self::assertNotNull($result);
        self::assertSame(9, $result->agentId);
    }

    public function testFeatureFlagOffMeansNoPin(): void
    {
        $this->agentConfig->method('isEnabled')->willReturn(false);
        $this->users->expects(self::never())->method('find');
        $this->runtime->expects(self::never())->method('resolve');

        self::assertNull($this->resolver->resolve($this->message(4, 12, null), ['agentId' => 7]));
    }

    public function testUnknownUserMeansNoPin(): void
    {
        $this->agentConfig->method('isEnabled')->willReturn(true);
        $this->users->method('find')->willReturn(null);
        $this->runtime->expects(self::never())->method('resolve');

        self::assertNull($this->resolver->resolve($this->message(4, 12, null), ['agentId' => 7]));
    }

    private function message(int $userId, ?int $id, ?int $chatId): Message
    {
        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn($id);
        $message->method('getUserId')->willReturn($userId);
        $message->method('getChatId')->willReturn($chatId);

        return $message;
    }

    private function user(int $id): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);

        return $user;
    }

    private function profile(int $agentId): RuntimeProfile
    {
        return new RuntimeProfile(
            promptId: 1,
            promptTopic: 'agent:x',
            systemPrompt: null,
            modelIds: [],
            ragScopes: [],
            toolFlags: [],
            skillAllow: null,
            skillDeny: null,
            parameters: [],
            agentId: $agentId,
        );
    }
}
