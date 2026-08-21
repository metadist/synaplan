<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Email;

use App\Entity\Connection;
use App\Service\Email\GraphMailboxSearcher;
use App\Service\Microsoft\GraphClient;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * M365 mail search contract (Phase M steps M3a/M3b): same hit shape as the
 * IMAP searcher, full body for the TOP hit only, per-hit failures degrade to
 * the preview instead of killing the search.
 */
final class GraphMailboxSearcherTest extends TestCase
{
    // Property (not param) on purpose: mocking the final GraphClient relies on
    // dg/bypass-finals; the property variant is allowlisted in phpstan.neon.
    private GraphClient&MockObject $graph;

    protected function setUp(): void
    {
        $this->graph = $this->createMock(GraphClient::class);
    }

    private function connection(): Connection
    {
        return new Connection(7, Connection::TYPE_M365, 'Work M365');
    }

    private function searcher(): GraphMailboxSearcher
    {
        return new GraphMailboxSearcher($this->graph, new NullLogger());
    }

    /**
     * @return array{id: string, subject: string, from: string, receivedAt: string, preview: string, hasAttachments: bool, isRead: bool}
     */
    private function message(string $id, string $receivedAt, string $preview): array
    {
        return [
            'id' => $id,
            'subject' => 'FPSenergy update',
            'from' => 'oliver@fps.test',
            'receivedAt' => $receivedAt,
            'preview' => $preview,
            'hasAttachments' => false,
            'isRead' => true,
        ];
    }

    public function testTopHitCarriesTheFullBodyOthersKeepThePreview(): void
    {
        $this->graph->method('searchMessages')->willReturn([
            $this->message('newest', '2026-08-15T09:00:00Z', 'short preview…'),
            $this->message('older', '2026-08-01T09:00:00Z', 'older preview'),
        ]);
        $this->graph->expects(self::once())
            ->method('messageBody')
            ->with(self::anything(), 'newest')
            ->willReturn(['subject' => 's', 'from' => 'f', 'receivedAt' => 'r', 'body' => 'THE FULL BODY with all the numbers.']);

        $hits = $this->searcher()->search($this->connection(), 'FPSenergy', 'Oliver Braun');

        self::assertCount(2, $hits);
        self::assertSame('THE FULL BODY with all the numbers.', $hits[0]['snippet']);
        self::assertSame('older preview', $hits[1]['snippet']);
        self::assertSame(['from', 'subject', 'date', 'snippet'], array_keys($hits[0]), 'same hit contract as the IMAP searcher');
        self::assertSame('2026-08-15T09:00:00Z', $hits[0]['date']);
    }

    public function testFailedBodyFetchDegradesToThePreviewNotTheSearch(): void
    {
        $this->graph->method('searchMessages')->willReturn([
            $this->message('newest', '2026-08-15T09:00:00Z', 'the preview survives'),
        ]);
        $this->graph->method('messageBody')->willThrowException(new \RuntimeException('throttled'));

        $hits = $this->searcher()->search($this->connection(), 'FPSenergy');

        self::assertCount(1, $hits);
        self::assertSame('the preview survives', $hits[0]['snippet']);
    }

    public function testLongBodiesAreCappedForTokenControl(): void
    {
        $this->graph->method('searchMessages')->willReturn([
            $this->message('newest', '2026-08-15T09:00:00Z', 'p'),
        ]);
        $this->graph->method('messageBody')->willReturn([
            'subject' => 's', 'from' => 'f', 'receivedAt' => 'r',
            'body' => str_repeat('x', 5000),
        ]);

        $hits = $this->searcher()->search($this->connection(), 'q');

        self::assertSame(2000, mb_strlen($hits[0]['snippet']));
    }

    public function testEmptyResultStaysEmptyWithoutBodyFetch(): void
    {
        $this->graph->method('searchMessages')->willReturn([]);
        $this->graph->expects(self::never())->method('messageBody');

        self::assertSame([], $this->searcher()->search($this->connection(), 'nothing'));
    }
}
