<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Service\Research\Run\Ledger\Passage;
use App\Service\Research\Run\Stage\PassageFocus;
use PHPUnit\Framework\TestCase;

final class PassageFocusTest extends TestCase
{
    public function testAPageThatFitsIsSentWhole(): void
    {
        $passages = [new Passage('§1', 'Menu'), new Passage('§2', 'The unit price is 12.40 EUR.')];

        self::assertSame($passages, PassageFocus::pick($passages, ['unit price'], 1000));
    }

    public function testMenusAtTheTopDoNotCrowdOutTheAnswer(): void
    {
        $passages = [];
        for ($i = 1; $i <= 40; ++$i) {
            $passages[] = new Passage('§'.$i, 0 === $i % 2 ? 'Grey Water' : str_repeat('Products Applications Login Contact ', 3));
        }
        $passages[] = new Passage('§41', 'Why eggs take longer on high mountains.');
        $passages[] = new Passage('§42', 'At the summit of Mount Everest water boils at about 70 °C.');
        $passages[] = new Passage('§43', 'That is why the egg stays soft.');
        $passages[] = new Passage('§44', str_repeat('Footer links ', 5));

        $sent = PassageFocus::pick($passages, ['Boiling point of water on Everest', 'water boiling point Everest summit'], 400);
        $ids = array_map(static fn (Passage $passage): string => $passage->id, $sent);

        self::assertSame(['§41', '§42', '§43'], $ids);
        self::assertLessThanOrEqual(400, array_sum(array_map(PassageFocus::cost(...), $sent)));
    }

    public function testWithNoSharedWordTheBeginningIsSent(): void
    {
        $passages = [];
        for ($i = 1; $i <= 10; ++$i) {
            $passages[] = new Passage('§'.$i, str_repeat('x', 90));
        }

        $ids = array_map(static fn (Passage $passage): string => $passage->id, PassageFocus::pick($passages, ['unit price'], 300));

        self::assertSame(['§1', '§2', '§3'], $ids);
    }
}
