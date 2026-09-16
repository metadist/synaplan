<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SavedTask;

use App\Service\SavedTask\SavedTaskOutcomeNarrator;
use PHPUnit\Framework\TestCase;

final class SavedTaskOutcomeNarratorTest extends TestCase
{
    public function testDoesNotClaimNothingHappenedAfterALaterStepFails(): void
    {
        $message = (new SavedTaskOutcomeNarrator())->failureMessage(
            [
                'response' => [
                    'metadata' => [
                        'multitask' => [
                            'node_statuses' => [
                                'n1' => 'done',
                                'n2' => 'failed',
                            ],
                        ],
                    ],
                ],
            ],
            [
                ['capability' => 'email_me', 'nodeId' => 'n1', 'state' => 'done'],
                ['capability' => 'outbound_webhook', 'nodeId' => 'n2', 'state' => 'failed'],
            ],
            'The AI step could not complete. Nothing was sent or saved.',
        );

        self::assertSame('The email finished. Sending to the other system did not complete.', $message);
        self::assertStringNotContainsString('Nothing was sent or saved', $message);
    }

    public function testTwoStepsOfOneKindWithOneFailureCountAsNotComplete(): void
    {
        $message = (new SavedTaskOutcomeNarrator())->failureMessage(
            [],
            [
                ['capability' => 'save_to_folder', 'nodeId' => 'n1', 'state' => 'done'],
                ['capability' => 'email_me', 'nodeId' => 'n2', 'state' => 'done'],
                ['capability' => 'email_me', 'nodeId' => 'n3', 'state' => 'failed'],
            ],
        );

        self::assertSame('Saving the file finished. The email did not complete.', $message);
    }

    public function testGenericStopWhenNothingFinished(): void
    {
        $message = (new SavedTaskOutcomeNarrator())->failureMessage([], [], null);

        self::assertSame('This run stopped before anything was sent or saved.', $message);
    }
}
