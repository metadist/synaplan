<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Voice;

use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\User;
use App\Entity\VoiceSession;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\Chat\ChatTitleService;
use App\Service\Voice\VoiceLiveArtifact;
use App\Service\Voice\VoiceLiveUsageService;
use App\Service\Voice\VoiceTranscriptWriter;
use App\Service\Voice\VoiceTurnSegmenter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A picture made during a call is stored between the question and the
 * question's transcript, which can arrive later. The question must still be
 * stored once.
 */
final class VoiceTranscriptWriterTest extends TestCase
{
    public function testALateQuestionTranscriptAfterAMadePictureIsNotStoredTwice(): void
    {
        $question = (new Message())->setUserId(4)->setDirection('IN')->setText('Draw a fox in the snow');
        $question->setMeta('voice_session_id', '77');
        $picture = (new Message())->setUserId(4)->setDirection('OUT')->setText('__IMAGE_GENERATED__');
        $picture->setMeta(VoiceLiveArtifact::META_SESSION, '77');

        [$writer, $em] = $this->writer([$picture, $question]);
        $em->expects($this->never())->method('persist');

        $stored = $writer->record($this->session(), [
            ['role' => 'user', 'text' => 'Draw a fox in the snow', 'startMs' => 0, 'endMs' => 0],
        ]);

        self::assertSame($question, $stored);
    }

    public function testTheSpokenAnswerAfterAMadePictureIsItsOwnRow(): void
    {
        $question = (new Message())->setUserId(4)->setDirection('IN')->setText('Draw a fox in the snow');
        $picture = (new Message())->setUserId(4)->setDirection('OUT')->setText('__IMAGE_GENERATED__');
        $picture->setMeta(VoiceLiveArtifact::META_SESSION, '77');

        [$writer, $em] = $this->writer([$picture, $question]);
        $em->expects($this->once())->method('persist')->with($this->isInstanceOf(Message::class));

        $stored = $writer->record($this->session(), [
            ['role' => 'assistant', 'text' => 'The image is ready.', 'startMs' => 0, 'endMs' => 0],
        ]);

        self::assertNotSame($picture, $stored);
        self::assertSame('OUT', $stored->getDirection());
        self::assertSame('The image is ready.', $stored->getText());
    }

    /**
     * @param list<Message> $recent newest first
     *
     * @return array{0: VoiceTranscriptWriter, 1: EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function writer(array $recent): array
    {
        $chat = (new Chat())->setUserId(4);
        $this->setId($chat, 12);
        $user = (new User())->setMail('ada@synaplan.internal');
        $this->setId($user, 4);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($chat);
        $em->method('getConnection')->willReturn($this->createStub(Connection::class));

        $messages = $this->createStub(MessageRepository::class);
        $messages->method('findBy')->willReturn($recent);
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturn($user);
        $usage = $this->createStub(VoiceLiveUsageService::class);
        $usage->method('isIncognito')->willReturn(false);

        $writer = new VoiceTranscriptWriter(
            $em,
            $messages,
            $users,
            $usage,
            new VoiceTurnSegmenter(),
            $this->createStub(ChatTitleService::class),
            new NullLogger(),
        );

        return [$writer, $em];
    }

    private function session(): VoiceSession
    {
        $session = (new VoiceSession())->setUserId(4)->setChatId(12)->setStatus('live')->setModelId(3);
        $this->setId($session, 77);

        return $session;
    }

    private function setId(object $entity, int $id): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }
}
