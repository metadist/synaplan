<?php

declare(strict_types=1);

namespace App\Tests\Unit\Observability;

use App\Observability\ServerIdentity;
use PHPUnit\Framework\TestCase;

final class ServerIdentityTest extends TestCase
{
    public function testTheConfiguredIdWins(): void
    {
        self::assertSame('web1', (new ServerIdentity('web1'))->id());
    }

    public function testUnsafeCharactersAreDroppedLikeTheWatchdogDoes(): void
    {
        self::assertSame('web1evil', (new ServerIdentity("web 1:evil\r\n"))->id());
    }

    public function testWithoutAnIdTheHostnameIsTheName(): void
    {
        $identity = new ServerIdentity(null);

        self::assertSame($identity->hostname(), $identity->id());
        self::assertNotSame('', $identity->id());
    }

    public function testAnEmptyIdFallsBackToTheHostname(): void
    {
        self::assertSame((new ServerIdentity(null))->id(), (new ServerIdentity(''))->id());
    }
}
