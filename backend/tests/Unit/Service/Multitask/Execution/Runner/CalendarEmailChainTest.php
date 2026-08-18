<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution\Runner;

use App\Entity\Message;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Calendar\CalendarEventService;
use App\Service\File\FileStorageService;
use App\Service\InternalEmailService;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\Runner\CalendarEventRunner;
use App\Service\Multitask\Execution\Runner\EmailMeRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Regression lock for Phase M utterance U2 ("...and mail the calendar entry
 * to me"): the .ics produced by the `calendar_event` node must arrive as a
 * real attachment of the `email_me` node through the shared NodeContext.
 *
 * This is the shipped end-to-end path that every Phase M planner change (M5,
 * M6) must NOT regress — it uses the REAL CalendarEventService (actual RFC
 * 5545 output) and the REAL reference resolution (`$n1.file`), mocking only
 * storage placement and SMTP.
 */
final class CalendarEmailChainTest extends TestCase
{
    private const USER_ID = 7;

    private string $uploadDir;

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/calchain_test_'.uniqid();
        mkdir($this->uploadDir.'/7/000', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->uploadDir.'/7/000', $this->uploadDir.'/7', $this->uploadDir] as $dir) {
            array_map('unlink', glob($dir.'/*') ?: []);
            @rmdir($dir);
        }
    }

    public function testCalendarInviteIsMailedToTheAccountOwnerAsIcsAttachment(): void
    {
        $context = $this->context();

        // ---- n1: calendar_event (real ICS builder, storage placed into the temp uploads tree) ----
        $fileStorage = $this->createMock(FileStorageService::class);
        $fileStorage->method('storeRawContent')->willReturnCallback(
            function (string $content, int $userId, string $filename, string $mime): array {
                self::assertSame(self::USER_ID, $userId);
                self::assertSame('text/calendar', $mime);
                $relative = self::USER_ID.'/000/'.$filename;
                file_put_contents($this->uploadDir.'/'.$relative, $content);

                return ['success' => true, 'path' => $relative, 'size' => strlen($content), 'mime' => $mime];
            }
        );

        $calendarNode = new TaskNode('n1', Capability::CalendarEvent, [], [], [
            'title' => 'Marketing Strategy',
            'start' => '2026-08-19T10:00:00',
            'timezone' => 'Europe/Berlin',
            'duration_minutes' => 60,
        ]);

        $calendarRunner = new CalendarEventRunner(
            new CalendarEventService(),
            $fileStorage,
            $this->createMock(LoggerInterface::class),
        );

        $calendarResult = $calendarRunner->run($calendarNode, $context);
        self::assertTrue($calendarResult->isSuccessful(), (string) $calendarResult->error);
        $context->setResult('n1', $calendarResult);

        // ---- n2: email_me consuming $n1.file through the shared context ----
        $sentAttachments = null;
        $sentBody = null;
        $emailService = $this->createMock(InternalEmailService::class);
        $emailService->expects(self::once())
            ->method('sendTaskResultEmail')
            ->willReturnCallback(function (string $to, string $subject, string $markdown, array $attachments) use (&$sentAttachments, &$sentBody): void {
                self::assertSame('alice@example.com', $to);
                $sentBody = $markdown;
                $sentAttachments = $attachments;
            });

        $emailNode = new TaskNode('n2', Capability::EmailMe, ['n1'], [
            'text' => '$n1.text',
            'attachments' => ['$n1.file'],
        ]);

        $emailResult = $this->emailRunner($emailService)->run($emailNode, $context);

        self::assertTrue($emailResult->isSuccessful(), (string) $emailResult->error);
        self::assertSame(1, $emailResult->metadata['attachment_count']);

        // The mailed attachment IS the calendar file the runner stored.
        self::assertIsArray($sentAttachments);
        self::assertCount(1, $sentAttachments);
        $attachedPath = $sentAttachments[0]['path'];
        self::assertFileExists($attachedPath);
        self::assertStringEndsWith('.ics', $attachedPath);

        // And it is a real RFC 5545 invite for the requested meeting.
        $ics = (string) file_get_contents($attachedPath);
        self::assertStringContainsString('BEGIN:VCALENDAR', $ics);
        self::assertStringContainsString('BEGIN:VEVENT', $ics);
        self::assertStringContainsString('SUMMARY:Marketing Strategy', $ics);
        // 2026-08-19 10:00 Europe/Berlin (CEST, UTC+2) == 08:00Z.
        self::assertStringContainsString('DTSTART:20260819T080000Z', $ics);
        self::assertStringContainsString('DTEND:20260819T090000Z', $ics);

        // The email body carries the calendar node's confirmation text.
        self::assertIsString($sentBody);
        self::assertStringContainsString('Marketing Strategy', $sentBody);
    }

    private function context(): NodeContext
    {
        $message = $this->createMock(Message::class);
        $message->method('getText')->willReturn(
            "Create a meeting reminder for tomorrow at 10am for 'Marketing Strategy' and mail the calendar entry to me",
        );
        $message->method('getFileText')->willReturn('');
        $message->method('getLanguage')->willReturn('en');
        $message->method('getFile')->willReturn(0);
        $message->method('getFilePath')->willReturn('');
        $message->method('getFiles')->willReturn(new ArrayCollection());

        return new NodeContext($message, [], self::USER_ID, ['language' => 'en']);
    }

    private function emailRunner(InternalEmailService $emailService): EmailMeRunner
    {
        $user = $this->createMock(User::class);
        $user->method('getMail')->willReturn('alice@example.com');
        $user->method('isEmailVerified')->willReturn(true);

        $users = $this->createMock(UserRepository::class);
        $users->method('find')->willReturn($user);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $params = []): string => match ($id) {
                'email.task_result.subject' => 'Your Synaplan results',
                'email.task_result.sent_confirmation' => 'Sent to '.($params['%email%'] ?? '?'),
                default => $id,
            }
        );

        return new EmailMeRunner(
            $emailService,
            $users,
            $translator,
            $this->createMock(LoggerInterface::class),
            $this->uploadDir,
        );
    }
}
