<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Messages\Tools\WebFetchTool;
use App\Entity\User;
use App\Service\Context\CondensedText;
use App\Service\Context\ContextCondenser;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\Research\UrlContentResult;
use App\Service\Research\UrlContentService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

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
                error: 'Blocked: link-local address',
                blockedReason: 'ssrf',
            ));

        $result = $this->tool($pages, max: 3)->execute(
            ['url' => 'http://169.254.169.254/latest/meta-data'],
            $this->user(),
        );

        self::assertTrue($result['isError']);
        self::assertStringContainsString('Blocked: link-local address', $result['text']);
        self::assertStringNotContainsString('secret-metadata-token', $result['text']);
    }

    public function testAThrownReadBecomesAnErrorResult(): void
    {
        $pages = $this->createStub(UrlContentService::class);
        $pages->method('fetchForReading')->willThrowException(new \RuntimeException('timed out'));

        $result = $this->tool($pages, max: 3)->execute(
            ['url' => 'https://example.com/slow'],
            $this->user(),
        );

        self::assertTrue($result['isError']);
        self::assertStringContainsString('timed out', $result['text']);
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

        $result = $this->tool($pages, max: 3, condenser: $condenser)->execute(
            ['url' => 'https://example.com/long'],
            $this->user(),
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

        $stack = new RequestStack();
        $stack->push(new Request());
        $tool = $this->tool($pages, max: 1, requests: $stack);

        $first = $tool->execute(['url' => 'https://example.com/one'], $this->user());
        $second = $tool->execute(['url' => 'https://example.com/two'], $this->user());

        self::assertFalse($first['isError']);
        self::assertTrue($second['isError']);
        self::assertStringContainsString(WebFetchTool::PAGE_LIMIT_MESSAGE, $second['text']);
    }

    public function testABadUrlDoesNotFetchAndDoesNotSpendTheBudget(): void
    {
        $pages = $this->createMock(UrlContentService::class);
        $pages->expects($this->once())
            ->method('fetchForReading')
            ->with('https://example.com/ok')
            ->willReturn($this->page('https://example.com/ok', 'Ok.'));

        $stack = new RequestStack();
        $stack->push(new Request());
        $tool = $this->tool($pages, max: 1, requests: $stack);

        $bad = $tool->execute(['url' => 'file:///etc/passwd'], $this->user());
        $good = $tool->execute(['url' => 'https://example.com/ok'], $this->user());

        self::assertTrue($bad['isError']);
        self::assertStringContainsString('absolute http or https URL', $bad['text']);
        self::assertFalse($good['isError']);
    }

    public function testZeroMeansNoPageIsOpened(): void
    {
        $pages = $this->createMock(UrlContentService::class);
        $pages->expects($this->never())->method('fetchForReading');

        $result = $this->tool($pages, max: 0)->execute(
            ['url' => 'https://example.com'],
            $this->user(),
        );

        self::assertTrue($result['isError']);
        self::assertStringContainsString(WebFetchTool::PAGE_LIMIT_MESSAGE, $result['text']);
    }

    private function tool(
        UrlContentService $pages,
        int $max,
        ?ContextCondenser $condenser = null,
        ?RequestStack $requests = null,
    ): WebFetchTool {
        $config = $this->createStub(MessagesGatewayConfig::class);
        $config->method('webFetchMaxPerTurn')->willReturn($max);

        return new WebFetchTool(
            $pages,
            $condenser ?? $this->createStub(ContextCondenser::class),
            $config,
            $requests ?? new RequestStack(),
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
