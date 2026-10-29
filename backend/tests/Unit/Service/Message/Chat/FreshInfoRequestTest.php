<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Chat;

use App\Service\Message\Chat\FreshInfoGuard;
use App\Service\Message\Chat\FreshInfoRequest;
use PHPUnit\Framework\TestCase;

final class FreshInfoRequestTest extends TestCase
{
    public function testTheWholeReplyAsMarkerIsARequest(): void
    {
        self::assertSame('aktueller CEO Siemens 2026', FreshInfoRequest::parse('[[SEARCH_WEB: aktueller CEO Siemens 2026]]'));
        self::assertSame('latest Node.js LTS', FreshInfoRequest::parse("  [[SEARCH_WEB: \"latest Node.js LTS\"]]\n"));
        self::assertSame('who won', FreshInfoRequest::parse('<think>I do not know this.</think>[[SEARCH_WEB: who won]]'));
    }

    public function testAnAnswerIsNotARequest(): void
    {
        self::assertNull(FreshInfoRequest::parse(null));
        self::assertNull(FreshInfoRequest::parse('The capital of France is Paris.'));
        self::assertNull(FreshInfoRequest::parse('Let me check: [[SEARCH_WEB: x]]'));
        self::assertNull(FreshInfoRequest::parse('[[SEARCH_WEB: x]] and here is my guess anyway'));
        self::assertNull(FreshInfoRequest::parse('[[SEARCH_WEB: ]]'));
        self::assertNull(FreshInfoRequest::parse('[[SEARCH_WEB: unterminated'));
    }

    public function testTheHeadOfAStreamIsHeldOnlyWhileItCouldBeTheMarker(): void
    {
        self::assertTrue(FreshInfoRequest::couldBecomeMarker(''));
        self::assertTrue(FreshInfoRequest::couldBecomeMarker('  ['));
        self::assertTrue(FreshInfoRequest::couldBecomeMarker('[[SEARCH'));
        self::assertTrue(FreshInfoRequest::couldBecomeMarker('[[SEARCH_WEB: la'));
        self::assertFalse(FreshInfoRequest::couldBecomeMarker('The'));
        self::assertFalse(FreshInfoRequest::couldBecomeMarker('[1] a cited answer'));
    }

    public function testTheGuardForwardsAnOrdinaryAnswerWhole(): void
    {
        $forwarded = [];
        $guard = new FreshInfoGuard(static function (string|array $chunk) use (&$forwarded): void {
            $forwarded[] = $chunk;
        });

        $guard(['type' => 'reasoning', 'content' => 'thinking']);
        $guard(['type' => 'content', 'content' => 'The']);
        $guard(['type' => 'content', 'content' => ' answer.']);
        $guard(['type' => 'finish', 'finish_reason' => 'stop']);
        $guard->finish();

        self::assertSame([
            ['type' => 'reasoning', 'content' => 'thinking'],
            ['type' => 'content', 'content' => 'The'],
            ['type' => 'content', 'content' => ' answer.'],
            ['type' => 'finish', 'finish_reason' => 'stop'],
        ], $forwarded);
        self::assertNull($guard->requestedQuery());
    }

    public function testTheGuardHoldsABracketUntilItIsKnownToBeAnAnswer(): void
    {
        $forwarded = [];
        $guard = new FreshInfoGuard(static function (string|array $chunk) use (&$forwarded): void {
            $forwarded[] = $chunk;
        });

        $guard('[[');
        self::assertSame([], $forwarded, 'two brackets may still become the marker');
        $guard('SEARCH_WEB');
        self::assertSame([], $forwarded);
        $guard(' is not what I write]] but a list');
        self::assertSame(['[[SEARCH_WEB is not what I write]] but a list'], $forwarded, 'released as one chunk once it diverged');
        $guard(' continues');
        self::assertSame(['[[SEARCH_WEB is not what I write]] but a list', ' continues'], $forwarded);
        $guard->finish();
        self::assertNull($guard->requestedQuery());
    }

    public function testTheGuardSwallowsARequestAndReadsTheQuery(): void
    {
        $forwarded = [];
        $guard = new FreshInfoGuard(static function (string|array $chunk) use (&$forwarded): void {
            $forwarded[] = $chunk;
        });

        $guard(['type' => 'content', 'content' => '[[SEARCH_WEB: ']);
        $guard(['type' => 'content', 'content' => 'Bundeskanzler Deutschland aktuell']);
        $guard(['type' => 'content', 'content' => ']]']);
        $guard(['type' => 'finish', 'finish_reason' => 'stop']);
        $guard->finish();

        self::assertSame([['type' => 'finish', 'finish_reason' => 'stop']], $forwarded, 'the person never sees the marker');
        self::assertSame('Bundeskanzler Deutschland aktuell', $guard->requestedQuery());
    }

    public function testAHeldAnswerThatEndsEarlyIsStillDelivered(): void
    {
        $forwarded = [];
        $guard = new FreshInfoGuard(static function (string|array $chunk) use (&$forwarded): void {
            $forwarded[] = $chunk;
        });

        $guard('[');
        $guard->finish();

        self::assertSame(['['], $forwarded);
        self::assertNull($guard->requestedQuery());
    }

    public function testTheHandlerResultCarriesTheQueryAndTheProbingCallsMetadata(): void
    {
        $result = FreshInfoRequest::handlerResult('a query', ['provider' => 'openai', 'usage' => ['total_tokens' => 7]]);

        self::assertSame('', $result['content']);
        self::assertSame('openai', $result['metadata']['provider']);
        self::assertSame('a query', FreshInfoRequest::fromHandlerResult($result));
        self::assertNull(FreshInfoRequest::fromHandlerResult(['content' => 'an answer', 'metadata' => []]));
    }
}
