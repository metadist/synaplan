<?php

namespace App\MessageHandler;

use App\Entity\Message;
use App\Message\ProcessMessageCommand;
use App\Service\Message\MessageProcessor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler für ProcessMessageCommand
 * 
 * Verarbeitet Message async im Hintergrund
 * Worker: php bin/console messenger:consume async_ai_high -vv
 */
#[AsMessageHandler]
class ProcessMessageCommandHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private MessageProcessor $processor,
        private LoggerInterface $logger
    ) {}

    public function __invoke(ProcessMessageCommand $command): void
    {
        $messageId = $command->getMessageId();
        
        $this->logger->info('🟢 Queue Worker: Processing message', [
            'message_id' => $messageId,
            'user_id' => $command->getUserId()
        ]);

        // Load message from DB
        $message = $this->em->getRepository(Message::class)->find($messageId);
        
        if (!$message) {
            $this->logger->error('Message not found', ['message_id' => $messageId]);
            return;
        }

        // Update status
        $message->setStatus('processing');
        $this->em->flush();

        try {
            // Process message (non-streaming for queue)
            $result = $this->processor->process($message);

            if (!$result['success']) {
                throw new \RuntimeException($result['error']);
            }

            // Update message with result
            $classification = $result['classification'];
            $response = $result['response'];

            $message->setTopic($classification['topic']);
            $message->setLanguage($classification['language']);
            $message->setStatus('complete');

            // Create outgoing message
            $outgoingMessage = new Message();
            $outgoingMessage->setUserId($message->getUserId());
            $outgoingMessage->setTrackingId($message->getTrackingId());
            $outgoingMessage->setProviderIndex($response['metadata']['provider'] ?? 'unknown');
            $outgoingMessage->setUnixTimestamp(time());
            $outgoingMessage->setDateTime(date('YmdHis'));
            $outgoingMessage->setMessageType($message->getMessageType());
            $outgoingMessage->setFile(0);
            $outgoingMessage->setTopic($classification['topic']);
            $outgoingMessage->setLanguage($classification['language']);
            $outgoingMessage->setText($response['content']);
            $outgoingMessage->setDirection('OUT');
            $outgoingMessage->setStatus('complete');

            $this->em->persist($outgoingMessage);
            $this->em->flush();

            $this->logger->info('✅ Queue Worker: Message processed successfully', [
                'message_id' => $messageId,
                'topic' => $classification['topic']
            ]);

        } catch (\Exception $e) {
            $this->logger->error('🔴 Queue Worker: Message processing failed', [
                'message_id' => $messageId,
                'error' => $e->getMessage()
            ]);

            $message->setStatus('failed');
            $this->em->flush();

            throw $e; // Rethrow for Messenger retry logic
        }
    }
}

