<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Voice;

use App\AI\Credential\ProviderKeyStore;
use App\Entity\User;
use App\Entity\VoiceSession;
use App\Exception\VoiceLiveRejectedException;
use App\Module\Sidecar\LiveVoiceModule;
use App\Repository\ConfigRepository;
use App\Repository\MessageRepository;
use App\Repository\ModelRepository;
use App\Repository\UserRepository;
use App\Repository\VoiceSessionRepository;
use App\Service\Infrastructure\RedisService;
use App\Service\Message\MessageApiFormatter;
use App\Service\PremiumFeatureGate;
use App\Service\RateLimitService;
use App\Service\Voice\VoiceLiveCompatibleEndpoint;
use App\Service\Voice\VoiceLiveConcurrency;
use App\Service\Voice\VoiceLiveDelegationService;
use App\Service\Voice\VoiceLiveInstructions;
use App\Service\Voice\VoiceLiveSessionService;
use App\Service\Voice\VoiceLiveTicketService;
use App\Service\Voice\VoiceLiveUsageService;
use App\Service\Voice\VoiceTranscriptWriter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

final class VoiceLiveSessionServiceTest extends TestCase
{
    public function testAUsageTickOnAnEndedSessionTellsTheGatewayToStop(): void
    {
        $session = $this->session(client: 'web')->setStatus('ended')->setEndReason('admin_disabled');
        $usage = $this->createMock(VoiceLiveUsageService::class);
        $usage->expects(self::never())->method('tick');

        $result = $this->service($session, usage: $usage)->applyUsage(5, ['meter' => ['seconds' => 30]]);

        self::assertSame('stop', $result['action']);
        self::assertSame('admin_disabled', $result['reason']);
        self::assertSame(0, $result['allowedSeconds']);
    }

    public function testAUsageTickOnAnUnknownSessionIsNotFound(): void
    {
        $this->expectException(VoiceLiveRejectedException::class);

        $this->service(null)->applyUsage(5, []);
    }

    public function testOnlyACarPlaySessionCanResumeWithAFreshTicket(): void
    {
        $tickets = $this->createMock(VoiceLiveTicketService::class);
        $tickets->expects(self::never())->method('mint');

        try {
            $this->service($this->session(client: 'web'), tickets: $tickets)
                ->resumeTicket($this->user(), 5, Request::create('/'));
            self::fail('A web session must not get a resume ticket.');
        } catch (VoiceLiveRejectedException $e) {
            self::assertSame(404, $e->statusCode());
        }
    }

    public function testACarPlaySessionGetsAFreshTicketForTheSameSession(): void
    {
        $tickets = $this->createMock(VoiceLiveTicketService::class);
        $tickets->expects(self::once())->method('mint')->with(3, 5)->willReturn('a.b.c');
        $redis = $this->createStub(RedisService::class);
        $redis->method('increment')->willReturn(1);

        $result = $this->service($this->session(client: 'carplay'), tickets: $tickets, redis: $redis)
            ->resumeTicket($this->user(), 5, Request::create('https://synaplan.test/'));

        self::assertSame(5, $result['sessionId']);
        self::assertStringEndsWith('?ticket=a.b.c', $result['wsUrl']);
    }

    private function session(string $client): VoiceSession
    {
        $session = (new VoiceSession())
            ->setUserId(3)
            ->setModelId(9)
            ->setProvider('openai')
            ->setStatus('live')
            ->setClient($client);
        (new \ReflectionProperty(VoiceSession::class, 'id'))->setValue($session, 5);

        return $session;
    }

    private function user(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(3);

        return $user;
    }

    private function service(
        ?VoiceSession $session,
        ?VoiceLiveUsageService $usage = null,
        ?VoiceLiveTicketService $tickets = null,
        ?RedisService $redis = null,
    ): VoiceLiveSessionService {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($session);

        return new VoiceLiveSessionService(
            $this->createStub(LiveVoiceModule::class),
            $usage ?? $this->createStub(VoiceLiveUsageService::class),
            $tickets ?? $this->createStub(VoiceLiveTicketService::class),
            $this->createStub(VoiceLiveConcurrency::class),
            $this->createStub(VoiceLiveDelegationService::class),
            $this->createStub(VoiceTranscriptWriter::class),
            new VoiceLiveInstructions(),
            $this->createStub(ModelRepository::class),
            $this->createStub(ProviderKeyStore::class),
            $this->createStub(ConfigRepository::class),
            $this->createStub(MessageRepository::class),
            $this->createStub(VoiceSessionRepository::class),
            $this->createStub(UserRepository::class),
            $this->createStub(RateLimitService::class),
            $this->createStub(PremiumFeatureGate::class),
            $em,
            $redis ?? $this->createStub(RedisService::class),
            new NullLogger(),
            '/voice/ws',
            $this->createStub(VoiceLiveCompatibleEndpoint::class),
            $this->createStub(MessageApiFormatter::class),
        );
    }
}
