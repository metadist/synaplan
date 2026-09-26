<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Desktop;

use App\Entity\Chat;
use App\Entity\DesktopJob;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ChatRepository;
use App\Repository\UserRepository;
use App\Service\Desktop\DesktopJobResultNotifier;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DesktopJobResultNotifierTest extends TestCase
{
    private ChatRepository&MockObject $chats;
    private UserRepository&MockObject $users;
    private EntityManagerInterface&MockObject $em;
    private DesktopJobResultNotifier $notifier;

    protected function setUp(): void
    {
        $this->chats = $this->createMock(ChatRepository::class);
        $this->users = $this->createMock(UserRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static function (string $id, array $parameters = []): string {
                $skill = $parameters['%skill%'] ?? '';
                $reason = $parameters['%reason%'] ?? '';

                return match ($id) {
                    'desktop.job.failed' => sprintf('The "%s" task on your computer did not finish. %s', $skill, $reason),
                    'desktop.job.finished' => sprintf('The "%s" task finished on your computer.', $skill),
                    'desktop.job.files' => 'Files: '.($parameters['%ids%'] ?? ''),
                    'desktop.job.unnamed' => 'task',
                    'desktop.job.reason.skill_disabled' => 'This computer refused to run the skill.',
                    'desktop.job.reason.local_error' => 'This computer could not finish the task.',
                    default => $id,
                };
            }
        );

        $this->notifier = new DesktopJobResultNotifier(
            $this->chats,
            $this->users,
            $this->em,
            $translator,
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testFailureNoteUsesTheDeviceReasonInTheOwnersLanguage(): void
    {
        $this->ownerSpeaks('de');
        $message = $this->capture($this->job(
            DesktopJob::STATUS_FAILED,
            'skill_disabled',
            ['message' => "The skill 'hello-files' is not allowed to run when nobody is at the keyboard."],
        ));

        self::assertSame('de', $message->getLanguage());
        self::assertSame(
            'The "hello-files" task on your computer did not finish. The skill \'hello-files\' is not allowed to run when nobody is at the keyboard.',
            $message->getText(),
        );
        self::assertStringNotContainsString('skill_disabled', $message->getText());
    }

    public function testFailureWithoutAReasonUsesAPlainSentence(): void
    {
        $this->ownerSpeaks('en');
        $message = $this->capture($this->job(DesktopJob::STATUS_FAILED, 'local_error', null));

        self::assertSame(
            'The "hello-files" task on your computer did not finish. This computer could not finish the task.',
            $message->getText(),
        );
        self::assertStringNotContainsString('(local_error)', $message->getText());
    }

    public function testSuccessNoteIncludesTheSummary(): void
    {
        $this->ownerSpeaks('en');
        $message = $this->capture($this->job(DesktopJob::STATUS_SUCCEEDED, null, [
            'summary' => 'Listed 12 files.',
            'fileIds' => [42],
        ]));

        self::assertSame(
            "The \"hello-files\" task finished on your computer.\nListed 12 files.\nFiles: #42",
            $message->getText(),
        );
    }

    private function ownerSpeaks(string $locale): void
    {
        $user = (new User())->setUserDetails(['language' => $locale]);
        $this->users->method('find')->willReturn($user);
    }

    private function capture(DesktopJob $job): Message
    {
        $captured = null;
        $this->em->expects(self::once())->method('persist')->willReturnCallback(
            static function (object $entity) use (&$captured): void {
                $captured = $entity;
            }
        );
        $this->em->method('flush');

        $this->notifier->notify($job);

        self::assertInstanceOf(Message::class, $captured);

        return $captured;
    }

    /**
     * @param array<string, mixed>|null $result
     */
    private function job(string $status, ?string $errorCode, ?array $result): DesktopJob
    {
        $chat = (new Chat())->setUserId(5)->setTitle('New Chat');
        $this->chats->expects(self::once())->method('find')->with(9)->willReturn($chat);

        return (new DesktopJob())
            ->setOwnerId(5)
            ->setChatId(9)
            ->setStatus($status)
            ->setErrorCode($errorCode)
            ->setResult($result)
            ->setInput(['skill' => 'hello-files', 'prompt' => 'Say hello']);
    }
}
