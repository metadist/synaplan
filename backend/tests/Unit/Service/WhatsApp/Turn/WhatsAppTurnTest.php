<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\WhatsApp\Turn;

use App\DTO\WhatsApp\IncomingMessageDto;
use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\User;
use App\Service\WhatsApp\Turn\InboundWhatsApp;
use App\Service\WhatsApp\Turn\StoredWhatsAppMessage;
use App\Service\WhatsApp\Turn\WhatsAppConversation;
use App\Service\WhatsApp\Turn\WhatsAppIntake;
use App\Service\WhatsApp\Turn\WhatsAppMessageAnswered;
use App\Service\WhatsApp\Turn\WhatsAppReply;
use App\Service\WhatsApp\Turn\WhatsAppTurn;
use PHPUnit\Framework\TestCase;

final class WhatsAppTurnTest extends TestCase
{
    public function testAnEarlyAnswerEndsTheTurnBeforeTheMessageIsStored(): void
    {
        $intake = $this->createStub(WhatsAppIntake::class);
        $intake->method('admit')->willThrowException(WhatsAppMessageAnswered::with(['success' => true, 'duplicate' => true]));
        $conversation = $this->createMock(WhatsAppConversation::class);
        $conversation->expects(self::never())->method('store');
        $reply = $this->createMock(WhatsAppReply::class);
        $reply->expects(self::never())->method('answer');

        $answer = (new WhatsAppTurn($intake, $conversation, $reply))->handle(self::dto(), $this->createStub(User::class), false);

        self::assertSame(['success' => true, 'duplicate' => true], $answer);
    }

    public function testMediaThatCannotBeFetchedEndsTheTurnWithoutAReply(): void
    {
        $inbound = $this->inbound();
        $intake = $this->createStub(WhatsAppIntake::class);
        $intake->method('admit')->willReturn($inbound);
        $conversation = $this->createStub(WhatsAppConversation::class);
        $conversation->method('store')->willThrowException(WhatsAppMessageAnswered::with(['success' => false, 'error' => 'File too large']));
        $reply = $this->createMock(WhatsAppReply::class);
        $reply->expects(self::never())->method('answer');

        $answer = (new WhatsAppTurn($intake, $conversation, $reply))->handle($inbound->dto, $inbound->user, false);

        self::assertSame(['success' => false, 'error' => 'File too large'], $answer);
    }

    public function testTheReplyAnswersTheAdmittedAndStoredMessage(): void
    {
        $inbound = $this->inbound();
        $stored = new StoredWhatsAppMessage(new Chat(), new Message());
        $intake = $this->createMock(WhatsAppIntake::class);
        $intake->expects(self::once())->method('admit')->with(self::identicalTo($inbound->dto), self::identicalTo($inbound->user), true)->willReturn($inbound);
        $conversation = $this->createMock(WhatsAppConversation::class);
        $conversation->expects(self::once())->method('store')->with(self::identicalTo($inbound))->willReturn($stored);
        $reply = $this->createMock(WhatsAppReply::class);
        $reply->expects(self::once())->method('answer')->with(self::identicalTo($inbound), self::identicalTo($stored))->willReturn(['success' => true, 'response_sent' => true]);

        $answer = (new WhatsAppTurn($intake, $conversation, $reply))->handle($inbound->dto, $inbound->user, true);

        self::assertSame(['success' => true, 'response_sent' => true], $answer);
    }

    private function inbound(): InboundWhatsApp
    {
        return new InboundWhatsApp(self::dto(), $this->createStub(User::class), false, 4, false, null);
    }

    private static function dto(): IncomingMessageDto
    {
        return IncomingMessageDto::fromPayload(
            ['from' => '491701234567', 'id' => 'wamid.in', 'timestamp' => '1700000000', 'type' => 'text', 'text' => ['body' => 'Hello']],
            ['metadata' => ['phone_number_id' => '1098765', 'display_phone_number' => '+49 30 1234']],
        );
    }
}
