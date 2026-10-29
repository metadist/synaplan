<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Processing;

use App\Entity\Message;
use App\Plug\WebSearch\WebSearchGateway;
use App\Service\Message\AttachmentSearchContextResolver;
use App\Service\Message\Processing\LinkedContentReader;
use App\Service\Message\Processing\TurnStatus;
use App\Service\Message\Processing\TurnWebSearch;
use App\Service\Message\SearchQueryGenerator;
use App\Service\Research\UrlContentService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * When no search ran, the answering model may still ask for one — but only
 * where a search would have been allowed in the first place.
 */
final class TurnWebSearchRequestTest extends TestCase
{
    public function testAGeneralQuestionWithoutASearchLeavesTheDoorOpen(): void
    {
        $result = $this->turn($this->createConfiguredMock(WebSearchGateway::class, ['isEnabled' => true]))->run($this->message(), ['topic' => 'general', 'web_search' => false], [], [], [], $this->turnStatus());

        self::assertNull($result['searchResults']);
        self::assertTrue($result['searchOnRequest']);
    }

    public function testNoRequestWhenTheSearchProviderIsOff(): void
    {
        $result = $this->turn($this->createConfiguredMock(WebSearchGateway::class, ['isEnabled' => false]))->run($this->message(), ['topic' => 'general'], [], [], [], $this->turnStatus());

        self::assertFalse($result['searchOnRequest']);
    }

    public function testNoRequestWhenThePromptOptedOutOfTheInternet(): void
    {
        $result = $this->turn($this->createConfiguredMock(WebSearchGateway::class, ['isEnabled' => true]))->run($this->message(), ['topic' => 'general'], ['tool_internet' => false], [], [], $this->turnStatus());

        self::assertFalse($result['searchOnRequest']);
    }

    public function testNoRequestOnAWidgetTurnOrAMediaTopic(): void
    {
        self::assertFalse($this->turn($this->createConfiguredMock(WebSearchGateway::class, ['isEnabled' => true]))->run($this->message(), ['topic' => 'general'], [], ['is_widget_mode' => true], [], $this->turnStatus())['searchOnRequest']);
        self::assertFalse($this->turn($this->createConfiguredMock(WebSearchGateway::class, ['isEnabled' => true]))->run($this->message(), ['topic' => 'mediamaker'], [], [], [], $this->turnStatus())['searchOnRequest']);
    }

    public function testNoRequestWhenTheTurnAlreadySearched(): void
    {
        $gateway = $this->createConfiguredMock(WebSearchGateway::class, ['isEnabled' => true]);
        $gateway->method('search')->willReturn(['results' => [['title' => 'A', 'url' => 'https://a.test', 'description' => 'a']]]);
        $queries = $this->createStub(SearchQueryGenerator::class);
        $queries->method('generate')->willReturn('a query');

        $result = $this->turn($gateway, $queries)->run($this->message(), ['topic' => 'general', 'web_search' => true], [], [], [], $this->turnStatus());

        self::assertNotNull($result['searchResults']);
        self::assertFalse($result['searchOnRequest']);
    }

    public function testTheRequestedSearchUsesTheModelsPhraseAndSaysWhoAsked(): void
    {
        $gateway = $this->createConfiguredMock(WebSearchGateway::class, ['isEnabled' => true]);
        $gateway->expects(self::once())->method('search')
            ->with('Bundeskanzler Deutschland aktuell', self::anything(), 4)
            ->willReturn(['results' => [['title' => 'Kanzler', 'url' => 'https://k.test', 'description' => 'Der Kanzler']]]);
        $queries = $this->createMock(SearchQueryGenerator::class);
        $queries->expects(self::never())->method('generate');
        $events = [];
        $status = new TurnStatus(static function (array $event) use (&$events): void {
            $events[] = [$event['status'], $event['metadata']];
        }, new NullLogger());

        $results = $this->turn($gateway, $queries)->searchRequested($this->message(), ['topic' => 'general', 'language' => 'de'], [], $status, 'Bundeskanzler Deutschland aktuell');

        self::assertNotNull($results);
        self::assertCount(1, $results['results']);
        self::assertSame(['searching', ['reason' => TurnWebSearch::REASON_MODEL_REQUESTED]], $events[0]);
        self::assertSame('search_complete', $events[1][0]);
        self::assertSame('Bundeskanzler Deutschland aktuell', $events[1][1]['query']);
    }

    public function testTheRequestedSearchReturnsNullWhenNothingWasFound(): void
    {
        $gateway = $this->createConfiguredMock(WebSearchGateway::class, ['isEnabled' => true]);
        $gateway->method('search')->willReturn(['results' => []]);

        self::assertNull($this->turn($gateway)->searchRequested($this->message(), ['topic' => 'general'], [], $this->turnStatus(), 'nothing'));
    }

    private function turn(WebSearchGateway $webSearch, ?SearchQueryGenerator $queries = null): TurnWebSearch
    {
        $urls = $this->createStub(UrlContentService::class);
        $urls->method('extractUrls')->willReturn([]);

        return new TurnWebSearch(
            $webSearch,
            $queries ?? $this->createStub(SearchQueryGenerator::class),
            $this->createStub(AttachmentSearchContextResolver::class),
            new LinkedContentReader($urls, new NullLogger()),
            new NullLogger(),
        );
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
