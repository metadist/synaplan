<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Compute;

use App\Entity\ComputeWorkspace;
use App\Entity\User;
use App\Repository\ComputeWorkspaceRepository;
use App\Repository\ConfigRepository;
use App\Repository\UserRepository;
use App\Service\Compute\ComputeClient;
use App\Service\Compute\ComputeConfig;
use App\Service\Compute\ComputeWorkspaceExpirer;
use App\Service\InternalEmailService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ComputeWorkspaceExpirerTest extends TestCase
{
    public function testIdleWhenDisabled(): void
    {
        $workspaces = $this->createMock(ComputeWorkspaceRepository::class);
        $workspaces->expects(self::never())->method('findStaleActiveWorkspaces');
        $expirer = new ComputeWorkspaceExpirer(
            $this->config(enabled: false),
            $this->client([]),
            $workspaces,
            $this->createStub(UserRepository::class),
            $this->mailer(),
            new NullLogger()
        );

        self::assertSame(['notified' => 0, 'deleted' => 0, 'skipped' => 0], $expirer->expire());
    }

    public function testNotifyThenDelete(): void
    {
        $stale = new ComputeWorkspace(7, 'ws-stale', 256);
        $due = new ComputeWorkspace(7, 'ws-due', 256);
        $due->markExpiring(new \DateTimeImmutable('-1 day'));

        $workspaces = $this->createMock(ComputeWorkspaceRepository::class);
        $workspaces->method('findStaleActiveWorkspaces')->willReturn([$stale]);
        $workspaces->method('findExpiredWorkspaces')->willReturn([$due]);

        $user = $this->createStub(User::class);
        $user->method('getMail')->willReturn('demo@synaplan.com');
        $user->method('getLocale')->willReturn('en');
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturn($user);

        $sent = [];
        $transport = $this->createMock(TransportInterface::class);
        $transport->method('send')->willReturnCallback(
            static function (RawMessage $message) use (&$sent): void {
                $sent[] = $message;
            }
        );

        $seen = [];
        $client = new ComputeClient(new MockHttpClient(static function (string $method, string $url) use (&$seen): MockResponse {
            $seen[] = $method.' '.$url;

            return new MockResponse('', ['http_code' => 204]);
        }), $this->config(enabled: true));

        $expirer = new ComputeWorkspaceExpirer(
            $this->config(enabled: true),
            $client,
            $workspaces,
            $users,
            $this->mailer($transport),
            new NullLogger()
        );

        self::assertSame(['notified' => 1, 'deleted' => 1, 'skipped' => 0], $expirer->expire());
        self::assertSame(ComputeWorkspace::STATUS_EXPIRING, $stale->getStatus());
        self::assertNotNull($stale->getExpiresAt());
        self::assertSame(ComputeWorkspace::STATUS_DELETED, $due->getStatus());
        self::assertCount(1, $sent);
        self::assertContains('DELETE http://compute:8080/v1/workspaces/ws-due', $seen);
    }

    public function testSkipsLocalPlaceholderAddresses(): void
    {
        $stale = new ComputeWorkspace(7, 'ws-stale', 256);
        $workspaces = $this->createMock(ComputeWorkspaceRepository::class);
        $workspaces->method('findStaleActiveWorkspaces')->willReturn([$stale]);
        $workspaces->method('findExpiredWorkspaces')->willReturn([]);

        $user = $this->createStub(User::class);
        $user->method('getMail')->willReturn('ghost@synaplan.local');
        $user->method('getLocale')->willReturn('en');
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturn($user);

        $transport = $this->createMock(TransportInterface::class);
        $transport->expects(self::never())->method('send');

        $expirer = new ComputeWorkspaceExpirer(
            $this->config(enabled: true),
            $this->client([]),
            $workspaces,
            $users,
            $this->mailer($transport),
            new NullLogger()
        );

        self::assertSame(['notified' => 1, 'deleted' => 0, 'skipped' => 0], $expirer->expire());
        self::assertSame(ComputeWorkspace::STATUS_EXPIRING, $stale->getStatus());
    }

    private function config(bool $enabled): ComputeConfig
    {
        $repo = $this->createStub(ConfigRepository::class);
        $repo->method('getValue')->willReturnCallback(
            static fn (int $ownerId, string $group, string $setting): ?string => 'ENABLED' === $setting && $enabled ? '1' : null
        );

        return new ComputeConfig($repo, 'http://compute:8080', 'token-token-token-token-token-32b');
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function client(array $responses): ComputeClient
    {
        return new ComputeClient(new MockHttpClient($responses), $this->config(enabled: true));
    }

    private function mailer(?TransportInterface $transport = null): InternalEmailService
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $params = []): string => $id.'|'.json_encode($params)
        );

        return new InternalEmailService(
            new Mailer($transport ?? $this->createStub(TransportInterface::class)),
            $this->createStub(Environment::class),
            $translator,
            new NullLogger()
        );
    }
}
