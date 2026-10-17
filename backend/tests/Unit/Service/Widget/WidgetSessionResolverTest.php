<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Widget;

use App\Entity\User;
use App\Entity\Widget;
use App\Entity\WidgetSession;
use App\Service\Widget\WidgetSessionResolver;
use App\Service\Widget\WidgetSessionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;

final class WidgetSessionResolverTest extends TestCase
{
    public function testOnlyTheSignedInOwnerOpensATestSession(): void
    {
        self::assertSame([true], $this->testModes(7, ['HTTP_X_WIDGET_TEST_MODE' => 'true']));
        self::assertSame([false], $this->testModes(8, ['HTTP_X_WIDGET_TEST_MODE' => 'true']));
        self::assertSame([false], $this->testModes(null, ['HTTP_X_WIDGET_TEST_MODE' => 'true']));
    }

    public function testTheOwnersInternalModeTurnsAnAiSessionInternalAtOnce(): void
    {
        $session = new WidgetSession();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $this->resolver(7, $session, $em)->resolve($this->request(['HTTP_X_WIDGET_INTERNAL_MODE' => 'true']), $this->widget(), 'sess');

        self::assertSame(WidgetSession::MODE_INTERNAL, $session->getMode());
    }

    public function testASessionAPersonRunsKeepsItsMode(): void
    {
        $session = (new WidgetSession())->setMode(WidgetSession::MODE_HUMAN);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $this->resolver(7, $session, $em)->resolve($this->request(['HTTP_X_WIDGET_INTERNAL_MODE' => 'true']), $this->widget(), 'sess');

        self::assertSame(WidgetSession::MODE_HUMAN, $session->getMode());
    }

    /**
     * @param array<string, string> $headers
     *
     * @return list<bool> the test mode each session lookup asked for
     */
    private function testModes(?int $userId, array $headers): array
    {
        $modes = [];
        $sessions = $this->createStub(WidgetSessionService::class);
        $sessions->method('getOrCreateSession')->willReturnCallback(static function (string $widgetId, string $sessionId, bool $isTestMode) use (&$modes): WidgetSession {
            $modes[] = $isTestMode;

            return new WidgetSession();
        });

        (new WidgetSessionResolver($sessions, $this->security($userId), $this->createStub(EntityManagerInterface::class)))
            ->resolve($this->request($headers), $this->widget(), 'sess');

        return $modes;
    }

    private function resolver(int $userId, WidgetSession $session, EntityManagerInterface $em): WidgetSessionResolver
    {
        $sessions = $this->createStub(WidgetSessionService::class);
        $sessions->method('getOrCreateSession')->willReturn($session);

        return new WidgetSessionResolver($sessions, $this->security($userId), $em);
    }

    private function security(?int $userId): Security
    {
        $user = null;
        if (null !== $userId) {
            $user = $this->createStub(User::class);
            $user->method('getId')->willReturn($userId);
        }
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return $security;
    }

    private function widget(): Widget
    {
        return (new Widget())->setWidgetId('wdg_1')->setOwnerId(7);
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(array $headers): Request
    {
        return Request::create('/', 'POST', [], [], [], $headers);
    }
}
