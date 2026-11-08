<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Media;

use App\Service\Media\MediaDependentPlanContinuation;
use App\Service\Multitask\Plan\TaskPlan;
use PHPUnit\Framework\TestCase;

final class MediaDependentPlanContinuationTest extends TestCase
{
    public function testAProcessingStepThatReadsTheRenderNeedsContinuation(): void
    {
        $plan = TaskPlan::fromArray([
            'version' => 1,
            'language' => 'en',
            'reply_node' => 'n3',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'image_generation', 'inputs' => ['prompt' => 'a cat']],
                ['id' => 'n2', 'capability' => 'file_analysis', 'depends_on' => ['n1'], 'inputs' => ['file' => '$n1.file']],
                ['id' => 'n3', 'capability' => 'compose_reply', 'depends_on' => ['n1', 'n2']],
            ],
        ]);

        self::assertTrue(MediaDependentPlanContinuation::stepNeedsThisRender($plan, 'n1'));
    }

    public function testDeliveryAloneDoesNotContinueThePlan(): void
    {
        $plan = TaskPlan::fromArray([
            'version' => 1,
            'language' => 'en',
            'reply_node' => 'n2',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'image_generation', 'inputs' => ['prompt' => 'a cat']],
                ['id' => 'n2', 'capability' => 'compose_reply', 'depends_on' => ['n1']],
            ],
        ]);

        self::assertFalse(MediaDependentPlanContinuation::stepNeedsThisRender($plan, 'n1'));
    }
}
