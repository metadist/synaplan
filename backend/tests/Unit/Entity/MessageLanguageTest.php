<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Message;
use PHPUnit\Framework\TestCase;

final class MessageLanguageTest extends TestCase
{
    public function testATwoLetterLanguageIsStoredAsGiven(): void
    {
        self::assertSame('de', (new Message())->setLanguage('de')->getLanguage());
    }

    public function testATurnThatFollowsTheUserIsStoredWithAnUnknownLanguage(): void
    {
        self::assertSame('NN', (new Message())->setLanguage('auto')->getLanguage());
    }
}
