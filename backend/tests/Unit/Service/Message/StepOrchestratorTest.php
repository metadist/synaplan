<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\Entity\Message;
use App\Repository\ConfigRepository;
use App\Service\Message\InferenceRouter;
use App\Service\Message\StepOrchestrator;
use App\UseCase\PlannedStep;
use App\UseCase\StepPlan;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class StepOrchestratorTest extends TestCase
{
    public function testCompoundChatStepKeepsSearchResultsAndChatPrompt(): void
    {
        $message = (new Message())
            ->setUserId(1)
            ->setTrackingId(99)
            ->setText('Preis von Pferdefleisch und generiere ein Bild von Steak');

        $router = $this->createMock(InferenceRouter::class);
        $router->expects(self::exactly(2))
            ->method('routeStream')
            ->willReturnCallback(function (
                Message $msg,
                array $thread,
                array $classification,
                callable $stream,
                ?callable $status,
                array $options,
            ) use (&$callIndex): array {
                ++$callIndex;

                if (1 === $callIndex) {
                    self::assertArrayHasKey('search_results', $options);
                    self::assertArrayHasKey('resolved_prompt_data', $options);
                    self::assertTrue($options['orchestrator_pending_media']);
                    self::assertSame('general', $classification['topic']);
                }

                if (2 === $callIndex) {
                    self::assertArrayNotHasKey('search_results', $options);
                    self::assertTrue($options['orchestrator_media_step']);
                }

                $stream('ok');

                return ['metadata' => []];
            });

        $config = $this->createMock(ConfigRepository::class);
        $config->method('getValue')->willReturn('false');

        $orchestrator = new StepOrchestrator(
            $router,
            $config,
            $this->createMock(LoggerInterface::class),
        );

        $plan = new StepPlan('text_chat', [
            new PlannedStep('answer', 'config.routing.steps.chat', 'CHAT', null, true),
            new PlannedStep('generate', 'config.routing.steps.mediaGenerate', 'TEXT2PIC'),
        ], true);

        $classification = [
            'topic' => 'mediamaker',
            'language' => 'de',
            'web_search' => true,
            'intent' => 'image_generation',
            'media_type' => 'image',
        ];

        $options = [
            'resolved_prompt_data' => ['prompt' => null, 'metadata' => []],
            'search_results' => ['query' => 'Pferdefleisch Preis', 'results' => [['title' => 'Shop']]],
        ];

        $callIndex = 0;
        $orchestrator->executeStream(
            $message,
            [],
            $classification,
            $plan,
            static function (): void {},
            null,
            $options,
        );
    }

    public function testCompoundPoemStepPassesSanitizedTextToTts(): void
    {
        $poem = "Döner, du König der Gassen\nIm Flammenschein, das Fleisch so zart.";

        $message = (new Message())
            ->setUserId(1)
            ->setTrackingId(100)
            ->setText('schreibe ein gedicht zum döner und lese es vor');

        $router = $this->createMock(InferenceRouter::class);
        $router->expects(self::exactly(2))
            ->method('routeStream')
            ->willReturnCallback(function (
                Message $msg,
                array $thread,
                array $classification,
                callable $stream,
                ?callable $status,
                array $options,
            ) use ($poem, &$callIndex): array {
                ++$callIndex;

                if (1 === $callIndex) {
                    self::assertSame('general', $classification['topic']);
                    self::assertArrayNotHasKey('model_id', $classification);
                    $stream('Intro: ');
                    $stream(['type' => 'content', 'content' => $poem]);

                    return ['metadata' => ['response_text' => 'Intro: '.$poem]];
                }

                self::assertSame('audio', $classification['media_type']);
                self::assertArrayNotHasKey('model_id', $classification);
                self::assertSame('Intro: '.$poem, $options['step_prompt_text']);
                self::assertTrue($options['orchestrator_media_step']);

                return [
                    'metadata' => [
                        'file' => [
                            'path' => '/api/v1/files/uploads/1/test.mp3',
                            'type' => 'audio',
                        ],
                    ],
                ];
            });

        $config = $this->createMock(ConfigRepository::class);
        $config->method('getValue')->willReturn('false');

        $orchestrator = new StepOrchestrator(
            $router,
            $config,
            $this->createMock(LoggerInterface::class),
        );

        $plan = new StepPlan('text_chat', [
            new PlannedStep('write', 'config.routing.steps.chat', 'CHAT'),
            new PlannedStep('speak', 'config.routing.steps.readAloud', 'TEXT2SOUND', 'steps.write.output.text'),
        ], true);

        $callIndex = 0;
        $orchestrator->executeStream(
            $message,
            [],
            ['topic' => 'mediamaker', 'language' => 'de'],
            $plan,
            static function (): void {},
            null,
            [],
        );
    }
}
