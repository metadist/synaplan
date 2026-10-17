<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Email\Turn;

use App\AI\Exception\ChatFailureReason;
use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\User;
use App\Service\Email\InternalEmailService;
use App\Service\Email\Turn\EmailAnswerStore;
use App\Service\Email\Turn\EmailReply;
use App\Service\Email\Turn\EmailReplyAttachments;
use App\Service\Email\Turn\EmailThread;
use App\Service\Email\Turn\EmailVoiceReply;
use App\Service\Email\Turn\InboundEmail;
use App\Service\Email\Turn\StoredInboundEmail;
use App\Service\Message\ChatErrorPresenter;
use App\Service\Message\ChatErrorView;
use App\Service\Message\ExternalReplyReferences;
use App\Service\Message\MessageProcessor;
use App\Service\Notification\DiscordNotificationService;
use App\Service\Usage\RecordedUsage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class EmailReplyTest extends TestCase
{
    /** @var list<mixed> */
    private array $steps = [];

    public function testAnAnswerIsRecordedStoredAndMailedInItsThread(): void
    {
        $payload = $this->reply(['success' => true, 'response' => ['content' => 'See [Doc:x].', 'metadata' => ['provider' => 'test', 'model' => 'm']]])
            ->answer($this->email(), $this->stored());

        self::assertSame([['usage'], ['store', 'See [Doc:x].'], ['mail', 'linked: See [Doc:x].', '<m1@example.com>', 'test', 'm', null]], $this->steps);
        self::assertSame(
            ['success' => true, 'message_id' => null, 'chat_id' => null, 'response' => ['text' => 'See [Doc:x].', 'metadata' => ['provider' => 'test', 'model' => 'm']], 'user_info' => ['is_anonymous' => true, 'rate_limit_level' => 'ANONYMOUS']],
            $payload,
        );
    }

    public function testASpokenReplyIsAddedOnlyWhenAskedForAndNoFileCameWithTheAnswer(): void
    {
        $answer = ['success' => true, 'response' => ['content' => 'Hello.', 'metadata' => []]];

        $this->reply($answer)->answer($this->email(), $this->stored(voiceReply: true));
        $this->reply($answer)->answer($this->email(), $this->stored());

        self::assertSame([['usage'], ['voice'], ['store', 'Hello.'], ['mail', 'linked: Hello.', '<m1@example.com>', null, null, '/uploads/voice.mp3'], ['usage'], ['store', 'Hello.'], ['mail', 'linked: Hello.', '<m1@example.com>', null, null, null]], $this->steps);
    }

    public function testAFailedAnswerStillTellsTheSenderAndIsNotRetried(): void
    {
        $payload = $this->reply(['success' => false, 'provider' => 'test'])->answer($this->email(debug: true), $this->stored());

        self::assertSame(['success' => false, 'error' => 'Message processing failed'], $payload);
        self::assertSame([['discord', 'processing', 'raw failure'], ['mail', 'Please try again.', '<m1@example.com>', 'test', null, null]], $this->steps);
    }

    public function testATurnThatBreaksIsReportedWithoutItsDetails(): void
    {
        $payload = $this->reply([])->failure($this->email(debug: true), new \RuntimeException('Database is down'));

        self::assertSame(['success' => false, 'error' => 'Internal error processing email', 'details' => null], $payload);
        self::assertSame('processing', $this->steps[0][1]);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function reply(array $result): EmailReply
    {
        $processor = $this->createStub(MessageProcessor::class);
        $processor->method('process')->willReturn($result);

        $voice = $this->createStub(EmailVoiceReply::class);
        $voice->method('attachment')->willReturnCallback(function (): string {
            $this->steps[] = ['voice'];

            return '/uploads/voice.mp3';
        });
        $answers = $this->createStub(EmailAnswerStore::class);
        $answers->method('recordUsage')->willReturnCallback(function (): RecordedUsage {
            $this->steps[] = ['usage'];

            return new RecordedUsage('0', '0', 0, 0, 0);
        });
        $answers->method('store')->willReturnCallback(function (InboundEmail $email, StoredInboundEmail $stored, string $answer): void {
            $this->steps[] = ['store', $answer];
        });

        $mailer = $this->createStub(InternalEmailService::class);
        $mailer->method('sendAiResponseEmail')->willReturnCallback(function (string $to, string $subject, string $text, ?string $inReplyTo, ?string $provider, ?string $model, ?float $time, ?string $attachment): void {
            $this->steps[] = ['mail', $text, $inReplyTo, $provider, $model, $attachment];
        });
        $references = $this->createStub(ExternalReplyReferences::class);
        $references->method('resolve')->willReturnCallback(static fn (string $text): string => 'linked: '.$text);
        $errors = $this->createStub(ChatErrorPresenter::class);
        $errors->method('presentFromResult')->willReturn(new ChatErrorView(ChatFailureReason::SchemaMismatch, 'Please try again.', null, true, 'raw failure'));
        $discord = $this->createStub(DiscordNotificationService::class);
        $discord->method('notifyEmailError')->willReturnCallback(function (string $type, string $from, string $to, string $subject, string $error): void {
            $this->steps[] = ['discord', $type, $error];
        });

        return new EmailReply($processor, $voice, $answers, new EmailReplyAttachments('/uploads', new NullLogger()), $mailer, $references, $errors, $discord, new NullLogger());
    }

    private function stored(bool $voiceReply = false): StoredInboundEmail
    {
        $message = (new Message())->setLanguage('en');
        if ($voiceReply) {
            $message->setMeta('voice_reply', '1');
        }

        return new StoredInboundEmail(new Chat(), $message);
    }

    private function email(bool $debug = false): InboundEmail
    {
        $user = $this->createStub(User::class);
        $user->method('getRateLimitLevel')->willReturn('ANONYMOUS');
        $user->method('getLocale')->willReturn('en');

        return new InboundEmail($debug ? 'ada'.InboundEmail::DEBUG_DOMAIN : 'ada@example.com', 'smart@synaplan.net', 'Hi', 'Question', new EmailThread('<m1@example.com>', null, null), null, 'fp', [], $user, true);
    }
}
