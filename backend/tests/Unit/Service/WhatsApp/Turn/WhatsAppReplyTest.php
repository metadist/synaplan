<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\WhatsApp\Turn;

use App\AI\Exception\ChatFailureClassifier;
use App\DTO\WhatsApp\IncomingMessageDto;
use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\User;
use App\Service\Message\ChatErrorPresenter;
use App\Service\Message\ExternalReplyReferences;
use App\Service\Message\MessageProcessor;
use App\Service\Notification\DiscordNotificationService;
use App\Service\RateLimitService;
use App\Service\Usage\RecordedUsage;
use App\Service\WhatsApp\Turn\InboundWhatsApp;
use App\Service\WhatsApp\Turn\StoredWhatsAppMessage;
use App\Service\WhatsApp\Turn\WhatsAppAnswer;
use App\Service\WhatsApp\Turn\WhatsAppReply;
use App\Service\WhatsApp\Turn\WhatsAppReplyDelivery;
use App\Service\WhatsApp\WhatsAppService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Translation\IdentityTranslator;

final class WhatsAppReplyTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];
    private ?WhatsAppAnswer $delivered = null;
    /** @var list<string> */
    private array $sent = [];

    public function testAFailedRunIsToldToTheSenderAndNothingIsDelivered(): void
    {
        $result = $this->answer(self::turn(), ['success' => false, 'error' => 'Provider down']);

        self::assertFalse($result['success']);
        self::assertSame([$result['error']], $this->sent);
        self::assertNull($this->delivered);
    }

    public function testAnImageWithoutCaptionIsDescribedByTheBoundAssistant(): void
    {
        $this->answer(self::turn('image', ['image' => ['id' => 'media.1']], agentId: 12));
        self::assertSame(['agentId' => 12, 'force_image_description' => true], $this->options);

        $this->answer(self::turn('image', ['image' => ['id' => 'media.1', 'caption' => 'What is this?']]));
        self::assertSame([], $this->options);
    }

    public function testTheStoredAnswerKeepsItsDocTagsAndOnlyTheTextReplyShowsSources(): void
    {
        $this->answer(self::turn(), [
            'success' => true,
            'response' => ['content' => 'See [Doc:setup]', 'metadata' => ['provider' => 'groq']],
            'search_results' => ['query' => 'setup', 'results' => [['title' => 'Setup guide', 'url' => 'https://example.com/setup']]],
        ]);

        self::assertNotNull($this->delivered);
        self::assertSame(['See [Doc:setup]', 'See https://docs.example/setup'], [$this->delivered->text, $this->delivered->channelText]);
        self::assertStringStartsWith('See https://docs.example/setup', $this->delivered->textWithSources);
        self::assertStringContainsString('https://example.com/setup', $this->delivered->textWithSources);
        self::assertSame('groq', $this->delivered->metadata['provider']);
        self::assertInstanceOf(RecordedUsage::class, $this->delivered->metadata['recorded_chat_usage']);
    }

    public function testAnAnswerThatReachesNoPhoneIsReported(): void
    {
        self::assertSame(
            ['success' => true, 'message_id' => 'wamid.in', 'response_sent' => false, 'response_type' => 'none'],
            $this->answer(self::turn(), reachesThePhone: false),
        );
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function answer(InboundWhatsApp $turn, array $result = ['success' => true, 'response' => ['content' => 'Answer']], bool $reachesThePhone = true): array
    {
        $this->delivered = null;
        $processor = $this->createStub(MessageProcessor::class);
        $processor->method('processStream')->willReturnCallback(function (Message $message, callable $stream, ?callable $status, array $options) use ($result): array {
            $this->options = $options;

            return $result;
        });
        $whatsApp = $this->createStub(WhatsAppService::class);
        $whatsApp->method('sendMessage')->willReturnCallback(function (string $to, string $text): array {
            $this->sent[] = $text;

            return ['success' => true];
        });
        $references = $this->createStub(ExternalReplyReferences::class);
        $references->method('resolveStored')->willReturnArgument(0);
        $references->method('resolveDocTags')->willReturnCallback(static fn (string $text): string => str_replace('[Doc:setup]', 'https://docs.example/setup', $text));
        $limits = $this->createStub(RateLimitService::class);
        $limits->method('recordUsage')->willReturn(new RecordedUsage('0.001000', '0.001000', 10, 5, 15));
        $delivery = $this->createStub(WhatsAppReplyDelivery::class);
        $delivery->method('deliver')->willReturnCallback(function (InboundWhatsApp $turn, StoredWhatsAppMessage $stored, WhatsAppAnswer $answer) use ($reachesThePhone): bool {
            $this->delivered = $answer;

            return $reachesThePhone;
        });
        $reply = new WhatsAppReply(
            $whatsApp,
            $processor,
            new ChatErrorPresenter(new IdentityTranslator(), new ChatFailureClassifier()),
            $references,
            $limits,
            $delivery,
            $this->createStub(DiscordNotificationService::class),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
        );
        $message = new Message();
        $message->setText('Hello');

        return $reply->answer($turn, new StoredWhatsAppMessage(new Chat(), $message));
    }

    /**
     * @param array<string, mixed> $content
     */
    private static function turn(string $type = 'text', array $content = ['text' => ['body' => 'Hello']], ?int $agentId = null): InboundWhatsApp
    {
        $dto = IncomingMessageDto::fromPayload(
            ['from' => '491701234567', 'id' => 'wamid.in', 'timestamp' => '1700000000', 'type' => $type] + $content,
            ['metadata' => ['phone_number_id' => '1098765']],
        );

        return new InboundWhatsApp($dto, new User(), false, 7, false, $agentId);
    }
}
