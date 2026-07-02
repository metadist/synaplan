<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\AI\Service\AiFacade;
use App\Prompt\PromptCatalog;
use App\Repository\PromptMetaRepository;
use App\Repository\PromptRepository;
use App\Repository\UserRepository;
use App\Service\ModelConfigService;
use App\Service\Multitask\Plan\TaskPlanValidator;
use App\Service\Multitask\TaskPlanner;
use App\Service\Prompt\TimeContextBuilder;
use App\Service\PromptService;
use App\Tests\Support\SkillCatalogFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

/**
 * Golden snapshot of the FULLY RENDERED planner system prompt.
 *
 * The planner prompt is the routing contract of the multitask engine: every
 * `[CAPABILITYLIST]` / `[DYNAMICLIST]` / `[KEYLIST]` substitution, every
 * routing rule and every canonical example influences which DAGs the model
 * emits. This snapshot makes any change to that surface an EXPLICIT, reviewed
 * diff instead of an invisible side effect — and it is the equivalence proof
 * required by the Sprint-3 catalog-lite refactor ("assemble [CAPABILITYLIST]
 * from SkillDescriptors and show the rendered prompt is byte-identical").
 *
 * Deterministic by construction: fixed topic fixtures, a MockClock'd
 * TimeContextBuilder, and a pinned process timezone.
 *
 * Record/refresh the baseline with: UPDATE_ROUTING_SNAPSHOTS=1
 */
final class PlannerPromptCharacterizationTest extends TestCase
{
    private const SNAPSHOT_FILE = __DIR__.'/__snapshots__/planner_system_prompt.txt';

    /** Fixed topic fixture standing in for the BPROMPTS routing pool. */
    private const TOPIC_FIXTURE = [
        ['topic' => 'general', 'description' => 'Catch-all topic for everyday questions.'],
        ['topic' => 'mediamaker', 'description' => 'Media-generation topic for images, videos and audio.'],
        ['topic' => 'officemaker', 'description' => 'Generate a single Office document.'],
        ['topic' => 'docsummary', 'description' => 'Summarize a document or attached file text.'],
        ['topic' => 'legal-review', 'description' => 'Custom user topic: review legal clauses.'],
    ];

    public function testRenderedPlannerPromptMatchesGoldenSnapshot(): void
    {
        $rendered = $this->renderPrompt();

        if (false !== getenv('UPDATE_ROUTING_SNAPSHOTS') && '' !== (string) getenv('UPDATE_ROUTING_SNAPSHOTS')) {
            if (!is_dir(dirname(self::SNAPSHOT_FILE))) {
                mkdir(dirname(self::SNAPSHOT_FILE), 0o775, true);
            }
            file_put_contents(self::SNAPSHOT_FILE, $rendered);
            self::assertNotSame('', $rendered, 'Recorded planner prompt baseline.');

            return;
        }

        self::assertFileExists(
            self::SNAPSHOT_FILE,
            'Missing planner prompt baseline. Generate it once with UPDATE_ROUTING_SNAPSHOTS=1 and commit '.self::SNAPSHOT_FILE,
        );

        self::assertSame(
            (string) file_get_contents(self::SNAPSHOT_FILE),
            $rendered,
            'The rendered planner system prompt drifted. If the change is intentional, review the diff line by line '
            .'and re-record with UPDATE_ROUTING_SNAPSHOTS=1.',
        );
    }

    private function renderPrompt(): string
    {
        $previousTz = date_default_timezone_get();
        date_default_timezone_set('UTC');

        try {
            $planner = $this->buildPlanner();
            $template = $this->planTemplate();

            return $planner->renderSystemPrompt($template, null);
        } finally {
            date_default_timezone_set($previousTz);
        }
    }

    private function buildPlanner(): TaskPlanner
    {
        $prompts = $this->createMock(PromptRepository::class);
        $prompts->method('getAllTopics')->willReturn(
            array_map(static fn (array $t): string => $t['topic'], self::TOPIC_FIXTURE),
        );
        $prompts->method('getTopicsWithDescriptions')->willReturn(self::TOPIC_FIXTURE);

        return new TaskPlanner(
            $this->createMock(AiFacade::class),
            $prompts,
            $this->createMock(ModelConfigService::class),
            new TaskPlanValidator(),
            new NullLogger(),
            $this->createMock(UserRepository::class),
            new TimeContextBuilder(new MockClock('2026-06-17 12:00:00', 'UTC')),
            // The REAL runner-declared descriptors — this is what makes the
            // snapshot prove the catalog-lite assembly is equivalent to the
            // previous hard-coded CAPABILITY_DESCRIPTIONS array.
            SkillCatalogFactory::real(),
            new PromptService(
                $this->createMock(PromptRepository::class),
                $this->createMock(PromptMetaRepository::class),
                $this->createMock(EntityManagerInterface::class),
                new NullLogger(),
            ),
        );
    }

    /** The shipped tools:plan template from the built-in catalog. */
    private function planTemplate(): string
    {
        foreach (PromptCatalog::all() as $definition) {
            if ('tools:plan' === $definition['topic']) {
                return $definition['prompt'];
            }
        }

        self::fail('tools:plan template missing from PromptCatalog::all().');
    }
}
