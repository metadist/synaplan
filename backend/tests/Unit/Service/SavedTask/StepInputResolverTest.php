<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SavedTask;

use App\Entity\Message;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\SavedTask\Graph\StepInputResolver;
use PHPUnit\Framework\TestCase;

final class StepInputResolverTest extends TestCase
{
    public function testResolvesLiteralFromEarlierStepAndTrigger(): void
    {
        $message = new Message();
        $message->setUserId(1);
        $context = new NodeContext($message, [], 1, ['language' => 'en'], [
            'trigger_payload' => ['body' => ['title' => 'Hello']],
        ]);
        $context->setResult('step_1', NodeResult::ok('Inbox summary', [], ['summary' => 'Inbox summary']));

        $resolver = new StepInputResolver();
        $resolved = $resolver->resolveAll([
            'name' => ['literal' => 'Ada'],
            'text' => ['from' => 'step_1', 'field' => 'text'],
            'title' => ['from' => 'trigger', 'field' => 'body.title'],
        ], $context);

        self::assertSame('Ada', $resolved['name']);
        self::assertSame('Inbox summary', $resolved['text']);
        self::assertSame('Hello', $resolved['title']);
    }

    public function testEmptyFieldMeansTheWholeEventOrTheStepText(): void
    {
        $message = new Message();
        $message->setUserId(1);
        $payload = ['title' => 'Hello', 'count' => 2];
        $context = new NodeContext($message, [], 1, ['language' => 'en'], ['trigger_payload' => $payload]);
        $context->setResult('step_1', NodeResult::ok('Inbox summary'));

        $resolver = new StepInputResolver();

        self::assertSame($payload, $resolver->resolve(['from' => 'trigger'], $context));
        self::assertSame($payload, $resolver->resolve(['from' => 'trigger', 'field' => ''], $context));
        self::assertSame('Inbox summary', $resolver->resolve(['from' => 'step_1', 'field' => ''], $context));
        self::assertNull($resolver->resolve(['from' => 'trigger', 'field' => 'missing'], $context));
    }
}
