<?php

declare(strict_types=1);

namespace App\Tests\Unit\UseCase;

use App\UseCase\RuleBasedStepPlanner;
use App\UseCase\UseCaseMapper;
use PHPUnit\Framework\TestCase;

final class RuleBasedStepPlannerTest extends TestCase
{
    private RuleBasedStepPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new RuleBasedStepPlanner(new UseCaseMapper());
    }

    public function testDetectsPoemAndReadAloudCompoundPlan(): void
    {
        $plan = $this->planner->plan('Write a poem and read it aloud', [
            'topic' => 'general-chat',
            'granular_topic' => 'general',
        ]);

        self::assertTrue($plan->isCompound);
        self::assertTrue($plan->isMultiStep());
        self::assertSame('text_chat', $plan->primaryUseCaseId);
        self::assertCount(2, $plan->steps);
        self::assertSame('CHAT', $plan->steps[0]->capability);
        self::assertSame('TEXT2SOUND', $plan->steps[1]->capability);
        self::assertSame('steps.write.output.text', $plan->steps[1]->inputFrom);
    }

    public function testUsesVideoCapabilityForVideoQuery(): void
    {
        $plan = $this->planner->plan('Create a short video clip', [
            'topic' => 'video-generation',
            'granular_topic' => 'mediamaker',
            'primary_use_case_id' => 'media_generation',
        ]);

        self::assertFalse($plan->isCompound);
        self::assertSame('TEXT2VID', $plan->steps[0]->capability);
    }

    public function testSingleStepForSimpleChat(): void
    {
        $plan = $this->planner->plan('How do I write a for-loop in PHP?', [
            'topic' => 'coding',
        ]);

        self::assertFalse($plan->isMultiStep());
        self::assertCount(1, $plan->steps);
        self::assertSame('CHAT', $plan->steps[0]->capability);
    }
}
