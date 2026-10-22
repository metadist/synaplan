<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Messages\Tools\WebFetchBudget;
use App\AI\Messages\Tools\WebFetchTool;
use App\Entity\User;
use App\Service\Context\CondensedText;
use App\Service\Context\ContextCondenser;
use App\Service\Research\UrlContentResult;
use App\Service\Research\UrlContentService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WebFetchToolTest extends TestCase
{
    public function testBlockedPageIsAnErrorAndDoesNotLeakTheBody(): void
    {
        $pages = $this->createMock(UrlContentService::class);
        $pages->expects($this->once())
            ->method('fetchForReading')
            ->with('http://169.254.169.254/latest/meta-data')
            ->willReturn(new UrlContentResult(
                url: 'http://169.254.169.254/latest/meta-data',
                extractedText: 'secret-metadata-token',
                title: '',
                hostname: '169.254.169.254',
                success: false,
                error: 'URL points to a private/blocked address',
                blockedReason: 'blocked_address',
            ));

        $result = $this->tool($pages)->execute(
            ['url' => 'http://169.254.169.254/latest/meta-data'],
            $this->user(),
            new WebFetchBudget(3),
        );

        self::assertTrue($result['isError']);
        self::assertStringContainsString('private or internal network', $result['text']);
        self::assertStringNotContainsString('secret-metadata-token', $result['text']);
    }

    public function testAThrownReadBecomesAnErrorResultWithoutInternalDetails(): void
    {
        $pages = $this->createStub(UrlContentService::class);
        $pages->method('fetchForReading')->willThrowException(new \RuntimeException('Failed to connect to 10.0.0.7 port 443'));

        $result = $this->tool($pages)->execute(
            ['url' => 'https://example.com/slow'],
            $this->user(),
            new WebFetchBudget(3),
        );

        self::assertTrue($result['isError']);
        self::assertSame('The page could not be read.', $result['text']);
    }

    public function testATransportErrorFromTheReaderDoesNotReachTheModel(): void
    {
        $pages = $this->createStub(UrlContentService::class);
        $pages->method('fetchForReading')->willReturn(new UrlContentResult(
            url: 'https://example.com/a',
            extractedText: '',
            title: '',
            hostname: 'example.com',
            success: false,
            error: 'Could not resolve host: internal.corp.lan',
        ));

        $result = $this->tool($pages)->execute(['url' => 'https://example.com/a'], $this->user(), new WebFetchBudget(3));

        self::assertSame('The page could not be read.', $result['text']);
    }

    public function testAnHttpStatusIsNamed(): void
    {
        $pages = $this->createStub(UrlContentService::class);
        $pages->method('fetchForReading')->willReturn(new UrlContentResult(
            url: 'https://example.com/gone',
            extractedText: '',
            title: '',
            hostname: 'example.com',
            success: false,
            error: 'HTTP 404',
        ));

        $result = $this->tool($pages)->execute(['url' => 'https://example.com/gone'], $this->user(), new WebFetchBudget(3));

        self::assertStringContainsString('HTTP 404', $result['text']);
    }

    public function testTheReportedUrlHasNoQueryString(): void
    {
        $pages = $this->createStub(UrlContentService::class);
        $pages->method('fetchForReading')->willReturn($this->page('https://example.com/doc?token=s3cret', 'Body.'));

        $result = $this->tool($pages)->execute(
            ['url' => 'https://example.com/doc?token=s3cret'],
            $this->user(),
            new WebFetchBudget(3),
        );

        self::assertFalse($result['isError']);
        self::assertStringNotContainsString('s3cret', $result['url']);
    }

    public function testALongPageIsCondensedBeforeItReachesTheModel(): void
    {
        $long = str_repeat('paragraph ', 2000);
        $pages = $this->createStub(UrlContentService::class);
        $pages->method('fetchForReading')->willReturn(new UrlContentResult(
            url: 'https://example.com/long',
            extractedText: $long,
            title: 'A long page',
            hostname: 'example.com',
            success: true,
            finalUrl: 'https://example.com/long',
        ));

        $condenser = $this->createMock(ContextCondenser::class);
        $condenser->expects($this->once())
            ->method('fit')
            ->with($long, 'https://example.com/long', WebFetchTool::RESULT_CHAR_BUDGET, 5, null, true, false)
            ->willReturn(new CondensedText(
                'The condensed page.',
                CondensedText::STRATEGY_CONDENSED,
                mb_strlen($long),
                WebFetchTool::RESULT_CHAR_BUDGET,
            ));

        $result = $this->tool($pages, condenser: $condenser)->execute(
            ['url' => 'https://example.com/long'],
            $this->user(),
            new WebFetchBudget(3),
        );

        self::assertFalse($result['isError']);
        self::assertStringContainsString('The condensed page.', $result['text']);
        self::assertStringContainsString('https://example.com/long', $result['text']);
        self::assertStringNotContainsString($long, $result['text']);
    }

    public function testThePageLimitStopsASecondRead(): void
    {
        $pages = $this->createMock(UrlContentService::class);
        $pages->expects($this->once())
            ->method('fetchForReading')
            ->willReturn($this->page('https://example.com/one', 'First page.'));

        $tool = $this->tool($pages);
        $budget = new WebFetchBudget(1);

        $first = $tool->execute(['url' => 'https://example.com/one'], $this->user(), $budget);
        $second = $tool->execute(['url' => 'https://example.com/two'], $this->user(), $budget);

        self::assertFalse($first['isError']);
        self::assertTrue($second['isError']);
        self::assertSame(WebFetchTool::PAGE_LIMIT_MESSAGE, $second['text']);
    }

    public function testABadUrlDoesNotFetchAndDoesNotSpendTheBudget(): void
    {
        $pages = $this->createMock(UrlContentService::class);
        $pages->expects($this->once())
            ->method('fetchForReading')
            ->with('https://example.com/ok')
            ->willReturn($this->page('https://example.com/ok', 'Ok.'));

        $tool = $this->tool($pages);
        $budget = new WebFetchBudget(1);

        $bad = $tool->execute(['url' => 'file:///etc/passwd'], $this->user(), $budget);
        $good = $tool->execute(['url' => 'https://example.com/ok'], $this->user(), $budget);

        self::assertTrue($bad['isError']);
        self::assertStringContainsString('absolute http or https URL', $bad['text']);
        self::assertFalse($good['isError']);
    }

    public function testZeroMeansNoPageIsOpened(): void
    {
        $pages = $this->createMock(UrlContentService::class);
        $pages->expects($this->never())->method('fetchForReading');

        $result = $this->tool($pages)->execute(
            ['url' => 'https://example.com'],
            $this->user(),
            new WebFetchBudget(0),
        );

        self::assertTrue($result['isError']);
        self::assertSame(WebFetchTool::PAGE_LIMIT_MESSAGE, $result['text']);
    }

    private function tool(UrlContentService $pages, ?ContextCondenser $condenser = null): WebFetchTool
    {
        return new WebFetchTool(
            $pages,
            $condenser ?? $this->createStub(ContextCondenser::class),
            new NullLogger(),
        );
    }

    private function page(string $url, string $text): UrlContentResult
    {
        return new UrlContentResult(
            url: $url,
            extractedText: $text,
            title: 'Example',
            hostname: 'example.com',
            success: true,
            finalUrl: $url,
        );
    }

    private function user(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(5);

        return $user;
    }
}
