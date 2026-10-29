<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Processing;

use App\Entity\Message;
use App\Service\Message\Chat\FreshInfoRequest;
use App\Service\Message\Processing\FreshInfoRetry;
use App\Service\Message\Processing\TurnStatus;
use App\Service\Message\Processing\TurnWebSearch;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class FreshInfoRetryTest extends TestCase
{
    public function testAnOrdinaryAnswerPassesThroughUntouched(): void
    {
        $search = $this->createMock(TurnWebSearch::class);
        $search->expects(self::never())->method('searchRequested');
        $response = ['content' => 'Paris.', 'metadata' => ['provider' => 'openai']];

        [$final, $results] = (new FreshInfoRetry($search, new NullLogger()))->apply(
            $this->message(),
            ['topic' => 'general'],
            [FreshInfoRequest::OPTION => true],
            $response,
            $this->turnStatus(),
            static fn (array $options): array => self::fail('no second dispatch for an answer'),
        );

        self::assertSame($response, $final);
        self::assertNull($results);
    }

    public function testARequestSearchesWithTheModelsPhraseAndDispatchesOnceMoreWithoutTheOption(): void
    {
        $found = ['query' => 'current chancellor Germany', 'results' => [['title' => 'A', 'url' => 'https://a.test', 'description' => 'a']]];
        $search = $this->createMock(TurnWebSearch::class);
        $search->expects(self::once())->method('searchRequested')
            ->with(self::anything(), ['topic' => 'general'], self::anything(), self::anything(), 'current chancellor Germany')
            ->willReturn($found);
        $seen = null;

        [$final, $results] = (new FreshInfoRetry($search, new NullLogger()))->apply(
            $this->message(),
            ['topic' => 'general'],
            [FreshInfoRequest::OPTION => true, 'reasoning' => false],
            FreshInfoRequest::handlerResult('current chancellor Germany', [
                'provider' => 'openai',
                'model' => 'gpt-x',
                'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 12, 'total_tokens' => 1212],
                'facade_charged_cost' => '0.004000',
            ]),
            $this->turnStatus(),
            static function (array $options) use (&$seen): array {
                $seen = $options;

                return ['content' => 'Friedrich Merz [1].', 'metadata' => ['provider' => 'openai', 'model' => 'gpt-x', 'usage' => ['total_tokens' => 1500]]];
            },
        );

        self::assertIsArray($seen);
        self::assertArrayNotHasKey(FreshInfoRequest::OPTION, $seen, 'one request per turn');
        self::assertSame($found, $seen['search_results']);
        self::assertFalse($seen['reasoning'], 'the other options stay');
        self::assertSame($found, $results);
        self::assertSame('Friedrich Merz [1].', $final['content']);
        self::assertSame([
            'promptTokens' => 1200,
            'completionTokens' => 12,
            'totalTokens' => 1212,
            'cost' => '0.004000',
            'modelKey' => 'openai:gpt-x',
            'kind' => 'LLM',
            'priceKnown' => true,
        ], $final['metadata'][FreshInfoRetry::USAGE_KEY], 'the probing call counts on the turn');
    }

    public function testNothingFoundOrAFailedSearchStillAnswers(): void
    {
        $search = $this->createMock(TurnWebSearch::class);
        $search->method('searchRequested')->willThrowException(new \RuntimeException('provider down'));
        $seen = null;

        [$final, $results] = (new FreshInfoRetry($search, new NullLogger()))->apply(
            $this->message(),
            ['topic' => 'general'],
            [FreshInfoRequest::OPTION => true],
            FreshInfoRequest::handlerResult('a phrase', ['provider' => 'openai', 'model' => 'gpt-x', 'usage' => []]),
            $this->turnStatus(),
            static function (array $options) use (&$seen): array {
                $seen = $options;

                return ['content' => 'As far as I know …', 'metadata' => ['provider' => 'openai']];
            },
        );

        self::assertIsArray($seen);
        self::assertArrayNotHasKey('search_results', $seen);
        self::assertArrayNotHasKey(FreshInfoRequest::OPTION, $seen);
        self::assertNull($results);
        self::assertSame('As far as I know …', $final['content']);
        self::assertArrayNotHasKey(FreshInfoRetry::USAGE_KEY, $final['metadata'], 'a call without tokens adds no usage line');
    }

    private function turnStatus(): TurnStatus
    {
        return new TurnStatus(null, new NullLogger());
    }

    private function message(): Message
    {
        return (new Message())->setUserId(4)->setText('Wer ist Bundeskanzler?');
    }
}
