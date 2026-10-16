<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\Service\Message\ChatModelId;
use PHPUnit\Framework\TestCase;

final class ChatModelIdTest extends TestCase
{
    public function testPrefersTheModelThatRanOverTheRequestedId(): void
    {
        self::assertSame('76', ChatModelId::persisted(76, 324));
        self::assertSame('76', ChatModelId::persisted('76', '324'));
    }

    public function testUsesTheRequestWhenTheHandlerDidNotReportAnId(): void
    {
        self::assertSame('324', ChatModelId::persisted(null, 324));
        self::assertSame('324', ChatModelId::persisted('', 324));
    }

    public function testReturnsNullWhenNeitherIdIsUsable(): void
    {
        self::assertNull(ChatModelId::persisted(null, null));
        self::assertNull(ChatModelId::persisted(0, 'abc'));
    }
}
