<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Email\Turn;

use App\AI\Exception\NoSpeakableTextException;
use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Entity\User;
use App\Service\Email\Turn\EmailReplyAttachments;
use App\Service\Email\Turn\EmailThread;
use App\Service\Email\Turn\EmailVoiceReply;
use App\Service\Email\Turn\InboundEmail;
use App\Service\ModelConfigService;
use App\Service\Notification\DiscordNotificationService;
use App\Service\RateLimitService;
use App\Service\Usage\RecordedUsage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class EmailVoiceReplyTest extends TestCase
{
    /** @var list<mixed> */
    private array $steps = [];

    public function testTheAnswerIsSpokenInTheTurnsLanguageWithTheOwnersSpeechModel(): void
    {
        $path = $this->voice()->attachment($this->email(), $this->message('en'), 'Guten Tag.', ['classification' => ['language' => 'de']]);

        self::assertSame('/uploads/4/voice.mp3', $path);
        self::assertSame([['speak', 'Guten Tag.', 'de', 'mp3', 'piper'], ['usage', 'AUDIOS', 10]], $this->steps);
    }

    public function testWithoutAClassifiedLanguageTheMessagesOwnIsUsed(): void
    {
        $this->voice()->attachment($this->email(), $this->message('fr'), 'Bonjour.', []);

        self::assertSame('fr', $this->steps[0][2]);
    }

    public function testAnAnswerWithNothingToSpeakStaysText(): void
    {
        self::assertNull($this->voice(new NoSpeakableTextException())->attachment($this->email(true), $this->message('en'), "```\ncode\n```", []));
        self::assertNotContains('discord', array_column($this->steps, 0));
    }

    public function testAFailedSynthesisStaysTextAndIsReportedForTheDebugDomain(): void
    {
        self::assertNull($this->voice(new \RuntimeException('Speech is down'))->attachment($this->email(true), $this->message('en'), 'Hello.', []));
        self::assertSame(['discord', 'tts_failed'], $this->steps[0]);
    }

    public function testTheVoiceStaysAttachedWhenItsUsageCannotBeRecorded(): void
    {
        self::assertSame('/uploads/4/voice.mp3', $this->voice(usageFails: true)->attachment($this->email(), $this->message('en'), 'Hello.', []));
    }

    private function voice(?\Exception $speechFails = null, bool $usageFails = false): EmailVoiceReply
    {
        $ai = $this->createStub(AiFacade::class);
        $ai->method('synthesize')->willReturnCallback(function (string $text, string $language, ?int $userId, array $options) use ($speechFails): array {
            if (null !== $speechFails) {
                throw $speechFails;
            }
            $this->steps[] = ['speak', $text, $language, $options['format'], $options['provider']];

            return ['relativePath' => '4/voice.mp3', 'provider' => 'piper', 'model' => 'voice', 'model_id' => 7, 'text_length' => 10];
        });
        $models = $this->createStub(ModelConfigService::class);
        $models->method('getDefaultModel')->willReturn(7);
        $models->method('getProviderForModel')->willReturn('Piper');
        $limits = $this->createStub(RateLimitService::class);
        $limits->method('recordUsage')->willReturnCallback(function (User $user, string $action, array $metadata) use ($usageFails): RecordedUsage {
            if ($usageFails) {
                throw new \RuntimeException('Usage store is down');
            }
            $this->steps[] = ['usage', $action, $metadata['media_usage']['characters']];

            return new RecordedUsage('0', '0', 0, 0, 0);
        });
        $discord = $this->createStub(DiscordNotificationService::class);
        $discord->method('notifyEmailError')->willReturnCallback(function (string $type): void {
            $this->steps[] = ['discord', $type];
        });

        return new EmailVoiceReply($ai, $models, $limits, new EmailReplyAttachments('/uploads', new NullLogger()), $discord, new NullLogger());
    }

    private function message(string $language): Message
    {
        return (new Message())->setLanguage($language);
    }

    private function email(bool $debug = false): InboundEmail
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(4);

        return new InboundEmail($debug ? 'ada'.InboundEmail::DEBUG_DOMAIN : 'ada@example.com', 'smart@synaplan.net', 'Hi', 'Question', new EmailThread(null, null, null), null, 'fp', [], $user, false);
    }
}
