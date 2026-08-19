<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Health;

use App\AI\Health\ModelHealthAlert;
use App\AI\Health\ModelHealthAlerter;
use App\AI\Health\ModelHealthConfig;
use App\Repository\ConfigRepository;
use App\Service\DiscordNotificationService;
use App\Service\InternalEmailService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ModelHealthAlerterTest extends TestCase
{
    private ArrayAdapter $cache;
    private InternalEmailService&MockObject $email;
    private DiscordNotificationService&MockObject $discord;
    private ModelHealthAlerter $alerter;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
        $this->email = $this->createMock(InternalEmailService::class);
        $this->discord = $this->createMock(DiscordNotificationService::class);

        $configRepository = $this->createStub(ConfigRepository::class);
        $configRepository->method('getValue')->willReturn(null);

        $this->alerter = new ModelHealthAlerter(
            $this->cache,
            new ModelHealthConfig($configRepository),
            $this->email,
            $this->discord,
            new NullLogger(),
        );
    }

    private static function alert(string $kind = ModelHealthAlert::KIND_OFFLINE): ModelHealthAlert
    {
        return new ModelHealthAlert($kind, 'OpenAI', ['GPT-5', 'GPT-5 Vision'], 'retired upstream');
    }

    public function testFirstAlertReachesBothChannels(): void
    {
        $this->email->expects(self::once())->method('sendModelHealthAlert');
        $this->discord->expects(self::once())->method('notifyModelHealth');

        self::assertTrue($this->alerter->raise(self::alert()));
    }

    /**
     * A flapping provider must not turn into a stream of identical emails —
     * that is how an alert channel gets muted and the next real outage is
     * missed.
     */
    public function testRepeatedAlertsAreThrottled(): void
    {
        $this->email->expects(self::once())->method('sendModelHealthAlert');
        $this->discord->expects(self::once())->method('notifyModelHealth');

        self::assertTrue($this->alerter->raise(self::alert()));
        self::assertFalse($this->alerter->raise(self::alert()));
        self::assertFalse($this->alerter->raise(self::alert()));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDifferentKindsForTheSameProviderAlertSeparately(): void
    {
        $this->email->expects(self::exactly(2))->method('sendModelHealthAlert');

        self::assertTrue($this->alerter->raise(self::alert(ModelHealthAlert::KIND_OFFLINE)));
        self::assertTrue($this->alerter->raise(self::alert(ModelHealthAlert::KIND_CREDENTIAL)));
    }

    /**
     * An all-clear for an incident nobody was told about is worse than none:
     * it reports a recovery the operator cannot place.
     */
    public function testNoAllClearWithoutAnOpenIncident(): void
    {
        $this->email->expects(self::never())->method('sendModelHealthAlert');
        $this->discord->expects(self::never())->method('notifyModelHealth');

        self::assertFalse($this->alerter->resolve(self::alert()));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAllClearFollowsAnOpenIncidentAndClosesIt(): void
    {
        $this->alerter->raise(self::alert());
        self::assertTrue($this->alerter->isOpen('OpenAI', ModelHealthAlert::KIND_OFFLINE));

        self::assertTrue($this->alerter->resolve(self::alert()));
        self::assertFalse($this->alerter->isOpen('OpenAI', ModelHealthAlert::KIND_OFFLINE));

        // Second all-clear is a no-op — the incident is already closed.
        self::assertFalse($this->alerter->resolve(self::alert()));
    }

    /**
     * The all-clear also clears the throttle, so a fresh outage right after a
     * recovery is reported immediately instead of inheriting the old silence.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testRecoveryClearsTheThrottleWindow(): void
    {
        $this->alerter->raise(self::alert());
        $this->alerter->resolve(self::alert());

        self::assertTrue($this->alerter->raise(self::alert()));
    }

    /**
     * Provider names come straight from BSERVICE and can carry characters PSR-6
     * reserves for cache keys.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testProviderNamesWithReservedCharactersDoNotBreakTheCacheKey(): void
    {
        $alert = new ModelHealthAlert(ModelHealthAlert::KIND_OFFLINE, 'Open{AI}:test', ['x'], 'gone');

        self::assertTrue($this->alerter->raise($alert));
        self::assertFalse($this->alerter->raise($alert));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHeadlineAndPreviewSummariseLargeOutages(): void
    {
        $names = array_map(static fn (int $i): string => 'model-'.$i, range(1, 25));
        $alert = new ModelHealthAlert(ModelHealthAlert::KIND_CREDENTIAL, 'Groq', $names, 'key rejected');

        self::assertSame(25, $alert->modelCount());
        self::assertStringContainsString('Groq credentials rejected', $alert->headline());
        self::assertStringContainsString('and 15 more', $alert->previewNames());
    }
}
