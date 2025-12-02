<?php

namespace App\Service;

use App\Entity\Message;
use App\Entity\User;
use App\Message\ProcessMessageCommand;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Service zum Enqueuen von Messages für async Processing
 */
class MessageEnqueueService
{
    public function __construct(
        private EntityManagerInterface $em,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger
    ) {}

    /**
     * Erstellt Message und queued sie für async Processing
     * 
     * @param User $user
     * @param string $text
     * @param array $options Optionale Parameter (files, reasoning, etc.)
     * @return array ['tracking_id' => ..., 'message_id' => ...]
     */
    public function enqueueMessage(User $user, string $text, array $options = []): array
    {
        $trackingId = $options['tracking_id'] ?? time();
        
        // Create message entity
        $message = new Message();
        $message->setUserId($user->getId());
        $message->setTrackingId($trackingId);
        $message->setProviderIndex($options['provider_index'] ?? 'WEB');
        $message->setUnixTimestamp(time());
        $message->setDateTime(date('YmdHis'));
        $message->setMessageType($options['message_type'] ?? 'WEB');
        $message->setFile($options['has_file'] ?? 0);
        $message->setFilePath($options['file_path'] ?? '');
        $message->setFileType($options['file_type'] ?? '');
        $message->setTopic('UNKNOWN'); // Will be set by classifier
        $message->setLanguage('NN'); // Will be detected
        $message->setText($text);
        $message->setDirection('IN');
        $message->setStatus('queued'); // Initially queued

        $this->em->persist($message);
        $this->em->flush();

        $this->logger->info('📨 Message enqueued', [
            'message_id' => $message->getId(),
            'tracking_id' => $trackingId,
            'user_id' => $user->getId()
        ]);

        // Dispatch to queue
        $this->messageBus->dispatch(
            new ProcessMessageCommand(
                messageId: $message->getId(),
                userId: $user->getId(),
                options: $options
            )
        );

        return [
            'tracking_id' => $trackingId,
            'message_id' => $message->getId(),
            'status' => 'queued',
            'estimated_time_seconds' => 10,
        ];
    }

    /**
     * Holt Status einer Message
     */
    public function getMessageStatus(int $messageId): ?array
    {
        $message = $this->em->getRepository(Message::class)->find($messageId);
        
        if (!$message) {
            return null;
        }

        // Find response message
        $responseMessage = $this->em->getRepository(Message::class)->findOneBy([
            'userId' => $message->getUserId(),
            'trackingId' => $message->getTrackingId(),
            'direction' => 'OUT'
        ]);

        return [
            'tracking_id' => $message->getTrackingId(),
            'status' => $message->getStatus(),
            'topic' => $message->getTopic(),
            'language' => $message->getLanguage(),
            'response' => $responseMessage?->getText(),
            'provider' => $responseMessage?->getProviderIndex(),
        ];
    }
}

