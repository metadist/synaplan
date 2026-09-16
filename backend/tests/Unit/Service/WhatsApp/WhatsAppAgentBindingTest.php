<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\WhatsApp;

use App\Repository\ConfigRepository;
use App\Service\WhatsApp\WhatsAppAgentBinding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class WhatsAppAgentBindingTest extends TestCase
{
    private ConfigRepository&MockObject $config;
    private WhatsAppAgentBinding $binding;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigRepository::class);
        $this->binding = new WhatsAppAgentBinding($this->config);
    }

    public function testGetReadsThePinnedAgent(): void
    {
        $this->config->method('getValue')->willReturnMap([[4, 'WHATSAPP', 'AGENTID', '12']]);

        self::assertSame(12, $this->binding->get(4));
        self::assertTrue($this->binding->isBoundTo(4, 12));
        self::assertFalse($this->binding->isBoundTo(4, 13));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function emptyValues(): iterable
    {
        yield 'missing row' => [null];
        yield 'blank' => [''];
        yield 'zero' => ['0'];
        yield 'garbage' => ['abc'];
    }

    #[DataProvider('emptyValues')]
    public function testGetTreatsEmptyOrInvalidAsUnbound(?string $stored): void
    {
        $this->config->method('getValue')->willReturn($stored);

        self::assertNull($this->binding->get(4));
    }

    public function testSetWritesTheId(): void
    {
        $this->config->expects(self::once())->method('setValue')->with(4, 'WHATSAPP', 'AGENTID', '12');

        $this->binding->set(4, 12);
    }

    public function testSetNullClearsTheBinding(): void
    {
        $this->config->expects(self::once())->method('setValue')->with(4, 'WHATSAPP', 'AGENTID', '');

        $this->binding->set(4, null);
    }
}
