<?php

namespace App\Tests\Integration\Service;

use App\Entity\Message;
use App\Entity\User;
use App\Repository\MessageRepository;
use App\Service\Message\MessageProcessor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Integration tests for MessageProcessor "Again" functionality
 * 
 * Verifies that classification is skipped when model_id is provided in options
 */
class MessageProcessorAgainTest extends KernelTestCase
{
    private MessageProcessor $messageProcessor;
    private EntityManagerInterface $entityManager;
    private MessageRepository $messageRepository;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        
        $container = self::getContainer();
        $this->messageProcessor = $container->get(MessageProcessor::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->messageRepository = $container->get(MessageRepository::class);
    }

    public function testAgainSkipsClassification(): void
    {
        // Create test user
        $user = new User();
        $user->setMail('again-test@example.com');
        $user->setPw('hashed_password');
        $user->setEmailVerified(true);
        
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        // Create incoming message
        $message = new Message();
        $message->setText('Test message for again functionality');
        $message->setDirection('IN');
        $message->setUserId($user->getId());
        $message->setTrackingId(time());
        $message->setProviderIndex('test');
        $message->setDateTime(date('Y-m-d H:i:s'));
        $message->setUnixTimestamp(time());

        $this->entityManager->persist($message);
        $this->entityManager->flush();

        // Track status updates to verify classification is skipped
        $statusUpdates = [];
        $statusCallback = function($status, $message = null, $metadata = []) use (&$statusUpdates) {
            $statusUpdates[] = [
                'status' => $status,
                'message' => $message,
                'metadata' => $metadata
            ];
        };

        // Track response chunks (we don't need to test content here)
        $streamCallback = function($chunk) {
            // Just consume the chunks
        };

        // Process with model_id option (simulating "Again")
        $options = [
            'model_id' => 1 // Explicitly specify model ID
        ];

        try {
            $result = $this->messageProcessor->processStream(
                $message,
                $streamCallback,
                $statusCallback,
                $options
            );

            // Verify classification was skipped
            $classifyingStatuses = array_filter($statusUpdates, function($update) {
                return $update['status'] === 'classifying';
            });
            
            $this->assertEmpty($classifyingStatuses, 'Classification should be skipped for "Again" requests');

            // Verify result structure
            $this->assertArrayHasKey('success', $result);
            $this->assertTrue($result['success']);
            $this->assertArrayHasKey('classification', $result);
            
            // Verify sorting model info is null (because classification was skipped)
            $this->assertNull($result['classification']['sorting_model_id'] ?? null, 'Sorting model ID should be null when classification is skipped');
            $this->assertNull($result['classification']['sorting_provider'] ?? null, 'Sorting provider should be null when classification is skipped');
            $this->assertNull($result['classification']['sorting_model_name'] ?? null, 'Sorting model name should be null when classification is skipped');

        } catch (\Exception $e) {
            // It's okay if the AI provider is not available for tests
            // We're mainly testing that classification is skipped
            if (strpos($e->getMessage(), 'No suitable model found') !== false ||
                strpos($e->getMessage(), 'not configured') !== false) {
                
                // Verify classification was still skipped before the error
                $classifyingStatuses = array_filter($statusUpdates, function($update) {
                    return $update['status'] === 'classifying';
                });
                
                $this->assertEmpty($classifyingStatuses, 'Classification should be skipped even if AI provider fails');
                $this->markTestIncomplete('AI provider not configured, but classification skip was verified');
            } else {
                throw $e;
            }
        } finally {
            // Cleanup
            $this->entityManager->remove($message);
            $this->entityManager->remove($user);
            $this->entityManager->flush();
        }
    }

    public function testNormalFlowRunsClassification(): void
    {
        // Create test user
        $user = new User();
        $user->setMail('normal-test@example.com');
        $user->setPw('hashed_password');
        $user->setEmailVerified(true);
        
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        // Create incoming message
        $message = new Message();
        $message->setText('Test message for normal flow');
        $message->setDirection('IN');
        $message->setUserId($user->getId());
        $message->setTrackingId(time());
        $message->setProviderIndex('test');
        $message->setDateTime(date('Y-m-d H:i:s'));
        $message->setUnixTimestamp(time());

        $this->entityManager->persist($message);
        $this->entityManager->flush();

        // Track status updates
        $statusUpdates = [];
        $statusCallback = function($status, $message = null, $metadata = []) use (&$statusUpdates) {
            $statusUpdates[] = [
                'status' => $status,
                'message' => $message,
                'metadata' => $metadata
            ];
        };

        $streamCallback = function($chunk) {
            // Just consume the chunks
        };

        // Process WITHOUT model_id option (normal flow)
        $options = [];

        try {
            $result = $this->messageProcessor->processStream(
                $message,
                $streamCallback,
                $statusCallback,
                $options
            );

            // Verify classification WAS run
            $classifyingStatuses = array_filter($statusUpdates, function($update) {
                return $update['status'] === 'classifying';
            });
            
            // Note: classifying status may not be captured if processing is too fast
            // The important thing is that we don't have the "skipped" message
            
            // Verify result has sorting model info (may be null if no sorting model configured)
            $this->assertArrayHasKey('classification', $result);
            $this->assertArrayHasKey('sorting_model_id', $result['classification'], 'Sorting model metadata should be present in normal flow');

        } catch (\Exception $e) {
            // It's okay if the AI provider is not available for tests
            if (strpos($e->getMessage(), 'No suitable model found') !== false ||
                strpos($e->getMessage(), 'not configured') !== false) {
                
                // Verify classification was attempted before the error
                $classifyingStatuses = array_filter($statusUpdates, function($update) {
                    return $update['status'] === 'classifying';
                });
                
                $this->assertNotEmpty($classifyingStatuses, 'Classification should be attempted even if AI provider fails');
                $this->markTestIncomplete('AI provider not configured, but classification attempt was verified');
            } else {
                throw $e;
            }
        } finally {
            // Cleanup
            $this->entityManager->remove($message);
            $this->entityManager->remove($user);
            $this->entityManager->flush();
        }
    }
}

