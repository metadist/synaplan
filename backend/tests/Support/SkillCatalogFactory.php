<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Multitask\Execution\Runner\CalendarEventRunner;
use App\Service\Multitask\Execution\Runner\ChatRunner;
use App\Service\Multitask\Execution\Runner\ComposeReplyRunner;
use App\Service\Multitask\Execution\Runner\DocumentGenerationRunner;
use App\Service\Multitask\Execution\Runner\EmailMeRunner;
use App\Service\Multitask\Execution\Runner\EmailSearchRunner;
use App\Service\Multitask\Execution\Runner\ExtractTextRunner;
use App\Service\Multitask\Execution\Runner\FileAnalysisRunner;
use App\Service\Multitask\Execution\Runner\McpFetchRunner;
use App\Service\Multitask\Execution\Runner\MediaGenerationRunner;
use App\Service\Multitask\Execution\Runner\Text2SoundRunner;
use App\Service\Multitask\Execution\Runner\UrlFetchRunner;
use App\Service\Multitask\Execution\Runner\WebSearchRunner;
use App\Service\Multitask\Execution\TaskRunner;
use App\Service\Multitask\Skill\SkillCatalog;

/**
 * Builds the REAL {@see SkillCatalog} for DB-free unit tests.
 *
 * `TaskRunner::describe()` is pure data (it touches no injected dependency),
 * so the runners are instantiated WITHOUT their constructors — the catalog a
 * test sees is assembled from the exact same descriptor declarations
 * production uses. This is what lets the planner-prompt characterization
 * prove byte-equivalence without booting the kernel.
 *
 * Keep this list in sync with the runners tagged `app.multitask.runner`
 * (one entry per runner class; SkillCatalogTest asserts full coverage of the
 * Capability enum, so a forgotten entry fails loudly).
 */
final class SkillCatalogFactory
{
    /** @var list<class-string<TaskRunner>> */
    private const RUNNER_CLASSES = [
        ExtractTextRunner::class,
        ChatRunner::class,
        WebSearchRunner::class,
        UrlFetchRunner::class,
        McpFetchRunner::class,
        EmailSearchRunner::class,
        FileAnalysisRunner::class,
        MediaGenerationRunner::class,
        Text2SoundRunner::class,
        DocumentGenerationRunner::class,
        CalendarEventRunner::class,
        EmailMeRunner::class,
        ComposeReplyRunner::class,
    ];

    public static function real(): SkillCatalog
    {
        return new SkillCatalog(self::runners());
    }

    /**
     * @return list<TaskRunner>
     */
    public static function runners(): array
    {
        $runners = [];
        foreach (self::RUNNER_CLASSES as $class) {
            /** @var TaskRunner $runner */
            $runner = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            $runners[] = $runner;
        }

        return $runners;
    }
}
