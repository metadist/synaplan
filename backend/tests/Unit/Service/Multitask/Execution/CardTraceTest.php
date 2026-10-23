<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution;

use App\Service\Multitask\Execution\CardTrace;
use App\Service\Multitask\Execution\NodeResult;
use PHPUnit\Framework\TestCase;

final class CardTraceTest extends TestCase
{
    public function testAStepResultCarriesItsTraceAndFolderReadButNotTheQuestion(): void
    {
        $fields = CardTrace::fromResult(NodeResult::ok('text', [], [
            'step_input' => 'in',
            'step_output' => '',
            'step_output_truncated' => true,
            'duration_ms' => 12,
            'folder_read' => ['outcome' => 'read'],
            'ask_user' => ['question' => 'Which?'],
            'provider' => 'test',
        ]));

        self::assertSame([
            'step_input' => 'in',
            'step_output_truncated' => true,
            'duration_ms' => 12,
            'folder_read' => ['outcome' => 'read'],
        ], $fields);
    }

    public function testAnEventOrCardKeepsTheQuestionAndDropsWrongTypes(): void
    {
        $fields = CardTrace::fromArray([
            'step_input' => 7,
            'step_output_truncated' => 'yes',
            'duration_ms' => '12',
            'ask_user' => ['question' => 'Which?'],
            'folder_read' => 'read',
        ]);

        self::assertSame(['ask_user' => ['question' => 'Which?']], $fields);
    }
}
