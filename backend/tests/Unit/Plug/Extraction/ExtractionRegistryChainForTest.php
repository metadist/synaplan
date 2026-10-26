<?php

declare(strict_types=1);

namespace App\Tests\Unit\Plug\Extraction;

use App\Plug\Extraction\ContentExtractorInterface;
use App\Plug\Extraction\ExtractionRegistry;
use App\Plug\Extraction\ExtractionRequest;
use App\Plug\Extraction\ExtractionResult;
use App\Plug\PlugConfigService;
use App\Plug\PlugDescriptor;
use App\Plug\PlugHealth;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ExtractionRegistryChainForTest extends TestCase
{
    public function testChainForDropsAdaptersThatRunInCloud(): void
    {
        $config = $this->createMock(PlugConfigService::class);
        $config->method('extractionChain')->willReturn(['tika', 'pdf_vision']);
        $registry = new ExtractionRegistry(
            [$this->adapter('tika', false), $this->adapter('pdf_vision', true)],
            $config,
            new NullLogger(),
        );

        $local = array_map(static fn (ContentExtractorInterface $adapter): string => $adapter->key(), $registry->chainFor('document', false));
        self::assertSame(['tika'], $local);
        foreach ($registry->chainFor('document', false) as $adapter) {
            self::assertFalse($adapter->runsInCloud());
        }

        $withCloud = array_map(static fn (ContentExtractorInterface $adapter): string => $adapter->key(), $registry->chainFor('document', true));
        self::assertSame(['tika', 'pdf_vision'], $withCloud);
    }

    private function adapter(string $key, bool $runsInCloud): ContentExtractorInterface
    {
        return new class($key, $runsInCloud) implements ContentExtractorInterface {
            public function __construct(private string $key, private bool $cloud)
            {
            }

            public function key(): string
            {
                return $this->key;
            }

            public function descriptor(): PlugDescriptor
            {
                return new PlugDescriptor($this->key, $this->key, '', [], 'self-hosted');
            }

            public function supports(ExtractionRequest $request): bool
            {
                return true;
            }

            public function extract(ExtractionRequest $request): ExtractionResult
            {
                return ExtractionResult::of('', $this->key);
            }

            public function runsInCloud(): bool
            {
                return $this->cloud;
            }

            public function health(): PlugHealth
            {
                return PlugHealth::available();
            }
        };
    }
}
