<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Stream;

use App\Entity\User;
use App\Service\Agent\AgentConfig;
use App\Service\Agent\AgentRuntimeResolver;
use App\Service\Agent\AgentService;
use App\Service\Agent\Exception\AgentArchivedException;
use App\Service\Agent\Exception\AgentNotAccessibleException;
use App\Service\Chat\Stream\StreamAgentPinResolver;
use App\Service\Chat\Stream\StreamRequestRejected;
use App\Service\Runtime\RuntimeProfile;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The assistant a streamed turn is pinned to: only while assistants are on
 * for the person, a draft only for its owner, never an archived assistant.
 */
#[AllowMockObjectsWithoutExpectations]
final class StreamAgentPinResolverTest extends TestCase
{
    private AgentConfig&MockObject $agentConfig;
    private AgentService&MockObject $agentService;
    private AgentRuntimeResolver&MockObject $runtime;
    private User&MockObject $user;

    protected function setUp(): void
    {
        $this->agentConfig = $this->createMock(AgentConfig::class);
        $this->agentService = $this->createMock(AgentService::class);
        $this->runtime = $this->createMock(AgentRuntimeResolver::class);
        $this->user = $this->createMock(User::class);
        $this->user->method('getId')->willReturn(4);
    }

    public function testFlagOffIgnoresAgentId(): void
    {
        $this->agentConfig->method('isEnabled')->willReturn(false);
        $this->runtime->expects(self::never())->method('resolve');

        self::assertNull($this->resolver()->resolve($this->user, 7, false, null));
    }

    public function testFlagOnPinsAgentIdWithItsPublishedVersion(): void
    {
        $this->agentConfig->expects(self::once())->method('isEnabled')->with(4)->willReturn(true);
        $this->runtime->method('resolve')->with(7, $this->user, false, 12)->willReturn($this->profile(versionId: 3));

        $pin = $this->resolver()->resolve($this->user, 7, false, 12);

        self::assertNotNull($pin);
        self::assertSame(7, $pin->agentId);
        self::assertFalse($pin->draft);
        self::assertSame(3, $pin->versionId);
    }

    public function testADraftIsTheOwnersOnly(): void
    {
        $this->agentConfig->method('isEnabled')->willReturn(true);
        $this->agentService->method('requireOwned')->willThrowException(new AgentNotAccessibleException('Assistant 7 is not yours.'));

        $this->expectRejection(403, fn () => $this->resolver()->resolve($this->user, 7, true, null));
    }

    public function testADraftWithoutAPinIsForbidden(): void
    {
        $this->agentConfig->method('isEnabled')->willReturn(false);

        $this->expectRejection(403, fn () => $this->resolver()->resolve($this->user, 7, true, null));
    }

    public function testAnArchivedAssistantIsGone(): void
    {
        $this->agentConfig->method('isEnabled')->willReturn(true);
        $this->runtime->method('resolve')->willThrowException(new AgentArchivedException('Assistant 7 is archived.'));

        $this->expectRejection(410, fn () => $this->resolver()->resolve($this->user, 7, false, null));
    }

    private function resolver(): StreamAgentPinResolver
    {
        return new StreamAgentPinResolver($this->agentConfig, $this->agentService, $this->runtime);
    }

    private function profile(int $versionId): RuntimeProfile
    {
        return new RuntimeProfile(
            promptId: null,
            promptTopic: '',
            systemPrompt: null,
            modelIds: [],
            ragScopes: [],
            toolFlags: [],
            skillAllow: null,
            skillDeny: null,
            parameters: [],
            agentId: 7,
            agentVersionId: $versionId,
        );
    }

    private function expectRejection(int $status, \Closure $resolve): void
    {
        try {
            $resolve();
            self::fail('The pin must be rejected with HTTP '.$status.'.');
        } catch (StreamRequestRejected $rejected) {
            self::assertSame($status, $rejected->httpStatus);
        }
    }
}
