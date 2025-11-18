<?php

namespace App\Tests\Unit;

use App\Service\Message\MessageClassifier;
use App\Service\Message\MessageSorter;
use App\Repository\MessageMetaRepository;
use App\Entity\Message;
use App\Entity\MessageMeta;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MessageClassifierTest extends TestCase
{
    private MessageSorter $messageSorter;
    private MessageMetaRepository $messageMetaRepository;
    private LoggerInterface $logger;
    private MessageClassifier $service;

    protected function setUp(): void
    {
        $this->messageSorter = $this->createMock(MessageSorter::class);
        $this->messageMetaRepository = $this->createMock(MessageMetaRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new MessageClassifier(
            $this->messageSorter,
            $this->messageMetaRepository,
            $this->logger
        );
    }

    public function testClassifyWithPromptOverride(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(1);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturn('Test message');
        $message->method('getLanguage')->willReturn('en');

        $promptMeta = $this->createMock(MessageMeta::class);
        $promptMeta->method('getMetaValue')->willReturn('tools:pic');

        $this->messageMetaRepository
            ->method('findOneBy')
            ->willReturnCallback(function($criteria) use ($promptMeta) {
                if ($criteria['metaKey'] === 'PROMPTID') {
                    return $promptMeta;
                }
                return null;
            });

        $result = $this->service->classify($message);

        $this->assertEquals('tools:pic', $result['topic']);
        $this->assertEquals('en', $result['language']);
        $this->assertEquals('prompt_override', $result['source']);
        $this->assertTrue($result['skip_sorting']);
    }

    public function testClassifyWithToolCommand(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(2);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturn('/pic generate a cat');
        $message->method('getLanguage')->willReturn('en');

        $this->messageMetaRepository
            ->method('findOneBy')
            ->willReturn(null);

        $result = $this->service->classify($message);

        $this->assertEquals('tools:pic', $result['topic']);
        $this->assertEquals('tool_command', $result['source']);
        $this->assertTrue($result['skip_sorting']);
    }

    public function testClassifyWithAiSorting(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(3);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturn('Hello, how are you?');
        $message->method('getLanguage')->willReturn('en');
        $message->method('getDateTime')->willReturn('20250116120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getTopic')->willReturn('');
        $message->method('getFileText')->willReturn('');
        $message->method('getFile')->willReturn(0);

        $this->messageMetaRepository
            ->method('findOneBy')
            ->willReturn(null);

        $this->messageSorter
            ->expects($this->once())
            ->method('classify')
            ->willReturn([
                'topic' => 'CHAT',
                'language' => 'en',
                'model_id' => 5,
                'provider' => 'ollama',
                'model_name' => 'llama3'
            ]);

        $result = $this->service->classify($message);

        $this->assertEquals('CHAT', $result['topic']);
        $this->assertEquals('en', $result['language']);
        $this->assertEquals('ai_sorting', $result['source']);
        $this->assertFalse($result['skip_sorting']);
        $this->assertEquals(5, $result['model_id']);
    }

    public function testClassifyDetectsVidCommand(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(4);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturn('/vid create a video');
        $message->method('getLanguage')->willReturn('de');

        $this->messageMetaRepository->method('findOneBy')->willReturn(null);

        $result = $this->service->classify($message);

        $this->assertEquals('tools:vid', $result['topic']);
        $this->assertEquals('tool_command', $result['source']);
    }

    public function testClassifyWithModelOverride(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(5);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturn('Test');
        $message->method('getLanguage')->willReturn('en');

        $promptMeta = $this->createMock(MessageMeta::class);
        $promptMeta->method('getMetaValue')->willReturn('CHAT');

        $modelMeta = $this->createMock(MessageMeta::class);
        $modelMeta->method('getMetaValue')->willReturn('42');

        $this->messageMetaRepository
            ->method('findOneBy')
            ->willReturnCallback(function($criteria) use ($promptMeta, $modelMeta) {
                if ($criteria['metaKey'] === 'PROMPTID') {
                    return $promptMeta;
                } elseif ($criteria['metaKey'] === 'MODEL_ID') {
                    return $modelMeta;
                }
                return null;
            });

        $result = $this->service->classify($message);

        $this->assertEquals('CHAT', $result['topic']);
        $this->assertEquals(42, $result['model_id']);
        $this->assertEquals('prompt_override', $result['source']);
    }

    public function testClassifyLogsClassification(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(6);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturn('Test');
        $message->method('getLanguage')->willReturn('en');
        $message->method('getDateTime')->willReturn('20250116120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getTopic')->willReturn('');
        $message->method('getFileText')->willReturn('');
        $message->method('getFile')->willReturn(0);

        $this->messageMetaRepository->method('findOneBy')->willReturn(null);
        $this->messageSorter->method('classify')->willReturn([
            'topic' => 'CHAT',
            'language' => 'en'
        ]);

        $this->logger
            ->expects($this->atLeastOnce())
            ->method('info');

        $this->service->classify($message);
    }
}

