<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\AI\Service\AiFacade;
use App\Service\File\FileGroupSorter;
use App\Service\ModelConfigService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class FileGroupSorterTest extends TestCase
{
    private AiFacade&MockObject $aiFacade;
    private ModelConfigService&MockObject $modelConfig;

    protected function setUp(): void
    {
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->modelConfig = $this->createMock(ModelConfigService::class);
    }

    private function sorter(): FileGroupSorter
    {
        return new FileGroupSorter($this->aiFacade, $this->modelConfig, new NullLogger());
    }

    private function configureModel(): void
    {
        $this->modelConfig->method('getDefaultModel')->willReturn(7);
        $this->modelConfig->method('getProviderForModel')->willReturn('ollama');
        $this->modelConfig->method('getModelName')->willReturn('llama');
    }

    public function testReturnsNullForEmptyText(): void
    {
        $this->aiFacade->expects(self::never())->method('chat');

        self::assertNull($this->sorter()->suggestGroup('   ', ['Contracts'], 1));
    }

    public function testReturnsNullWhenNoSortModelConfigured(): void
    {
        $this->modelConfig->method('getDefaultModel')->willReturn(null);
        $this->aiFacade->expects(self::never())->method('chat');

        self::assertNull($this->sorter()->suggestGroup('a photo of a dog', [], 1));
    }

    public function testMatchesExistingGroupCaseInsensitively(): void
    {
        $this->configureModel();
        $this->aiFacade->method('chat')->willReturn(['content' => 'contracts', 'provider' => 'ollama']);

        self::assertSame('Contracts', $this->sorter()->suggestGroup('a signed PDF', ['Contracts', 'Brand'], 1));
    }

    public function testProposesNewGroupWhenNoneFit(): void
    {
        $this->configureModel();
        $this->aiFacade->method('chat')->willReturn(['content' => "Pets\n", 'provider' => 'ollama']);

        self::assertSame('Pets', $this->sorter()->suggestGroup('a photo of a dog', ['Contracts'], 1));
    }

    public function testStripsQuotesAndRejectsOversizedReplies(): void
    {
        $this->configureModel();
        $this->aiFacade->method('chat')->willReturn(['content' => str_repeat('x', 200)]);

        self::assertNull($this->sorter()->suggestGroup('something', [], 1));
    }

    public function testReturnsNullWhenModelCallThrows(): void
    {
        $this->configureModel();
        $this->aiFacade->method('chat')->willThrowException(new \RuntimeException('provider down'));

        self::assertNull($this->sorter()->suggestGroup('something', [], 1));
    }
}
