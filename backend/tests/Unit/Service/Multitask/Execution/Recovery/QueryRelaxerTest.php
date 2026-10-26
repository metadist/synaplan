<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution\Recovery;

use App\AI\Service\AiFacade;
use App\Repository\PromptRepository;
use App\Repository\UserRepository;
use App\Service\Exception\StreamCancelledException;
use App\Service\ModelConfigService;
use App\Service\Multitask\Execution\Recovery\QueryRelaxer;
use App\Service\RateLimitService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class QueryRelaxerTest extends TestCase
{
    public function testBroadenKeepsTheStrongestTermAndDropsAnInventedFilter(): void
    {
        $next = $this->relaxer()->broaden(
            ['query' => 'Metadsit project notes', 'filter' => ['value' => 'page']],
            'Look up Metadsit',
            [],
        );

        self::assertSame(['query' => 'Metadsit'], $next);
    }

    public function testBroadenKeepsAFilterTheUserNamed(): void
    {
        $next = $this->relaxer()->broaden(
            ['query' => 'invoice archive', 'status' => 'Open'],
            'show my Open invoices',
            [],
        );

        self::assertSame(['query' => 'invoice', 'status' => 'Open'], $next);
    }

    public function testBroadenRefusesAQueryThatWasAlreadyTried(): void
    {
        $arguments = ['query' => 'Metadsit'];

        self::assertNull($this->relaxer()->broaden($arguments, 'Metadsit', []));
        self::assertNull($this->relaxer()->broaden(
            ['query' => 'Metadsit project'],
            'Metadsit',
            [QueryRelaxer::signature(['query' => 'Metadsit'])],
        ));
    }

    public function testSuggestCorrectsTheSpelling(): void
    {
        $captured = null;
        $relaxer = $this->relaxer(function (array $messages) use (&$captured): array {
            $captured = $messages;

            return ['content' => '{"arguments":{"query":"Metadist"}}', 'usage' => []];
        });

        $next = $relaxer->suggest('Metadsit', 'notion-search', ['query' => 'Metadsit'], [QueryRelaxer::signature(['query' => 'Metadsit'])], 7);

        self::assertSame(['query' => 'Metadist'], $next);
        self::assertStringContainsString('give_up', $captured[0]['content']);
    }

    public function testSuggestGivesUpAndIgnoresARepeat(): void
    {
        $relaxer = $this->relaxer(static fn (): array => ['content' => '{"give_up": true}']);
        self::assertNull($relaxer->suggest('Metadsit', 'notion-search', ['query' => 'Metadsit'], [], 7));

        $repeat = $this->relaxer(static fn (): array => ['content' => "```json\n".'{"arguments":{"query":"Metadsit"}}'."\n```"]);
        $tried = [QueryRelaxer::signature(['query' => 'Metadsit'])];
        self::assertNull($repeat->suggest('Metadsit', 'notion-search', ['query' => 'Metadsit'], $tried, 7));
    }

    public function testSuggestDropsKeysTheToolDoesNotHave(): void
    {
        $relaxer = $this->relaxer(static fn (): array => [
            'content' => '{"arguments":{"query":"Metadist","filter":{"value":"page"}}}',
        ]);

        self::assertSame(
            ['query' => 'Metadist'],
            $relaxer->suggest('Metadsit', 'notion-search', ['query' => 'Metadsit'], [], 7),
        );
    }

    public function testAMissingModelSkipsTheCall(): void
    {
        $ai = $this->createMock(AiFacade::class);
        $ai->expects(self::never())->method('chat');
        $models = $this->createMock(ModelConfigService::class);
        $models->method('getDefaultModel')->willReturn(null);

        $relaxer = new QueryRelaxer(
            $ai,
            $this->createMock(PromptRepository::class),
            $models,
            $this->createMock(UserRepository::class),
            $this->createMock(RateLimitService::class),
            new NullLogger(),
        );

        self::assertNull($relaxer->suggest('Metadsit', 'notion-search', ['query' => 'Metadsit'], [], 7));
    }

    public function testAProviderFailureGivesUpButCancellationStopsTheTurn(): void
    {
        $failed = $this->relaxer(static function (): array {
            throw new \RuntimeException('provider down');
        });
        self::assertNull($failed->suggest('Metadsit', 'notion-search', ['query' => 'Metadsit'], [], 7));

        $cancelled = $this->relaxer(static function (): array {
            throw new StreamCancelledException('stopped');
        });
        $this->expectException(StreamCancelledException::class);
        $cancelled->suggest('Metadsit', 'notion-search', ['query' => 'Metadsit'], [], 7);
    }

    /**
     * @param (callable(array<int, array{role: string, content: string}>): array<string, mixed>)|null $chat
     */
    private function relaxer(?callable $chat = null): QueryRelaxer
    {
        $ai = $this->createMock(AiFacade::class);
        if (null !== $chat) {
            $ai->method('chat')->willReturnCallback(
                static fn (array|string $messages, ?int $userId = null, array $options = []): array => $chat(is_array($messages) ? $messages : []),
            );
        }
        $models = $this->createMock(ModelConfigService::class);
        $models->method('getDefaultModel')->willReturn(4);
        $models->method('getProviderForModel')->willReturn('test');
        $models->method('getModelName')->willReturn('sort-test');

        return new QueryRelaxer(
            $ai,
            $this->createMock(PromptRepository::class),
            $models,
            $this->createMock(UserRepository::class),
            $this->createMock(RateLimitService::class),
            new NullLogger(),
        );
    }
}
