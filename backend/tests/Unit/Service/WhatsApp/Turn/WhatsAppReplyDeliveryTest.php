<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\WhatsApp\Turn;

use App\AI\Service\AiFacade;
use App\DTO\WhatsApp\IncomingMessageDto;
use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\User;
use App\Service\File\UserUploadPathBuilder;
use App\Service\Notification\DiscordNotificationService;
use App\Service\RateLimitService;
use App\Service\WhatsApp\Turn\InboundWhatsApp;
use App\Service\WhatsApp\Turn\StoredWhatsAppMessage;
use App\Service\WhatsApp\Turn\WhatsAppAnswer;
use App\Service\WhatsApp\Turn\WhatsAppAnswerStore;
use App\Service\WhatsApp\Turn\WhatsAppReplyDelivery;
use App\Service\WhatsApp\WhatsAppService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WhatsAppReplyDeliveryTest extends TestCase
{
    /** @var list<array{string, string}> */
    private array $sent = [];
    /** @var list<array{string, string, array<string, string>|null}> */
    private array $stored = [];

    public function testAVoiceNoteIsAnsweredWithSpeech(): void
    {
        self::assertTrue($this->deliver(answersBySpeech: true));

        self::assertSame([['audio', 'https://app.example.com/api/v1/files/uploads/7/tts/answer.mp3']], $this->sent);
        self::assertSame([['Answer', 'wamid.1', ['path' => '/api/v1/files/uploads/7/tts/answer.mp3', 'type' => 'audio']]], $this->stored);
    }

    public function testSpeechThatCannotBeSynthesizedFallsBackToText(): void
    {
        self::assertTrue($this->deliver(answersBySpeech: true, synthesizes: false));

        self::assertSame([['text', 'Answer']], $this->sent);
        self::assertSame([['Answer', 'wamid.1', null]], $this->stored);
    }

    public function testTextSentBeforeADocumentThatFailsIsStoredOnceAndNotResent(): void
    {
        self::assertTrue($this->deliver(file: ['path' => '/api/v1/files/uploads/7/plan.ics', 'type' => 'document'], mediaFails: true));

        self::assertSame(['text', 'document'], array_column($this->sent, 0));
        self::assertSame([['Answer', 'wamid.1', null]], $this->stored);
    }

    public function testNothingReachesThePhoneWhenEverySendFails(): void
    {
        self::assertFalse($this->deliver(textFails: true));

        self::assertSame([['text', 'Answer']], $this->sent);
        self::assertSame([], $this->stored);
    }

    /**
     * @param array{path: string, type: string}|null $file
     */
    private function deliver(bool $answersBySpeech = false, ?array $file = null, bool $synthesizes = true, bool $mediaFails = false, bool $textFails = false): bool
    {
        $whatsApp = $this->createStub(WhatsAppService::class);
        $whatsApp->method('sendMessage')->willReturnCallback(function (string $to, string $text) use ($textFails): array {
            $this->sent[] = ['text', $text];

            return $textFails ? ['success' => false, 'error' => 'Meta refused'] : ['success' => true, 'message_id' => 'wamid.'.count($this->sent)];
        });
        $whatsApp->method('sendMedia')->willReturnCallback(function (string $to, string $type, string $url) use ($mediaFails): array {
            $this->sent[] = [$type, $url];

            return $mediaFails ? ['success' => false, 'error' => 'Meta refused'] : ['success' => true, 'message_id' => 'wamid.'.count($this->sent)];
        });
        $answers = $this->createStub(WhatsAppAnswerStore::class);
        $answers->method('store')->willReturnCallback(function (User $user, IncomingMessageDto $dto, string $text, string $externalId, ?Chat $chat = null, ?array $searchResults = null, ?array $generatedFile = null): void {
            $this->stored[] = [$text, $externalId, $generatedFile];
        });
        $ai = $this->createStub(AiFacade::class);
        if ($synthesizes) {
            $ai->method('synthesize')->willReturn(['relativePath' => '7/tts/answer.mp3', 'provider' => 'piper']);
        } else {
            $ai->method('synthesize')->willThrowException(new \RuntimeException('No voice for this language.'));
        }
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);
        $dto = IncomingMessageDto::fromPayload(
            ['from' => '491701234567', 'id' => 'wamid.in', 'timestamp' => '1700000000', 'type' => $answersBySpeech ? 'audio' : 'text'],
            ['metadata' => ['phone_number_id' => '1098765']],
        );
        $delivery = new WhatsAppReplyDelivery(
            $whatsApp,
            $answers,
            $this->createStub(RateLimitService::class),
            $this->createStub(DiscordNotificationService::class),
            $ai,
            new UserUploadPathBuilder(),
            new NullLogger(),
            'https://app.example.com',
            sys_get_temp_dir().'/whatsapp-delivery-test',
        );

        return $delivery->deliver(
            new InboundWhatsApp($dto, $user, false, 7, $answersBySpeech, null),
            new StoredWhatsAppMessage(new Chat(), new Message()),
            new WhatsAppAnswer('Answer', 'Answer', 'Answer', null !== $file ? ['file' => $file] : [], null, null),
        );
    }
}
