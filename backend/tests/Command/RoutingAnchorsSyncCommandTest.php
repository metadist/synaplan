<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\AI\Service\AiFacade;
use App\Command\RoutingAnchorsSyncCommand;
use App\Service\Message\Capability\SystemCapabilityRegistry;
use App\Service\VectorSearch\QdrantClientInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * The anchors collection is what the embedding-router layer matches against,
 * so a half-finished sync must never leave it emptier than it found it: fresh
 * anchors are upserted FIRST and only the leftovers are pruned afterwards.
 * These tests lock that ordering, because the previous wipe-then-rebuild order
 * turned a single failed embedding call into permanently degraded routing.
 *
 * Every side effect the command can have is appended to `$calls` in order, so
 * the assertions can talk about sequence — the actual contract here — instead
 * of just about which methods were reached.
 */
final class RoutingAnchorsSyncCommandTest extends TestCase
{
    private AiFacade&Stub $aiFacade;
    private QdrantClientInterface&Stub $qdrant;

    /** @var list<array{op: string, point_id?: string, kept?: list<string>}> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->calls = [];
        $this->aiFacade = $this->createStub(AiFacade::class);
        $this->qdrant = $this->createStub(QdrantClientInterface::class);

        $this->qdrant->method('upsertRoutingAnchor')
            ->willReturnCallback(function (string $pointId): void {
                $this->calls[] = ['op' => 'upsert', 'point_id' => $pointId];
            });
        $this->qdrant->method('deleteRoutingAnchorsExcept')
            ->willReturnCallback(function (array $keepPointIds): int {
                $this->calls[] = ['op' => 'prune', 'kept' => $keepPointIds];

                return 2;
            });
        $this->qdrant->method('deleteAllRoutingAnchors')
            ->willReturnCallback(function (): int {
                $this->calls[] = ['op' => 'wipe'];

                return 0;
            });
    }

    public function testEveryAnchorIsUpsertedBeforeAnythingIsPruned(): void
    {
        $this->embedReturns(array_fill(0, 4, 0.5));

        $tester = $this->tester();
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());

        $expectedIds = $this->expectedPointIds();

        $anchorCount = count($expectedIds);
        $expectedOps = [];
        for ($i = 0; $i < $anchorCount; ++$i) {
            array_push($expectedOps, 'embed', 'upsert');
        }
        $expectedOps[] = 'prune';
        $this->assertSame($expectedOps, array_column($this->calls, 'op'), 'nothing may be pruned before the last upsert');

        $this->assertSame($expectedIds, array_column($this->calls, 'point_id'));
        $this->assertSame($expectedIds, end($this->calls)['kept']);
        $this->assertStringContainsString(sprintf('%d upserted, 2 pruned', $anchorCount), $tester->getDisplay());
    }

    public function testAFailedEmbeddingKeepsThePreviouslySyncedAnchorInsteadOfPruningIt(): void
    {
        $failing = $this->expectedPointIds()[1];
        $this->aiFacade->method('embed')->willReturnCallback(
            function (string $utterance) use ($failing): array {
                $this->calls[] = ['op' => 'embed'];
                if (self::pointIdOf($utterance) === $failing) {
                    throw new \RuntimeException('embedding provider down');
                }

                return ['embedding' => array_fill(0, 4, 0.5)];
            },
        );

        $tester = $this->tester();
        $tester->execute([]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());

        $pruneCall = end($this->calls);
        $this->assertSame('prune', $pruneCall['op']);
        $this->assertNotContains($failing, array_column($this->calls, 'point_id'), 'the failed anchor was not upserted');
        $this->assertContains($failing, $pruneCall['kept'], 'the failed anchor must survive the prune');
    }

    public function testAnEmptyVectorCountsAsAFailedEmbedding(): void
    {
        $this->embedReturns([]);

        $tester = $this->tester();
        $tester->execute([]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertSame(['prune'], array_values(array_diff(array_column($this->calls, 'op'), ['embed'])));
        $this->assertSame($this->expectedPointIds(), end($this->calls)['kept']);
    }

    public function testDryRunTouchesNeitherTheEmbeddingProviderNorQdrant(): void
    {
        $this->embedReturns(array_fill(0, 4, 0.5));

        $tester = $this->tester();
        $tester->execute(['--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertSame([], $this->calls, 'a dry run must not embed, upsert, prune or wipe');

        $display = $tester->getDisplay();
        $this->assertStringContainsString('Dry run', $display);
        foreach ($this->expectedPointIds() as $pointId) {
            $this->assertStringContainsString($pointId, $display);
        }
    }

    /**
     * @param list<float> $vector
     */
    private function embedReturns(array $vector): void
    {
        $this->aiFacade->method('embed')->willReturnCallback(function () use ($vector): array {
            $this->calls[] = ['op' => 'embed'];

            return ['embedding' => $vector];
        });
    }

    private function tester(): CommandTester
    {
        $application = new Application();
        $application->addCommand(new RoutingAnchorsSyncCommand(
            new SystemCapabilityRegistry(),
            $this->aiFacade,
            $this->qdrant,
            new LockFactory(new InMemoryStore()),
        ));

        return new CommandTester($application->find('app:routing:sync-anchors'));
    }

    /**
     * @return list<string>
     */
    private function expectedPointIds(): array
    {
        $ids = [];
        foreach ((new SystemCapabilityRegistry())->all() as $capability) {
            foreach ($capability->exampleUtterances as $utterance) {
                $ids[] = sprintf('route_%s_%s', $capability->topic, md5($utterance));
            }
        }

        return $ids;
    }

    private static function pointIdOf(string $utterance): string
    {
        foreach ((new SystemCapabilityRegistry())->all() as $capability) {
            if (in_array($utterance, $capability->exampleUtterances, true)) {
                return sprintf('route_%s_%s', $capability->topic, md5($utterance));
            }
        }

        return '';
    }
}
