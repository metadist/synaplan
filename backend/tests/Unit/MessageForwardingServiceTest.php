<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Chat;
use App\Entity\Message;
use App\Repository\MessageRepository;
use App\Service\Message\MessageForwardingService;
use App\Service\UserMemoryService;
use App\Service\WhatsAppService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MessageForwardingServiceTest extends TestCase
{
    private WhatsAppService&MockObject $whatsAppService;
    private MessageRepository&MockObject $messageRepository;
    private UserMemoryService&MockObject $memoryService;
    private EntityManagerInterface&MockObject $em;
    private LoggerInterface&MockObject $logger;
    private MessageForwardingService $service;

    protected function setUp(): void
    {
        $this->whatsAppService = $this->createMock(WhatsAppService::class);
        $this->messageRepository = $this->createMock(MessageRepository::class);
        $this->memoryService = $this->createMock(UserMemoryService::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->memoryService->method('resolveMemoryTags')
            ->willReturnArgument(0);

        $this->service = new MessageForwardingService(
            $this->whatsAppService,
            $this->messageRepository,
            $this->memoryService,
            $this->em,
            $this->logger,
        );
    }

    public function testSkipsNonWhatsAppChats(): void
    {
        $chat = $this->createChatWithSource('web');

        $this->whatsAppService->expects($this->never())->method('sendMessage');
        $this->messageRepository->expects($this->never())->method('findLatestInboundByChannel');

        $this->service->forwardIfNeeded($chat, 'Hello');
    }

    public function testSkipsEmailChats(): void
    {
        $chat = $this->createChatWithSource('email');

        $this->whatsAppService->expects($this->never())->method('sendMessage');

        $this->service->forwardIfNeeded($chat, 'Hello');
    }

    public function testForwardsToWhatsAppWhenChatSourceIsWhatsApp(): void
    {
        $chat = $this->createChatWithSource('whatsapp');

        $inbound = $this->createInboundMessageWithMeta('+491234567890', 'phone-number-id-123');

        $this->whatsAppService->method('isAvailable')->willReturn(true);
        $this->messageRepository->method('findLatestInboundByChannel')
            ->with($chat->getId(), 'whatsapp')
            ->willReturn($inbound);

        $this->whatsAppService->expects($this->once())
            ->method('sendMessage')
            ->with('+491234567890', 'AI response text', 'phone-number-id-123')
            ->willReturn(['success' => true, 'message_id' => 'wa_msg_123']);

        $this->service->forwardIfNeeded($chat, 'AI response text');
    }

    public function testLogsWarningWhenWhatsAppUnavailable(): void
    {
        $chat = $this->createChatWithSource('whatsapp');

        $this->whatsAppService->method('isAvailable')->willReturn(false);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'WhatsApp service unavailable, cannot forward message',
                $this->anything()
            );

        $this->whatsAppService->expects($this->never())->method('sendMessage');

        $this->service->forwardIfNeeded($chat, 'Hello');
    }

    public function testLogsWarningWhenNoInboundMessageFound(): void
    {
        $chat = $this->createChatWithSource('whatsapp');

        $this->whatsAppService->method('isAvailable')->willReturn(true);
        $this->messageRepository->method('findLatestInboundByChannel')->willReturn(null);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Cannot forward to WhatsApp: no inbound message found',
                $this->anything()
            );

        $this->whatsAppService->expects($this->never())->method('sendMessage');

        $this->service->forwardIfNeeded($chat, 'Hello');
    }

    public function testLogsWarningWhenMetadataMissing(): void
    {
        $chat = $this->createChatWithSource('whatsapp');
        $inbound = $this->createInboundMessageWithMeta(null, null);

        $this->whatsAppService->method('isAvailable')->willReturn(true);
        $this->messageRepository->method('findLatestInboundByChannel')->willReturn($inbound);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Cannot forward to WhatsApp: missing contact metadata',
                $this->anything()
            );

        $this->whatsAppService->expects($this->never())->method('sendMessage');

        $this->service->forwardIfNeeded($chat, 'Hello');
    }

    public function testSwallowsExceptionsAndLogs(): void
    {
        $chat = $this->createChatWithSource('whatsapp');
        $inbound = $this->createInboundMessageWithMeta('+491234567890', 'phone-id');

        $this->whatsAppService->method('isAvailable')->willReturn(true);
        $this->messageRepository->method('findLatestInboundByChannel')->willReturn($inbound);
        $this->whatsAppService->method('sendMessage')
            ->willThrowException(new \RuntimeException('API connection failed'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Exception while forwarding message to WhatsApp',
                $this->callback(fn (array $ctx): bool => 'API connection failed' === $ctx['error'])
            );

        $this->service->forwardIfNeeded($chat, 'Hello');
    }

    private function createChatWithSource(string $source): Chat&MockObject
    {
        $chat = $this->createMock(Chat::class);
        $chat->method('getSource')->willReturn($source);
        $chat->method('getId')->willReturn(42);

        return $chat;
    }

    private function createInboundMessageWithMeta(?string $fromPhone, ?string $phoneNumberId): Message&MockObject
    {
        $message = $this->createMock(Message::class);
        $message->method('getMeta')->willReturnCallback(
            fn (string $key): ?string => match ($key) {
                'from_phone' => $fromPhone,
                'to_phone_number_id' => $phoneNumberId,
                default => null,
            }
        );

        return $message;
    }
}
