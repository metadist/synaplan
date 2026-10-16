<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Message\DeleteComputeWorkspaceCommand;
use App\MessageHandler\DeleteComputeWorkspaceCommandHandler;
use App\Service\Compute\ComputeClient;
use App\Service\Compute\ComputeRefusedException;
use PHPUnit\Framework\TestCase;

/**
 * The orphaned folder of a deleted account: a busy sidecar throws so the
 * transport delivers the message again, and a folder that is already gone
 * counts as done.
 */
final class DeleteComputeWorkspaceCommandHandlerTest extends TestCase
{
    public function testABusyWorkspaceIsRetriedAndTheNextDeliveryDeletesIt(): void
    {
        $attempts = 0;
        $client = $this->createMock(ComputeClient::class);
        $client->expects(self::exactly(2))->method('deleteWorkspace')->with('01JLIVE')
            ->willReturnCallback(static function () use (&$attempts): void {
                if (1 === ++$attempts) {
                    throw new ComputeRefusedException('workspace_busy', 'A run is using this folder.', httpStatus: 409);
                }
            });
        $handler = new DeleteComputeWorkspaceCommandHandler($client);
        $command = new DeleteComputeWorkspaceCommand('01JLIVE');

        try {
            $handler($command);
            self::fail('A busy workspace must reach the transport, which retries it.');
        } catch (ComputeRefusedException $e) {
            self::assertSame('workspace_busy', $e->errorCode());
        }
        $handler($command);
    }

    public function testAFolderTheSidecarNoLongerKnowsIsAcknowledged(): void
    {
        $client = $this->createMock(ComputeClient::class);
        $client->expects(self::once())->method('deleteWorkspace')
            ->willThrowException(new ComputeRefusedException('workspace_not_found', 'No such workspace.', httpStatus: 404));

        (new DeleteComputeWorkspaceCommandHandler($client))(new DeleteComputeWorkspaceCommand('01JGONE'));
    }
}
