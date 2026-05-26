<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Message\ProcessStepCommand;
use App\MessageHandler\ProcessStepCommandHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProcessStepCommandHandlerTest extends TestCase
{
    public function testHandlesStepCommand(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('info');

        $handler = new ProcessStepCommandHandler($logger);

        $command = new ProcessStepCommand(
            conversationId: 42,
            originalMsgId: 100,
            userId: 1,
            stepIndex: 1,
            stepData: [
                'id' => 'step_2',
                'capability' => 'IMAGE_GENERATION',
                'web_search' => false,
                'media_type' => 'image',
                'metadata' => [],
            ],
            previousOutput: 'Previous step generated text about cats',
        );

        $handler($command);

        $this->assertSame(42, $command->getConversationId());
        $this->assertSame(100, $command->getOriginalMsgId());
        $this->assertSame(1, $command->getUserId());
        $this->assertSame(1, $command->getStepIndex());
        $this->assertSame('IMAGE_GENERATION', $command->getStepData()['capability']);
        $this->assertSame('Previous step generated text about cats', $command->getPreviousOutput());
    }

    public function testHandlesChatStep(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $handler = new ProcessStepCommandHandler($logger);

        $command = new ProcessStepCommand(
            conversationId: 10,
            originalMsgId: 50,
            userId: 2,
            stepIndex: 2,
            stepData: [
                'id' => 'step_3',
                'capability' => 'CHAT',
                'web_search' => true,
                'metadata' => [],
            ],
        );

        $handler($command);

        $this->assertSame('', $command->getPreviousOutput());
    }
}
