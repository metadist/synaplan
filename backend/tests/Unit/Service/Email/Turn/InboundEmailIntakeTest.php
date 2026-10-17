<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Email\Turn;

use App\Entity\Message;
use App\Entity\User;
use App\Service\Email\EmailChatService;
use App\Service\Email\EmailWebhookIdempotencyService;
use App\Service\Email\InternalEmailService;
use App\Service\Email\RawMimeEmailParser;
use App\Service\Email\Turn\InboundEmail;
use App\Service\Email\Turn\InboundEmailAnswered;
use App\Service\Email\Turn\InboundEmailIntake;
use App\Service\Notification\DiscordNotificationService;
use App\Service\RateLimitService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

final class InboundEmailIntakeTest extends TestCase
{
    /** @var list<mixed> */
    private array $steps = [];

    public function testAPayloadThatIsNotJsonIsAnswered(): void
    {
        self::assertSame([400, ['success' => false, 'error' => 'Invalid JSON payload']], $this->answered($this->intake(), 'not json'));
        self::assertSame([400, ['success' => false, 'error' => 'Invalid JSON payload']], $this->answered($this->intake(), '{}'));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function incompleteMails(): iterable
    {
        yield 'no sender' => [['to' => 'smart@synaplan.net', 'body' => 'Hi']];
        yield 'no recipient' => [['from' => 'ada@example.com', 'body' => 'Hi']];
        yield 'no body' => [['from' => 'ada@example.com', 'to' => 'smart@synaplan.net']];
        yield 'body as a list' => [['from' => 'ada@example.com', 'to' => 'smart@synaplan.net', 'body' => ['Hi']]];
    }

    /**
     * @param array<string, mixed> $mail
     */
    #[DataProvider('incompleteMails')]
    public function testAMailWithoutSenderRecipientOrBodyIsAnswered(array $mail): void
    {
        self::assertSame([400, ['success' => false, 'error' => 'Missing required fields: from, to, body']], $this->answered($this->intake(), json_encode($mail, JSON_THROW_ON_ERROR)));
    }

    public function testAnAcceptedMailCarriesTheNormalizedSenderAndItsThread(): void
    {
        $email = $this->intake(account: ['user' => $this->user(), 'is_anonymous' => true])->accept($this->request([
            'from' => '  Ada@Example.COM ',
            'to' => 'Smart+Support@Synaplan.net',
            'body' => 'Hi',
            'message_id' => '<m1@example.com>',
            'in_reply_to' => '<m0@example.com>',
            'attachments' => [['filename' => 'a.pdf']],
        ]));

        self::assertSame(
            ['ada@example.com', 'smart+support@synaplan.net', '(no subject)', 'Hi', '<m1@example.com>', '<m0@example.com>', 'support', 'fp', 'm1', [['filename' => 'a.pdf']], true, false],
            [$email->fromEmail, $email->toEmail, $email->subject, $email->body, $email->thread->messageId, $email->thread->inReplyTo, $email->keyword, $email->fingerprint, $email->thread->externalId, $email->attachments, $email->isAnonymous, $email->reportsToDiscord()],
        );
    }

    public function testARawMimeBodyIsReadAsItsText(): void
    {
        self::assertSame('Plain question', $this->accept($this->intake(mimeText: 'Plain question'))->body);
        self::assertSame('MIME-Version: 1.0', $this->accept($this->intake(mimeText: '  '), body: 'MIME-Version: 1.0')->body);
    }

    public function testARedeliveryIsAnsweredAsTheDuplicateItIs(): void
    {
        $existing = (new Message())->setUserId(4);
        (new \ReflectionProperty(Message::class, 'id'))->setValue($existing, 70);

        self::assertSame(
            [200, ['success' => true, 'duplicate' => true, 'message_id' => 70, 'chat_id' => null]],
            $this->answered($this->intake(duplicate: $existing, externalId: 'm1'), $this->mail()),
        );
        $this->answered($this->intake(duplicate: $existing, externalId: null), $this->mail());

        self::assertSame([['duplicate', 'external_id', 'm1'], ['duplicate', 'fingerprint', null]], $this->steps);
    }

    public function testARefusedSenderIsToldByMail(): void
    {
        self::assertSame(
            [429, ['success' => false, 'error' => 'Too many requests.']],
            $this->answered($this->intake(account: ['user' => null, 'error' => 'Too many requests.']), $this->mail('ada@metadist.onmicrosoft.com')),
        );
        self::assertSame([['discord', 'user_creation'], ['mail', 'ada@metadist.onmicrosoft.com', 'Too many requests.']], $this->steps);
    }

    public function testASpentLimitIsToldByMailWithItsReset(): void
    {
        $limit = ['allowed' => false, 'limit' => 10, 'used' => 10, 'reset_at' => 'tomorrow'];

        self::assertSame(
            [429, ['success' => false, 'error' => 'Rate limit exceeded', 'limit' => 10, 'used' => 10, 'reset_at' => 'tomorrow']],
            $this->answered($this->intake(limit: $limit), $this->mail()),
        );
        self::assertSame([['mail', 'ada@example.com', "Rate limit exceeded. Please try again later.\n\nYour limit will reset at: tomorrow"]], $this->steps);
    }

    /**
     * @param array<string, mixed>|null $account
     * @param array<string, mixed>      $limit
     */
    private function intake(?array $account = null, array $limit = ['allowed' => true], ?Message $duplicate = null, ?string $externalId = 'm1', ?string $mimeText = null): InboundEmailIntake
    {
        $mime = $this->createStub(RawMimeEmailParser::class);
        $mime->method('looksLikeRawMime')->willReturn(null !== $mimeText);
        $mime->method('extractText')->willReturn((string) $mimeText);

        $idempotency = $this->createStub(EmailWebhookIdempotencyService::class);
        $idempotency->method('findDuplicate')->willReturn(['existing' => $duplicate, 'fingerprint' => 'fp', 'normalized_message_id' => $externalId]);

        $chats = $this->createStub(EmailChatService::class);
        $chats->method('parseEmailKeyword')->willReturnCallback(static fn (string $to): ?string => str_contains($to, '+') ? 'support' : null);
        $chats->method('findOrCreateUserFromEmail')->willReturn($account ?? ['user' => $this->user(), 'is_anonymous' => false]);

        $limits = $this->createStub(RateLimitService::class);
        $limits->method('checkLimit')->willReturn($limit);

        $mailer = $this->createStub(InternalEmailService::class);
        $mailer->method('sendAiResponseEmail')->willReturnCallback(function (string $to, string $subject, string $text): void {
            $this->steps[] = ['mail', $to, $text];
        });
        $discord = $this->createStub(DiscordNotificationService::class);
        $discord->method('notifyDuplicateEmailWebhook')->willReturnCallback(function (string $fromEmail, string $toEmail, string $subject, int $existingMessageId, ?int $chatId, ?string $externalMessageId, string $detectionMethod): void {
            $this->steps[] = ['duplicate', $detectionMethod, $externalMessageId];
        });
        $discord->method('notifyEmailError')->willReturnCallback(function (string $type): void {
            $this->steps[] = ['discord', $type];
        });

        return new InboundEmailIntake($mime, $idempotency, $chats, $limits, $mailer, $discord, new NullLogger());
    }

    private function accept(InboundEmailIntake $intake, string $body = 'MIME-Version: 1.0'): InboundEmail
    {
        return $intake->accept($this->request(['from' => 'ada@example.com', 'to' => 'smart@synaplan.net', 'body' => $body]));
    }

    private function user(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(4);

        return $user;
    }

    private function mail(string $from = 'ada@example.com'): string
    {
        return json_encode(['from' => $from, 'to' => 'smart@synaplan.net', 'subject' => 'Hi', 'body' => 'Question'], JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $mail
     */
    private function request(array $mail): Request
    {
        return Request::create('/api/v1/webhooks/email', 'POST', content: json_encode($mail, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function answered(InboundEmailIntake $intake, string $content): array
    {
        try {
            $intake->accept(Request::create('/api/v1/webhooks/email', 'POST', content: $content));
        } catch (InboundEmailAnswered $answered) {
            return [$answered->httpStatus, $answered->payload];
        }

        self::fail('The mail was accepted.');
    }
}
