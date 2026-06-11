<?php

namespace App\Tests\Unit;

use App\Service\InternalEmailService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

class InternalEmailServiceTest extends TestCase
{
    public function testVerificationEmailStructure(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $twig = $this->createMock(Environment::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        // Expect send to be called once
        $mailer->expects($this->once())
            ->method('send');

        $service = new InternalEmailService($mailer, $twig, $translator, $logger);
        $service->sendVerificationEmail('test@example.com', 'test_token_123');

        $this->assertTrue(true, 'Verification email method called successfully');
    }

    public function testPasswordResetEmailStructure(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $twig = $this->createMock(Environment::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $mailer->expects($this->once())
            ->method('send');

        $service = new InternalEmailService($mailer, $twig, $translator, $logger);
        $service->sendPasswordResetEmail('test@example.com', 'reset_token_456');

        $this->assertTrue(true, 'Password reset email method called successfully');
    }

    public function testAiResponseEmailWithMinimalParams(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $twig = $this->createMock(Environment::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $mailer->expects($this->once())
            ->method('send');

        $service = new InternalEmailService($mailer, $twig, $translator, $logger);
        $service->sendAiResponseEmail(
            'user@example.com',
            'Test Subject',
            'Rate limit exceeded. Please try again later.',
            '<msg-123@mail.example.com>',
            originalRecipient: 'smart@synaplan.net',
        );
    }

    public function testAiResponseEmailWithResetTime(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $twig = $this->createMock(Environment::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $mailer->expects($this->once())
            ->method('send');

        $service = new InternalEmailService($mailer, $twig, $translator, $logger);
        $service->sendAiResponseEmail(
            'user@example.com',
            'Test Subject',
            "Rate limit exceeded. Please try again later.\n\nYour limit will reset at: 2026-03-13 15:00:00",
            originalRecipient: 'smart@synaplan.net',
        );
    }

    /**
     * Sprint 5: a multi-task turn can produce several files. The primary
     * attachment plus each additional attachment must be attached to the email.
     */
    public function testAiResponseEmailAttachesPrimaryAndAdditionalFiles(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $twig = $this->createMock(Environment::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $primary = (string) tempnam(sys_get_temp_dir(), 'mt_audio_');
        $extra = (string) tempnam(sys_get_temp_dir(), 'mt_img_');
        file_put_contents($primary, 'audio-bytes');
        file_put_contents($extra, 'image-bytes');

        $captured = null;
        $mailer->expects($this->once())
            ->method('send')
            ->willReturnCallback(function ($email) use (&$captured): void {
                $captured = $email;
            });

        $service = new InternalEmailService($mailer, $twig, $translator, $logger);
        $service->sendAiResponseEmail(
            'user@example.com',
            'Test Subject',
            'Here are your results.',
            attachmentPath: $primary,
            mediaType: 'audio',
            additionalAttachmentPaths: [$extra],
        );

        self::assertNotNull($captured);
        // Primary (audio) + one additional (image) = 2 attachments.
        self::assertCount(2, $captured->getAttachments());

        @unlink($primary);
        @unlink($extra);
    }

    public function testAiResponseEmailSkipsMissingAdditionalFiles(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $twig = $this->createMock(Environment::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $captured = null;
        $mailer->expects($this->once())
            ->method('send')
            ->willReturnCallback(function ($email) use (&$captured): void {
                $captured = $email;
            });

        $service = new InternalEmailService($mailer, $twig, $translator, $logger);
        $service->sendAiResponseEmail(
            'user@example.com',
            'Test Subject',
            'No attachments here.',
            additionalAttachmentPaths: ['/tmp/does-not-exist-'.uniqid().'.mp3', ''],
        );

        self::assertNotNull($captured);
        self::assertCount(0, $captured->getAttachments());
    }

    // ---- email_me DAG node: sendTaskResultEmail ----

    /**
     * Service whose mailer captures the (single) sent email into $captured.
     */
    private function taskResultService(?\Symfony\Component\Mime\Email &$captured): InternalEmailService
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())
            ->method('send')
            ->willReturnCallback(function ($email) use (&$captured): void {
                $captured = $email;
            });

        return new InternalEmailService(
            $mailer,
            $this->createMock(Environment::class),
            $this->createMock(TranslatorInterface::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    /**
     * The multi-MIME contract: the FIRST image is embedded inline (CID) so
     * mail clients render it in the body; every other file (audio before the
     * image, calendar invite after it) is a regular attachment.
     */
    public function testTaskResultEmailEmbedsFirstImageAndAttachesRest(): void
    {
        $mp3 = sys_get_temp_dir().'/task_'.uniqid().'.mp3';
        $png = sys_get_temp_dir().'/task_'.uniqid().'.png';
        $ics = sys_get_temp_dir().'/task_'.uniqid().'.ics';
        file_put_contents($mp3, 'audio-bytes');
        file_put_contents($png, 'image-bytes');
        file_put_contents($ics, 'BEGIN:VCALENDAR');

        $captured = null;
        $service = $this->taskResultService($captured);
        $service->sendTaskResultEmail('owner@example.com', 'Your results', "# Spring Poem\n\nRoses are red.", [
            ['path' => $mp3, 'type' => 'audio'],
            ['path' => $png, 'type' => 'image'],
            ['path' => $ics, 'type' => 'document'],
        ]);

        self::assertNotNull($captured);
        self::assertSame('Your results', $captured->getSubject());
        self::assertCount(3, $captured->getAttachments());

        $inline = array_values(array_filter(
            $captured->getAttachments(),
            static fn ($part) => 'inline' === $part->getDisposition(),
        ));
        self::assertCount(1, $inline, 'exactly the first image must be embedded inline');
        self::assertStringContainsString('cid:generated-image', (string) $captured->getHtmlBody());
        // Markdown body rendered to HTML, raw markdown kept as the text part.
        self::assertStringContainsString('<h1>Spring Poem</h1>', (string) $captured->getHtmlBody());
        self::assertStringContainsString('# Spring Poem', (string) $captured->getTextBody());

        @unlink($mp3);
        @unlink($png);
        @unlink($ics);
    }

    public function testTaskResultEmailWithTextOnly(): void
    {
        $captured = null;
        $service = $this->taskResultService($captured);
        $service->sendTaskResultEmail('owner@example.com', 'Your results', 'Just the poem.');

        self::assertNotNull($captured);
        self::assertCount(0, $captured->getAttachments());
        self::assertStringNotContainsString('cid:generated-image', (string) $captured->getHtmlBody());
    }

    public function testTaskResultEmailSkipsMissingFiles(): void
    {
        $captured = null;
        $service = $this->taskResultService($captured);
        $service->sendTaskResultEmail('owner@example.com', 'Your results', 'Body.', [
            ['path' => '/tmp/does-not-exist-'.uniqid().'.png', 'type' => 'image'],
        ]);

        self::assertNotNull($captured);
        self::assertCount(0, $captured->getAttachments());
    }
}
