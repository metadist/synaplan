<?php

declare(strict_types=1);

namespace App\Tests\Unit\UseCase;

use App\UseCase\ClassificationStepPlanner;
use App\UseCase\UseCaseMapper;
use PHPUnit\Framework\TestCase;

final class ClassificationStepPlannerTest extends TestCase
{
    private ClassificationStepPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new ClassificationStepPlanner(new UseCaseMapper());
    }

    public function testBuildsPlanFromSorterBsteps(): void
    {
        $plan = $this->planner->plan('any wording', [
            'topic' => 'mediamaker',
            'primary_use_case_id' => 'media_generation',
            'steps' => [
                ['id' => 'answer', 'capability' => 'CHAT', 'web_search' => true],
                ['id' => 'generate', 'capability' => 'TEXT2PIC'],
            ],
        ]);

        self::assertTrue($plan->isCompound);
        self::assertTrue($plan->isMultiStep());
        self::assertSame('text_chat', $plan->primaryUseCaseId);
        self::assertCount(2, $plan->steps);
        self::assertTrue($plan->steps[0]->webSearch);
        self::assertSame('TEXT2PIC', $plan->steps[1]->capability);
    }

    public function testDetectsPoemAndReadAloudCompoundPlan(): void
    {
        $plan = $this->planner->plan('Write a poem and read it aloud', [
            'topic' => 'general-chat',
            'granular_topic' => 'general',
            'steps' => [
                ['id' => 'write', 'capability' => 'CHAT'],
                ['id' => 'speak', 'capability' => 'TEXT2SOUND', 'input_from' => 'steps.write.output.text'],
            ],
        ]);

        self::assertTrue($plan->isCompound);
        self::assertSame('TEXT2SOUND', $plan->steps[1]->capability);
    }

    public function testDetectsGermanPoemAndReadAloudCompoundPlan(): void
    {
        $plan = $this->planner->plan('schreibe ein gedicht zum döner und lese es vor', [
            'topic' => 'general-chat',
            'steps' => [
                ['id' => 'write', 'capability' => 'CHAT'],
                ['id' => 'speak', 'capability' => 'TEXT2SOUND', 'input_from' => 'steps.write.output.text'],
            ],
        ]);

        self::assertTrue($plan->isCompound);
        self::assertSame('text_chat', $plan->primaryUseCaseId);
        self::assertSame('CHAT', $plan->steps[0]->capability);
        self::assertSame('TEXT2SOUND', $plan->steps[1]->capability);
        self::assertSame('steps.write.output.text', $plan->steps[1]->inputFrom);
    }

    public function testDetectsAnswerAndGenerateImageFromClassificationSignals(): void
    {
        $plan = $this->planner->plan('döner price and image', [
            'topic' => 'mediamaker',
            'intent' => 'image_generation',
            'web_search' => true,
            'media_type' => 'image',
        ]);

        self::assertTrue($plan->isCompound);
        self::assertTrue($plan->compoundStartsWithWebSearch());
        self::assertTrue($plan->steps[0]->webSearch);
        self::assertSame('TEXT2PIC', $plan->steps[1]->capability);
    }

    public function testInheritsWebSearchOnChatStepFromClassificationWhenBstepsOmitFlag(): void
    {
        $plan = $this->planner->plan('any wording', [
            'topic' => 'mediamaker',
            'web_search' => true,
            'steps' => [
                ['id' => 'answer', 'capability' => 'CHAT'],
                ['id' => 'generate', 'capability' => 'TEXT2PIC'],
            ],
        ]);

        self::assertTrue($plan->steps[0]->webSearch);
        self::assertTrue($plan->compoundStartsWithWebSearch());
    }

    public function testSingleStepWhenNoCompoundSignals(): void
    {
        $plan = $this->planner->plan('Draw a lion', [
            'topic' => 'mediamaker',
            'intent' => 'image_generation',
            'web_search' => false,
            'media_type' => 'image',
        ]);

        self::assertFalse($plan->isCompound);
        self::assertSame('TEXT2PIC', $plan->steps[0]->capability);
    }

    public function testUsesMediaTypeForSingleMediaGenerationStep(): void
    {
        $plan = $this->planner->plan('Speak this text', [
            'topic' => 'mediamaker',
            'primary_use_case_id' => 'media_generation',
            'media_type' => 'audio',
        ]);

        self::assertFalse($plan->isCompound);
        self::assertSame('TEXT2SOUND', $plan->steps[0]->capability);
    }
}
