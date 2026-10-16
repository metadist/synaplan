<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Health;

use App\AI\Health\ModelHealthAlert;
use App\AI\Health\ModelHealthAlerter;
use App\AI\Health\ModelHealthConfig;
use App\Entity\ModelHealth;
use App\Repository\ConfigRepository;
use App\Repository\ModelHealthRepository;
use App\Service\Email\InternalEmailService;
use App\Service\Notification\DiscordNotificationService;
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

    /** @var array<int, ModelHealth> */
    private array $healthRows = [];

    /** @var array<string, string> */
    private array $settings = [];

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
        $this->healthRows = [];
        $this->settings = [];
        $this->email = $this->createMock(InternalEmailService::class);
        $this->discord = $this->createMock(DiscordNotificationService::class);

        $health = $this->createStub(ModelHealthRepository::class);
        $health->method('findIndexedByModelId')->willReturnCallback(function (array $ids): array {
            if ([] === $ids) {
                return $this->healthRows;
            }

            $found = [];
            foreach ($ids as $id) {
                $id = (int) $id;
                if (isset($this->healthRows[$id])) {
                    $found[$id] = $this->healthRows[$id];
                }
            }

            return $found;
        });
        $health->method('stampAlerted')->willReturnCallback(function (array $ids, string $kind, int $at): void {
            foreach ($ids as $id) {
                $this->healthRows[$id] ??= (new ModelHealth())->setModelId($id);
                $this->healthRows[$id]->setLastAlerted($at)->setLastAlertedKind($kind);
            }
        });

        $configRepository = $this->createStub(ConfigRepository::class);
        $configRepository->method('getValue')->willReturnCallback(
            fn (int $owner, string $group, string $setting): ?string => $this->setting($setting),
        );

        $this->alerter = new ModelHealthAlerter(
            $this->cache,
            $health,
            new ModelHealthConfig($configRepository),
            $this->email,
            $this->discord,
            new NullLogger(),
        );
    }

    private function setting(string $name): ?string
    {
        return $this->settings[$name] ?? null;
    }

    /**
     * @param list<int>    $modelIds
     * @param list<string> $names
     */
    private static function alert(
        string $kind = ModelHealthAlert::KIND_OFFLINE,
        array $modelIds = [1, 2],
        array $names = ['GPT-5', 'GPT-5 Vision'],
        string $provider = 'OpenAI',
        ?string $displayName = null,
    ): ModelHealthAlert {
        return new ModelHealthAlert($kind, $provider, $names, 'retired upstream', $displayName, $modelIds);
    }

    public function testFirstAlertReachesBothChannels(): void
    {
        $this->email->expects(self::once())->method('sendModelHealthAlert');
        $this->discord->expects(self::once())->method('notifyModelHealth');

        self::assertTrue($this->alerter->raise(self::alert()));
        self::assertGreaterThan(0, $this->healthRows[1]->getLastAlerted());
        self::assertGreaterThan(0, $this->healthRows[2]->getLastAlerted());
    }

    /**
     * A model that stays offline must not page on every health check.
     * That is how an alert channel gets muted and the next real outage is missed.
     */
    public function testTheSameModelIsQuietForADay(): void
    {
        $this->email->expects(self::once())->method('sendModelHealthAlert');
        $this->discord->expects(self::once())->method('notifyModelHealth');

        $alert = self::alert();
        self::assertTrue($this->alerter->raise($alert));
        self::assertFalse($this->alerter->raise($alert));
        self::assertFalse($this->alerter->raise($alert));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testADifferentModelIsReportedTheSameDay(): void
    {
        $sent = [];
        $this->email->method('sendModelHealthAlert')->willReturnCallback(
            static function (ModelHealthAlert $alert) use (&$sent): void {
                $sent[] = $alert;
            }
        );

        self::assertTrue($this->alerter->raise(self::alert(
            modelIds: [1],
            names: ['Grok Voice 3'],
            provider: 'xAI',
            displayName: 'xAI',
        )));
        self::assertTrue($this->alerter->raise(self::alert(
            modelIds: [2],
            names: ['Gemini 2.5 Pro'],
            provider: 'Google',
            displayName: 'Google',
        )));

        self::assertSame(['Grok Voice 3'], $sent[0]->modelNames);
        self::assertSame(['Gemini 2.5 Pro'], $sent[1]->modelNames);
        self::assertStringContainsString('24 hours', $sent[0]->quietPeriodNotice());
        self::assertStringContainsString('different model', $sent[0]->quietPeriodNotice());
    }

    /**
     * A second model of the same provider must not be dropped just because
     * the first one was already reported. The message names only the new one.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testALaterModelOfTheSameProviderIsNamedOnItsOwn(): void
    {
        $sent = [];
        $this->email->method('sendModelHealthAlert')->willReturnCallback(
            static function (ModelHealthAlert $alert) use (&$sent): void {
                $sent[] = $alert;
            }
        );

        self::assertTrue($this->alerter->raise(self::alert(
            modelIds: [1],
            names: ['Grok Voice 3'],
            provider: 'xAI',
            displayName: 'xAI',
        )));
        self::assertTrue($this->alerter->raise(self::alert(
            modelIds: [1, 2],
            names: ['Grok Voice 3', 'Grok 4.20'],
            provider: 'xAI',
            displayName: 'xAI',
        )));

        self::assertCount(2, $sent);
        self::assertSame(['Grok 4.20'], $sent[1]->modelNames);
        self::assertSame([2], $sent[1]->modelIds);
        self::assertFalse($this->alerter->raise(self::alert(
            modelIds: [2],
            names: ['Grok 4.20'],
            provider: 'xAI',
            displayName: 'xAI',
        )));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testTheSameModelIsReportedAgainAfterTheQuietPeriod(): void
    {
        $this->healthRows[1] = (new ModelHealth())
            ->setModelId(1)
            ->setLastAlerted(time() - (1440 * 60) - 5)
            ->setLastAlertedKind(ModelHealthAlert::KIND_OFFLINE);

        $this->email->expects(self::once())->method('sendModelHealthAlert');
        $this->discord->expects(self::once())->method('notifyModelHealth');

        $started = time();
        self::assertTrue($this->alerter->raise(self::alert(modelIds: [1], names: ['Grok Voice 3'])));
        self::assertGreaterThanOrEqual($started, $this->healthRows[1]->getLastAlerted());
    }

    /**
     * A flaky hour this morning must not hide a rejected key at noon: that is
     * the one alert that pings everyone. The same or a milder problem of the
     * same model stays quiet.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testOnlyAWorseProblemOfTheSameModelBreaksTheQuietPeriod(): void
    {
        $this->email->expects(self::exactly(3))->method('sendModelHealthAlert');
        $this->discord->expects(self::exactly(3))->method('notifyModelHealth');

        self::assertTrue($this->alerter->raise(self::alert(ModelHealthAlert::KIND_DEGRADED, [1], ['Grok Voice 3'])));
        self::assertTrue($this->alerter->raise(self::alert(ModelHealthAlert::KIND_OFFLINE, [1], ['Grok Voice 3'])));
        self::assertTrue($this->alerter->raise(self::alert(ModelHealthAlert::KIND_CREDENTIAL, [1], ['Grok Voice 3'])));
        self::assertFalse($this->alerter->raise(self::alert(ModelHealthAlert::KIND_OFFLINE, [1], ['Grok Voice 3'])));
        self::assertFalse($this->alerter->raise(self::alert(ModelHealthAlert::KIND_DEGRADED, [1], ['Grok Voice 3'])));
        self::assertSame(ModelHealthAlert::KIND_CREDENTIAL, $this->healthRows[1]->getLastAlertedKind());
    }

    /** The message must promise the quiet period the install actually uses. */
    #[AllowMockObjectsWithoutExpectations]
    public function testTheNoticeFollowsTheConfiguredQuietPeriod(): void
    {
        $this->settings['ALERT_THROTTLE_MINUTES'] = '120';
        $sent = [];
        $this->email->method('sendModelHealthAlert')->willReturnCallback(
            static function (ModelHealthAlert $alert) use (&$sent): void {
                $sent[] = $alert;
            }
        );

        self::assertTrue($this->alerter->raise(self::alert()));

        self::assertSame(7200, $sent[0]->quietForSeconds);
        self::assertStringContainsString('for 2 hours', $sent[0]->quietPeriodNotice());
        self::assertStringNotContainsString('24 hours', $sent[0]->quietPeriodNotice());
    }

    /** "0 model(s) are working again" told the operator nothing. */
    #[AllowMockObjectsWithoutExpectations]
    public function testTheAllClearNamesTheModelsOfTheIncident(): void
    {
        $resolved = [];
        $this->email->method('sendModelHealthAlert')->willReturnCallback(
            static function (ModelHealthAlert $alert, bool $isResolved = false) use (&$resolved): void {
                if ($isResolved) {
                    $resolved[] = $alert;
                }
            }
        );

        $this->alerter->raise(self::alert(modelIds: [1], names: ['GPT-5']));
        $this->alerter->raise(self::alert(modelIds: [1, 2], names: ['GPT-5', 'GPT-5 Vision']));
        self::assertSame([1 => 'GPT-5', 2 => 'GPT-5 Vision'], $this->alerter->openIncident('OpenAI', ModelHealthAlert::KIND_OFFLINE));

        self::assertTrue($this->alerter->resolve(new ModelHealthAlert(ModelHealthAlert::KIND_OFFLINE, 'OpenAI', [], 'All models are answering again.')));

        self::assertSame(['GPT-5', 'GPT-5 Vision'], $resolved[0]->modelNames);
        self::assertStringContainsString('24 hours', $resolved[0]->relapseNotice());
    }

    /** An incident opened before this change holds a bare timestamp. */
    #[AllowMockObjectsWithoutExpectations]
    public function testAnIncidentOpenedByTheOldFormatStillResolves(): void
    {
        $item = $this->cache->getItem('model_health.open.openai.offline');
        $item->set(time());
        $this->cache->save($item);

        self::assertSame([], $this->alerter->openIncident('OpenAI', ModelHealthAlert::KIND_OFFLINE));
        self::assertTrue($this->alerter->resolve(self::alert()));
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
     * Recovery used to wipe the silence window, so the same model paged again
     * on the next check. The quiet period outlives the all-clear.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testRecoveryDoesNotLetTheSameModelPageAgainTheSameDay(): void
    {
        $this->email->expects(self::exactly(3))->method('sendModelHealthAlert');
        $this->discord->expects(self::exactly(3))->method('notifyModelHealth');

        $alert = self::alert(modelIds: [1], names: ['Grok Voice 3'], provider: 'xAI', displayName: 'xAI');
        self::assertTrue($this->alerter->raise($alert));
        self::assertTrue($this->alerter->resolve($alert));
        self::assertFalse($this->alerter->raise($alert));

        self::assertTrue($this->alerter->raise(self::alert(
            modelIds: [2],
            names: ['Gemini 2.5 Pro'],
            provider: 'Google',
            displayName: 'Google',
        )));
    }

    /**
     * Provider names come straight from BSERVICE and can carry characters PSR-6
     * reserves for cache keys. The open-incident flag still has to stick.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testProviderNamesWithReservedCharactersDoNotBreakTheCacheKey(): void
    {
        $alert = new ModelHealthAlert(ModelHealthAlert::KIND_OFFLINE, 'Open{AI}:test', ['x'], 'gone', null, [9]);

        self::assertTrue($this->alerter->raise($alert));
        self::assertFalse($this->alerter->raise($alert));
        self::assertTrue($this->alerter->isOpen('Open{AI}:test', ModelHealthAlert::KIND_OFFLINE));
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

    /**
     * The alert carries two different things under similar names: `provider` is
     * the BSERVICE key that the open incident is keyed on, and `name()` is what
     * the operator reads. Rendering the key would put "ollama" and "xAI" in the
     * subject line.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testTheAlertRendersTheBrandedNameButTracksTheIncidentOnTheKey(): void
    {
        $alert = new ModelHealthAlert(ModelHealthAlert::KIND_OFFLINE, 'ollama', ['x'], 'gone', 'Ollama', [4]);

        self::assertSame('Ollama', $alert->name());
        self::assertStringContainsString('Ollama model(s)', $alert->headline());
        self::assertSame('ollama', $alert->provider);

        self::assertTrue($this->alerter->raise($alert));
        self::assertTrue($this->alerter->isOpen('ollama', ModelHealthAlert::KIND_OFFLINE));
    }

    /** Without a display name the key is still better than printing nothing. */
    #[AllowMockObjectsWithoutExpectations]
    public function testTheAlertFallsBackToTheKeyWhenNoNameIsGiven(): void
    {
        $alert = new ModelHealthAlert(ModelHealthAlert::KIND_OFFLINE, 'ollama', ['x'], 'gone');

        self::assertSame('ollama', $alert->name());
    }

    public function testAnAlertWithoutModelIdsIsNotSent(): void
    {
        $this->email->expects(self::never())->method('sendModelHealthAlert');
        $this->discord->expects(self::never())->method('notifyModelHealth');

        $alert = new ModelHealthAlert(ModelHealthAlert::KIND_OFFLINE, 'xAI', ['Grok Voice 3'], 'gone', 'xAI');

        self::assertFalse($this->alerter->raise($alert));
    }
}
