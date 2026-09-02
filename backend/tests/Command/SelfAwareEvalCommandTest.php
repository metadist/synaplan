<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SelfAwareEvalCommand;
use App\Service\Message\Handler\ChatHandler;
use App\Service\Message\MessageClassifier;
use App\Service\SelfAware\Eval\SelfAwareEvalCorpus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SelfAwareEvalCommandTest extends TestCase
{
    public function testRoutingOnlyWithCannedClassifier(): void
    {
        $classifier = $this->createMock(MessageClassifier::class);
        $classifier->method('classify')->willReturn([
            'topic' => 'synaplan',
            'language' => 'en',
            'source' => 'tool_command',
        ]);

        $command = new SelfAwareEvalCommand(
            $classifier,
            $this->createMock(ChatHandler::class),
            dirname(__DIR__, 2),
        );
        $tester = new CommandTester($command);
        $status = $tester->execute([
            '--only' => 'Q26',
            '--routing-only' => true,
            '--corpus' => 'tests/Eval/self_aware_eval_corpus.json',
        ]);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('passed=1', $tester->getDisplay());
    }

    public function testFailingTopicYieldsExitOne(): void
    {
        $classifier = $this->createMock(MessageClassifier::class);
        $classifier->method('classify')->willReturn([
            'topic' => 'general',
            'language' => 'en',
        ]);

        $command = new SelfAwareEvalCommand(
            $classifier,
            $this->createMock(ChatHandler::class),
            dirname(__DIR__, 2),
        );
        $tester = new CommandTester($command);
        $status = $tester->execute([
            '--only' => 'Q1',
            '--routing-only' => true,
        ]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('failed=1', $tester->getDisplay());
    }

    public function testCorpusFileIsValid(): void
    {
        $rows = SelfAwareEvalCorpus::load(dirname(__DIR__).'/Eval/self_aware_eval_corpus.json');
        $this->assertGreaterThanOrEqual(26, count($rows));
    }
}
