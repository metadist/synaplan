<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\Service\Message\StepOrchestrator;
use App\UseCase\PlannedStep;
use App\UseCase\StepPlan;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class StepOrchestratorTest extends TestCase
{
    private StepOrchestrator $orchestrator;

    protected function setUp(): void
    {
        $this->orchestrator = new StepOrchestrator(
            $this->createMock(MessageBusInterface::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testBuildPlanSingleStep(): void
    {
        $classification = [
            'topic' => 'general',
            'source' => 'synapse_embedding',
        ];

        $plan = $this->orchestrator->buildPlan($classification);

        $this->assertFalse($plan->isCompound());
        $this->assertEquals(1, $plan->stepCount());
        $this->assertEquals('CHAT', $plan->firstStep()->capability);
    }

    public function testBuildPlanCompoundFromRouter(): void
    {
        $classification = [
            'topic' => 'general',
            'source' => 'synapse_external_router',
            'classification_source' => 'setfit',
            'classification_confidence' => 0.91,
            'router_steps' => [
                ['id' => 'step_1', 'capability' => 'CHAT', 'web_search' => true],
                ['id' => 'step_2', 'capability' => 'IMAGE_GENERATION', 'media_type' => 'image'],
            ],
        ];

        $plan = $this->orchestrator->buildPlan($classification);

        $this->assertTrue($plan->isCompound());
        $this->assertEquals(2, $plan->stepCount());
        $this->assertEquals('CHAT', $plan->steps[0]->capability);
        $this->assertTrue($plan->steps[0]->webSearch);
        $this->assertEquals('IMAGE_GENERATION', $plan->steps[1]->capability);
        $this->assertEquals('image', $plan->steps[1]->mediaType);
        $this->assertEquals('setfit', $plan->source);
        $this->assertEquals(0.91, $plan->confidence);
    }

    public function testBuildPlanMediamaker(): void
    {
        $classification = [
            'topic' => 'mediamaker',
            'source' => 'synapse_embedding',
        ];

        $plan = $this->orchestrator->buildPlan($classification);

        $this->assertFalse($plan->isCompound());
        $this->assertEquals('IMAGE_GENERATION', $plan->firstStep()->capability);
    }

    public function testRequiresOrchestration(): void
    {
        $singlePlan = StepPlan::single('CHAT');
        $this->assertFalse($this->orchestrator->requiresOrchestration($singlePlan));

        $compoundPlan = new StepPlan(
            steps: [
                new PlannedStep('step_1', 'CHAT', webSearch: true),
                new PlannedStep('step_2', 'IMAGE_GENERATION', mediaType: 'image'),
            ],
            source: 'setfit',
            confidence: 0.89,
        );
        $this->assertTrue($this->orchestrator->requiresOrchestration($compoundPlan));
    }

    public function testPrepareStepContext(): void
    {
        $messageData = ['BTEXT' => 'Generate an image of a sunset'];
        $step = new PlannedStep('step_2', 'IMAGE_GENERATION', mediaType: 'image');

        $context = $this->orchestrator->prepareStepContext($messageData, $step, 'Previous step output');

        $this->assertEquals('Generate an image of a sunset', $context['BTEXT']);
        $this->assertEquals('step_2', $context['_step_id']);
        $this->assertEquals('IMAGE_GENERATION', $context['_step_capability']);
        $this->assertEquals('mediamaker', $context['_step_topic']);
        $this->assertEquals('image', $context['_step_media_type']);
        $this->assertEquals('Previous step output', $context['_step_previous_output']);
    }
}
