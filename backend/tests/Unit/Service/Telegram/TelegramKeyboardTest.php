<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Telegram;

use App\Service\Telegram\TelegramKeyboard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TelegramKeyboardTest extends TestCase
{
    public function testEveryButtonFitsTelegramsCallbackLimit(): void
    {
        $label = static fn (string $key): string => $key;
        $keyboards = [
            TelegramKeyboard::actions(PHP_INT_MAX, $label),
            TelegramKeyboard::models(PHP_INT_MAX, [2147483647 => str_repeat('Model ', 20)], $label),
            TelegramKeyboard::cancelJob(PHP_INT_MAX, $label),
        ];
        foreach ($keyboards as $keyboard) {
            foreach ($keyboard['inline_keyboard'] as $row) {
                foreach ($row as $button) {
                    $this->assertLessThanOrEqual(64, strlen($button['callback_data']));
                    $this->assertNotNull(TelegramKeyboard::parse($button['callback_data']));
                }
            }
        }
    }

    public function testAModelChoiceRoundTrips(): void
    {
        $this->assertSame(['action' => 'p', 'messageId' => 12, 'modelId' => 3], TelegramKeyboard::parse('p:12:3'));
        $this->assertSame(['action' => 'a', 'messageId' => 12, 'modelId' => null], TelegramKeyboard::parse('a:12'));
    }

    #[DataProvider('forged')]
    public function testForgedDataIsRejected(mixed $data): void
    {
        $this->assertNull(TelegramKeyboard::parse($data));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function forged(): iterable
    {
        yield 'unknown action' => ['z:12'];
        yield 'zero id' => ['a:0'];
        yield 'model without id' => ['p:12'];
        yield 'again with model' => ['a:12:3'];
        yield 'injection' => ["a:12\n"];
        yield 'not a string' => [12];
        yield 'too long' => ['a:'.str_repeat('1', 70)];
    }
}
