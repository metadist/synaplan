<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Production code re-arms PHP's execution-time limit per work window
 * (`set_time_limit(120)` in the vectorization and media-polling loops) so a
 * FrankenPHP request is not cut mid-window. In the one long-lived PHPUnit
 * process that limit outlives the test that armed it and counts the CPU time
 * of every later test, until the suite dies with "Maximum execution time of
 * 120 seconds exceeded" in whatever test happens to run then. The CLI starts
 * with no limit; this restores that before every test.
 *
 * `PHPUNIT_REPORT_TIME_LIMIT=1` names the tests that leave a limit behind.
 */
final class NoExecutionTimeLimitExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new class implements PreparationStartedSubscriber {
            public function notify(PreparationStarted $event): void
            {
                set_time_limit(0);
            }
        });

        if ('1' === getenv('PHPUNIT_REPORT_TIME_LIMIT')) {
            $facade->registerSubscriber(new class implements FinishedSubscriber {
                public function notify(Finished $event): void
                {
                    $limit = (int) ini_get('max_execution_time');
                    if ($limit > 0) {
                        fwrite(STDERR, sprintf("\n[time limit] %s left max_execution_time=%d\n", $event->test()->id(), $limit));
                    }
                }
            });
        }
    }
}
