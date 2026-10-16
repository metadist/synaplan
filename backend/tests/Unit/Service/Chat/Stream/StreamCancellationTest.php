<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Stream;

use App\Service\Chat\Stream\StreamFailureReporter;
use App\Service\Chat\Stream\StreamMessageMeta;
use App\Service\Message\ChatErrorNotifier;
use App\Service\Message\ChatErrorPresenter;
use App\Service\ModelConfigService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Cancelling a multitask/media turn wrote TWO outgoing messages for one Stop
 * click: the CHAT row from /save-cancelled plus an ERROR row, because the
 * cancellation came back as a failed processing result instead of an
 * exception. The controller now recognises a cancelled result and ends the
 * turn silently.
 */
class StreamCancellationTest extends TestCase
{
    private StreamFailureReporter $failures;

    protected function setUp(): void
    {
        $this->failures = new StreamFailureReporter(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(ChatErrorPresenter::class),
            $this->createStub(ChatErrorNotifier::class),
            $this->createStub(AuthorizationCheckerInterface::class),
            $this->createStub(ModelConfigService::class),
            new StreamMessageMeta(new NullLogger()),
            new NullLogger(),
        );
    }

    public function testUserCancellationIsNotTreatedAsError(): void
    {
        $this->assertTrue($this->isCancelledResult([
            'success' => false,
            'cancelled' => true,
            'error' => 'Stream cancelled by user',
        ]));
    }

    public function testProviderFailureStillProducesAnErrorMessage(): void
    {
        $this->assertFalse($this->isCancelledResult([
            'success' => false,
            'error' => 'AI provider failed for streaming',
        ]));
    }

    public function testSuccessfulTurnIsNeverACancellation(): void
    {
        $this->assertFalse($this->isCancelledResult(['success' => true, 'cancelled' => true]));
    }

    /**
     * The DAG path used to report a cancel as an ordinary node failure, whose
     * message was then persisted as a second, raw-English card (#1501). The
     * marker is fixed at the source; this is the net that keeps a regression
     * from reaching the chat.
     */
    public function testDagFailureThatIsReallyACancellationIsRecognised(): void
    {
        $this->assertTrue($this->isCancelledResult([
            'success' => false,
            'error' => 'chat failed: Stream cancelled by user',
        ]));
    }

    public function testUnrelatedFailureMentioningTheUserIsStillAnError(): void
    {
        $this->assertFalse($this->isCancelledResult([
            'success' => false,
            'error' => 'chat failed: no input text for user prompt',
        ]));
    }

    /**
     * @param array<string, mixed> $result
     */
    private function isCancelledResult(array $result): bool
    {
        return $this->failures->isCancellation($result);
    }
}
