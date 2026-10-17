<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\WhatsApp\Turn;

use App\DTO\WhatsApp\IncomingMessageDto;
use App\Entity\User;
use App\Service\Agent\AgentConfig;
use App\Service\RateLimitService;
use App\Service\WhatsApp\Turn\InboundWhatsApp;
use App\Service\WhatsApp\Turn\WhatsAppIntake;
use App\Service\WhatsApp\Turn\WhatsAppMessageAnswered;
use App\Service\WhatsApp\WhatsAppAgentBinding;
use App\Service\WhatsApp\WhatsAppService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class WhatsAppIntakeTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $limit = ['allowed' => true];
    /** @var list<string> */
    private array $transport = [];
    private int $limitChecks = 0;

    public function testAnAnonymousSenderRunsUnderTheSharedAccount(): void
    {
        self::assertSame([2, 7], [$this->admit(self::text(), anonymous: true)->effectiveUserId, $this->admit(self::text())->effectiveUserId]);
    }

    public function testAVoiceNoteAndAVideoAreAnsweredBySpeech(): void
    {
        self::assertSame([true, false, true, false], [
            $this->admit(self::message('audio', ['audio' => ['id' => 'media.1']]))->answersBySpeech,
            $this->admit(self::message('audio', ['audio' => ['id' => 'media.1', 'caption' => 'Listen']]))->answersBySpeech,
            $this->admit(self::message('video', ['video' => ['id' => 'media.2']]))->answersBySpeech,
            $this->admit(self::text())->answersBySpeech,
        ]);
    }

    public function testTheAssistantBoundToTheAccountRunsTheTurn(): void
    {
        self::assertSame([12, null], [$this->admit(self::text(), agentEnabled: true)->agentId, $this->admit(self::text())->agentId]);
    }

    public function testASpentLimitIsToldAndEndsTheTurn(): void
    {
        $this->limit = ['allowed' => false, 'limit' => 5, 'used' => 5];

        try {
            $this->admit(self::text());
            self::fail('A spent limit ends the turn.');
        } catch (WhatsAppMessageAnswered $answered) {
            self::assertSame(['success' => false, 'message_id' => 'wamid.in', 'error' => 'Rate limit exceeded'], $answered->answer);
        }
        self::assertSame(['send', 'read'], $this->transport);
    }

    public function testAnUnsupportedTypeIsAcknowledgedBeforeTheLimitIsChecked(): void
    {
        try {
            $this->admit(self::message('reaction', ['reaction' => ['emoji' => '👍']]));
            self::fail('An unsupported type ends the turn.');
        } catch (WhatsAppMessageAnswered $answered) {
            self::assertTrue($answered->answer['skipped']);
        }
        self::assertSame(0, $this->limitChecks);
    }

    private function admit(IncomingMessageDto $dto, bool $anonymous = false, bool $agentEnabled = false): InboundWhatsApp
    {
        $whatsApp = $this->createStub(WhatsAppService::class);
        $whatsApp->method('isAvailable')->willReturn(true);
        $whatsApp->method('sendMessage')->willReturnCallback(function (): array {
            $this->transport[] = 'send';

            return ['success' => true];
        });
        $whatsApp->method('markAsRead')->willReturnCallback(function (): array {
            $this->transport[] = 'read';

            return ['success' => true];
        });
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')->willReturnCallback(fn (string $key, callable $callback): mixed => $callback($this->createStub(ItemInterface::class)));
        $lock = $this->createStub(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(true);
        $locks = $this->createStub(LockFactory::class);
        $locks->method('createLock')->willReturn($lock);
        $limits = $this->createStub(RateLimitService::class);
        $limits->method('checkLimit')->willReturnCallback(function (): array {
            ++$this->limitChecks;

            return $this->limit;
        });
        $agents = $this->createStub(AgentConfig::class);
        $agents->method('isEnabled')->willReturn($agentEnabled);
        $binding = $this->createStub(WhatsAppAgentBinding::class);
        $binding->method('get')->willReturn(12);
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);

        $intake = new WhatsAppIntake($whatsApp, $cache, $locks, $limits, $this->createStub(EntityManagerInterface::class), new NullLogger(), 2, $binding, $agents);

        return $intake->admit($dto, $user, $anonymous);
    }

    private static function text(): IncomingMessageDto
    {
        return self::message('text', ['text' => ['body' => 'Hello there']]);
    }

    /**
     * @param array<string, mixed> $content
     */
    private static function message(string $type, array $content): IncomingMessageDto
    {
        return IncomingMessageDto::fromPayload(
            ['from' => '491701234567', 'id' => 'wamid.in', 'timestamp' => '1700000000', 'type' => $type] + $content,
            ['metadata' => ['phone_number_id' => '1098765', 'display_phone_number' => '+49 30 1234']],
        );
    }
}
