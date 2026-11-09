<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Stream;

use App\Entity\User;
use App\Entity\WidgetSession;
use App\Service\Chat\Stream\StreamCaller;
use PHPUnit\Framework\TestCase;

final class StreamCallerDiagnosticsTest extends TestCase
{
    public function testNormalUserDoesNotSeeTheDump(): void
    {
        $caller = new StreamCaller($this->user('PRO'), 'en');

        self::assertFalse($caller->seesProviderDiagnostics());
    }

    public function testAdministratorSeesTheDumpOnTheirOwnTurn(): void
    {
        $caller = new StreamCaller($this->user('ADMIN'), 'en');

        self::assertTrue($caller->seesProviderDiagnostics());
    }

    public function testImpersonatingAdministratorSeesTheDump(): void
    {
        $caller = new StreamCaller($this->user('PRO'), 'en', providerDiagnostics: true);

        self::assertTrue($caller->seesProviderDiagnostics());
    }

    public function testWidgetVisitorNeverSeesTheDump(): void
    {
        $caller = new StreamCaller(
            $this->user('ADMIN'),
            'en',
            widgetSession: new WidgetSession(),
            providerDiagnostics: true,
        );

        self::assertFalse($caller->seesProviderDiagnostics());
    }

    private function user(string $level): User
    {
        $user = new User();
        $user->setUserLevel($level);

        return $user;
    }
}
