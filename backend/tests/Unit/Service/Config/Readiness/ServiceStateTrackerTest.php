<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Config\Readiness;

use App\Service\Config\Readiness\ReadinessObservation;
use App\Service\Config\Readiness\ServiceReadiness;
use App\Service\Config\Readiness\ServiceStateTracker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class ServiceStateTrackerTest extends TestCase
{
    private MockClock $clock;
    private ServiceStateTracker $tracker;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-10-19 12:00:00 UTC');
        $this->tracker = new ServiceStateTracker(new ArrayAdapter(), $this->clock);
    }

    public function testAServiceThatNeverAnsweredIsStartingForTenMinutesThenFailed(): void
    {
        $first = $this->tracker->track('qdrant', ReadinessObservation::down());
        self::assertSame(ServiceReadiness::STARTING, $first['state']);
        self::assertSame('2026-10-19T12:00:00+00:00', $first['since']);

        $this->clock->sleep(ServiceStateTracker::START_GRACE_SECONDS - 1);
        self::assertSame(ServiceReadiness::STARTING, $this->tracker->track('qdrant', ReadinessObservation::down())['state']);

        $this->clock->sleep(1);
        $late = $this->tracker->track('qdrant', ReadinessObservation::down());
        self::assertSame(ServiceReadiness::FAILED, $late['state']);
        self::assertSame('2026-10-19T12:00:00+00:00', $late['since'], 'the state still began at the first observation');
    }

    public function testAServiceThatWasReadyAndStopsAnsweringIsUnavailable(): void
    {
        self::assertSame(ServiceReadiness::READY, $this->tracker->track('tika', ReadinessObservation::ready())['state']);

        $this->clock->sleep(60);
        $down = $this->tracker->track('tika', ReadinessObservation::down());

        self::assertSame(ServiceReadiness::UNAVAILABLE, $down['state']);
        self::assertSame('2026-10-19T12:01:00+00:00', $down['since']);
    }

    public function testALongSilenceStartsTheHistoryOver(): void
    {
        $this->tracker->track('qdrant', ReadinessObservation::ready());
        $this->clock->sleep(ServiceStateTracker::STREAK_GAP_SECONDS + 1);

        $next = $this->tracker->track('qdrant', ReadinessObservation::down());

        self::assertSame(ServiceReadiness::STARTING, $next['state'], 'a restarted stack is starting, not unavailable');
    }

    public function testAnOldNeverReadyHistoryDoesNotReadAsFailedAfterARestart(): void
    {
        $this->tracker->track('qdrant', ReadinessObservation::down());
        $this->clock->sleep(ServiceStateTracker::STREAK_GAP_SECONDS + 1);

        self::assertSame(ServiceReadiness::STARTING, $this->tracker->track('qdrant', ReadinessObservation::down())['state']);
    }

    public function testADownloadEstimatesTheMinutesLeftFromItsProgress(): void
    {
        $start = $this->tracker->track('ollama', ReadinessObservation::downloading(10, 'gpt-oss:20b'));
        self::assertSame(ServiceReadiness::DOWNLOADING, $start['state']);
        self::assertNull($start['minutes_left'], 'no rate after one observation');

        $this->clock->sleep(120);
        $later = $this->tracker->track('ollama', ReadinessObservation::downloading(30, 'gpt-oss:20b'));

        // 20 % in 2 minutes leaves 70 % for 7 minutes.
        self::assertSame(7, $later['minutes_left']);
    }

    public function testANewModelRestartsTheEstimate(): void
    {
        $this->tracker->track('ollama', ReadinessObservation::downloading(10, 'bge-m3'));
        $this->clock->sleep(120);
        $this->tracker->track('ollama', ReadinessObservation::downloading(90, 'bge-m3'));
        $this->clock->sleep(10);

        self::assertNull($this->tracker->track('ollama', ReadinessObservation::downloading(5, 'gpt-oss:20b'))['minutes_left']);
    }

    public function testADownloadWithoutProgressFailsAfterHalfAnHour(): void
    {
        self::assertSame(ServiceReadiness::DOWNLOADING, $this->tracker->track('whisper', ReadinessObservation::downloading())['state']);

        for ($elapsed = 0; $elapsed < ServiceStateTracker::DOWNLOAD_STALL_SECONDS; $elapsed += 600) {
            $this->clock->sleep(600);
            $state = $this->tracker->track('whisper', ReadinessObservation::downloading())['state'];
        }

        self::assertSame(ServiceReadiness::FAILED, $state);
    }

    public function testOffAndBrokenMapStraightThrough(): void
    {
        self::assertSame(ServiceReadiness::OFF, $this->tracker->track('docling', ReadinessObservation::off())['state']);
        self::assertSame(ServiceReadiness::FAILED, $this->tracker->track('whisper', ReadinessObservation::broken())['state']);
    }
}
