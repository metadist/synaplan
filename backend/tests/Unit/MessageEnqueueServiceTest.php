<?php

namespace App\Tests\Unit;

use App\Entity\Message;
use App\Entity\User;
use App\Message\ProcessMessageCommand;
use App\Repository\MessageRepository;
use App\Service\MessageEnqueueService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class MessageEnqueueServiceTest extends TestCase
{
    private EntityManagerInterface $em;
    private MessageBusInterface $messageBus;
    private LoggerInterface $logger;
    private MessageEnqueueService $service;
    private MessageRepository $messageRepository;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->messageRepository = $this->createMock(MessageRepository::class);

        $this->service = new MessageEnqueueService(
            $this->em,
            $this->messageBus,
            $this->logger
        );
    }

    public function testEnqueueMessageSuccess(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $text = 'Test message';
        $options = ['tracking_id' => 12345];

        // Mock persist to set ID on message
        $this->em->expects($this->once())
            ->method('persist')
            ->willReturnCallback(function ($message) {
                $reflection = new \ReflectionClass($message);
                $property = $reflection->getProperty('id');
                $property->setAccessible(true);
                $property->setValue($message, 42); // Set mock ID
            });

        $this->em->expects($this->once())
            ->method('flush');

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(ProcessMessageCommand::class))
            ->willReturn(new Envelope(new \stdClass()));

        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('enqueued'), $this->isType('array'));

        $result = $this->service->enqueueMessage($user, $text, $options);

        $this->assertArrayHasKey('tracking_id', $result);
        $this->assertArrayHasKey('message_id', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertEquals('queued', $result['status']);
        $this->assertEquals(12345, $result['tracking_id']);
    }

    public function testEnqueueMessageWithDefaultOptions(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(5);

        $text = 'Message without options';

        $this->em->expects($this->once())
            ->method('persist')
            ->willReturnCallback(function ($message) {
                $reflection = new \ReflectionClass($message);
                $property = $reflection->getProperty('id');
                $property->setAccessible(true);
                $property->setValue($message, 100);
            });
        $this->em->expects($this->once())->method('flush');
        $this->messageBus->method('dispatch')->willReturn(new Envelope(new \stdClass()));

        $result = $this->service->enqueueMessage($user, $text);

        $this->assertArrayHasKey('tracking_id', $result);
        $this->assertIsNumeric($result['tracking_id']); // Should be timestamp
    }

    public function testEnqueueMessageWithCustomOptions(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(2);

        $text = 'Custom message';
        $options = [
            'tracking_id' => 98765,
            'provider_index' => 'TELEGRAM',
            'message_type' => 'CHAT',
            'has_file' => 1,
            'file_path' => '/path/to/file.pdf',
            'file_type' => 'pdf',
            'reasoning' => true,
        ];

        $capturedMessage = null;
        $this->em->expects($this->once())
            ->method('persist')
            ->willReturnCallback(function ($message) use (&$capturedMessage) {
                $capturedMessage = $message;
                $reflection = new \ReflectionClass($message);
                $property = $reflection->getProperty('id');
                $property->setAccessible(true);
                $property->setValue($message, 200);
            });

        $this->em->expects($this->once())->method('flush');
        $this->messageBus->method('dispatch')->willReturn(new Envelope(new \stdClass()));

        $result = $this->service->enqueueMessage($user, $text, $options);

        $this->assertEquals(98765, $result['tracking_id']);
    }

    public function testGetMessageStatusFound(): void
    {
        $messageId = 10;

        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getTrackingId')->willReturn(123456);
        $message->method('getStatus')->willReturn('complete');
        $message->method('getTopic')->willReturn('CHAT');
        $message->method('getLanguage')->willReturn('en');

        $responseMessage = $this->createMock(Message::class);
        $responseMessage->method('getText')->willReturn('AI response text');
        $responseMessage->method('getProviderIndex')->willReturn('openai');

        $this->em->expects($this->exactly(2))
            ->method('getRepository')
            ->with(Message::class)
            ->willReturn($this->messageRepository);

        $this->messageRepository->expects($this->once())
            ->method('find')
            ->with($messageId)
            ->willReturn($message);

        $this->messageRepository->expects($this->once())
            ->method('findOneBy')
            ->with([
                'userId' => 1,
                'trackingId' => 123456,
                'direction' => 'OUT',
            ])
            ->willReturn($responseMessage);

        $result = $this->service->getMessageStatus($messageId);

        $this->assertIsArray($result);
        $this->assertEquals(123456, $result['tracking_id']);
        $this->assertEquals('complete', $result['status']);
        $this->assertEquals('CHAT', $result['topic']);
        $this->assertEquals('en', $result['language']);
        $this->assertEquals('AI response text', $result['response']);
        $this->assertEquals('openai', $result['provider']);
    }

    public function testGetMessageStatusNotFound(): void
    {
        $messageId = 999;

        $this->em->expects($this->once())
            ->method('getRepository')
            ->with(Message::class)
            ->willReturn($this->messageRepository);

        $this->messageRepository->expects($this->once())
            ->method('find')
            ->with($messageId)
            ->willReturn(null);

        $result = $this->service->getMessageStatus($messageId);

        $this->assertNull($result);
    }

    public function testGetMessageStatusWithoutResponse(): void
    {
        $messageId = 15;

        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(3);
        $message->method('getTrackingId')->willReturn(456789);
        $message->method('getStatus')->willReturn('processing');
        $message->method('getTopic')->willReturn('CHAT');
        $message->method('getLanguage')->willReturn('de');

        $this->em->expects($this->exactly(2))
            ->method('getRepository')
            ->with(Message::class)
            ->willReturn($this->messageRepository);

        $this->messageRepository->method('find')->willReturn($message);
        $this->messageRepository->method('findOneBy')->willReturn(null); // No response yet

        $result = $this->service->getMessageStatus($messageId);

        $this->assertIsArray($result);
        $this->assertEquals('processing', $result['status']);
        $this->assertNull($result['response']);
        $this->assertNull($result['provider']);
    }

    public function testEnqueueMessageDispatchesCorrectCommand(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(7);

        $text = 'Test dispatch';
        $options = ['reasoning' => true];

        $capturedCommand = null;

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($command) use (&$capturedCommand) {
                $capturedCommand = $command;

                return new Envelope($command);
            });

        $this->em->method('persist')
            ->willReturnCallback(function ($message) {
                $reflection = new \ReflectionClass($message);
                $property = $reflection->getProperty('id');
                $property->setAccessible(true);
                $property->setValue($message, 300);
            });
        $this->em->method('flush');

        $result = $this->service->enqueueMessage($user, $text, $options);

        $this->assertInstanceOf(ProcessMessageCommand::class, $capturedCommand);
    }
}
