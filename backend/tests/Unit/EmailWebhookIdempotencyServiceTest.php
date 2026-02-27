<?php

namespace App\Tests\Unit;

use App\Entity\Message;
use App\Repository\MessageRepository;
use App\Service\EmailWebhookIdempotencyService;
use PHPUnit\Framework\TestCase;

class EmailWebhookIdempotencyServiceTest extends TestCase
{
    public function testFindDuplicateUsesExternalMessageIdWhenAvailable(): void
    {
        $expectedMessage = new Message();
        $repository = $this->createMock(MessageRepository::class);
        $repository->expects($this->once())
            ->method('findLatestIncomingEmailByExternalId')
            ->with('<abc@example.com>', 'sender@example.com')
            ->willReturn($expectedMessage);
        $repository->expects($this->never())
            ->method('findRecentIncomingEmailByFingerprint');

        $service = new EmailWebhookIdempotencyService($repository);
        $result = $service->findDuplicate(
            'Sender@Example.com ',
            ' Smart@Synaplan.net',
            'Subject',
            'Body',
            ' <ABC@EXAMPLE.COM> '
        );

        $this->assertSame($expectedMessage, $result['existing']);
        $this->assertSame('<abc@example.com>', $result['normalized_message_id']);
        $this->assertNotSame('', $result['fingerprint']);
    }

    public function testFindDuplicateFallsBackToFingerprintWithoutMessageId(): void
    {
        $expectedMessage = new Message();
        $repository = $this->createMock(MessageRepository::class);
        $repository->expects($this->never())
            ->method('findLatestIncomingEmailByExternalId');
        $repository->expects($this->once())
            ->method('findRecentIncomingEmailByFingerprint')
            ->with(
                hash('sha256', "sender@example.com\nsmart@synaplan.net\nTest\nHello"),
                180
            )
            ->willReturn($expectedMessage);

        $service = new EmailWebhookIdempotencyService($repository);
        $result = $service->findDuplicate(
            'sender@example.com',
            'smart@synaplan.net',
            'Test',
            'Hello',
            null
        );

        $this->assertSame($expectedMessage, $result['existing']);
        $this->assertNull($result['normalized_message_id']);
        $this->assertSame(hash('sha256', "sender@example.com\nsmart@synaplan.net\nTest\nHello"), $result['fingerprint']);
    }
}
