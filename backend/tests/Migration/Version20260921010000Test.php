<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Prompt\PromptCatalog;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use DoctrineMigrations\Version20260921010000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Executes the planner-prompt migration's real statements against the test
 * database.
 *
 * Rule 3a (script execution → code_run) is the arbitration the v5.0.0 file-work
 * chain was missing: without it the planner sent "write a python script and
 * apply ..." to the AI image model. An install that silently keeps the old
 * prompt keeps the bug, so the up/down surgery, its idempotency, its
 * customized-prompt skip and its byte-identity with the catalog are all
 * locked here.
 *
 * Everything runs inside a transaction that is rolled back, so the fixture
 * rows never survive the test.
 */
final class Version20260921010000Test extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testItAddsRule3aToTheGlobalPlannerPrompt(): void
    {
        $this->givenGlobalPrompt($this->oldPrompt());

        $this->runUp();

        $result = $this->fetchGlobalPrompt();
        self::assertStringContainsString('rule 3a wins', $result);
        self::assertStringContainsString('3a. Script / program / code', $result);
        self::assertStringContainsString('→ `code_run`', $result);
        self::assertStringNotContainsString(
            '`image_generation`. To EDIT an image produced by an',
            $result,
            'the old rule 3 opening must be replaced, not duplicated'
        );
    }

    /**
     * Migrations run again on every container start of every node in the
     * cluster, and a partially applied one has to be safe to repeat.
     */
    public function testItIsIdempotent(): void
    {
        $this->givenGlobalPrompt($this->oldPrompt());

        $this->runUp();
        $this->runUp();

        self::assertSame(1, substr_count($this->fetchGlobalPrompt(), 'rule 3a wins'));
    }

    /**
     * An operator-customized prompt (anchors missing) is left alone — the
     * migration must never rewrite text it does not recognize.
     */
    public function testItLeavesCustomizedPromptsAlone(): void
    {
        $custom = "## Routing decisions (apply in order)\n\n3. Custom operator rules live here.\n";
        $this->givenGlobalPrompt($custom);

        $this->runUp();

        self::assertSame($custom, $this->fetchGlobalPrompt());
    }

    public function testDownRemovesRule3a(): void
    {
        $old = $this->oldPrompt();
        $this->givenGlobalPrompt($old);

        $this->runUp();
        $this->runDown();

        self::assertSame($old, $this->fetchGlobalPrompt());
    }

    /**
     * The migration applied to the real pre-fix catalog text must produce the
     * shipped catalog text byte-for-byte — and insert the block exactly once
     * (the anchors must not match anywhere else in the 31 KB prompt).
     */
    public function testUpOnTheRealCatalogPromptMatchesTheCatalog(): void
    {
        $new = $this->catalogPlanPrompt();
        $old = $this->reverseTransform($new);
        // Sanity: the reversal really removed the rule, so the test below is
        // not comparing the catalog with itself.
        self::assertStringNotContainsString('rule 3a wins', $old);

        $this->givenGlobalPrompt($old);
        $this->runUp();

        $result = $this->fetchGlobalPrompt();
        self::assertSame($new, $result);
        self::assertSame(1, substr_count($result, 'rule 3a wins'));
    }

    private function runUp(): void
    {
        // Direct-execution migration (executeStatement inside up(), no
        // deferred getSql() queue like the addSql-style ones).
        $this->loadMigration()->up(new Schema());
    }

    private function runDown(): void
    {
        $this->loadMigration()->down(new Schema());
    }

    /**
     * migrations/ is outside the composer autoload map — Doctrine discovers
     * these files by path at runtime, so the test has to load it itself.
     */
    private function loadMigration(): AbstractMigration
    {
        require_once dirname(__DIR__, 2).'/migrations/Version20260921010000.php';

        return new Version20260921010000($this->connection, new NullLogger());
    }

    private function migrationConstant(string $name): string
    {
        $this->loadMigration();

        return (string) (new \ReflectionClass(Version20260921010000::class))->getConstant($name);
    }

    /**
     * Reconstruct the pre-fix prompt from the shipped catalog text by
     * reversing the migration transform with the migration's own constants.
     */
    private function reverseTransform(string $new): string
    {
        $old = str_replace(
            $this->migrationConstant('NEW_RULE3'),
            $this->migrationConstant('ANCHOR_RULE3'),
            $new
        );

        return str_replace($this->migrationConstant('BLOCK_3A'), '', $old);
    }

    private function catalogPlanPrompt(): string
    {
        foreach (PromptCatalog::all() as $definition) {
            if ('tools:plan' === $definition['topic']) {
                return $definition['prompt'];
            }
        }

        self::fail('tools:plan template missing from PromptCatalog::all().');
    }

    /**
     * Minimal pre-fix prompt carrying both anchors verbatim (mirrors the
     * catalog text around rule 3 before rule 3a existed).
     */
    private function oldPrompt(): string
    {
        return "## Routing decisions (apply in order)\n"
            ."\n"
            .'3. Image generate/edit → `image_generation`. To EDIT an image produced by an'."\n"
            .'   earlier node ("make a logo, then make it blue"), add a second'."\n"
            .'   `image_generation` node that depends on the first and references its file:'."\n"
            .'   `"depends_on": ["n1"], "inputs": { "prompt": "make it blue", "image": "$n1.file" }`.'."\n"
            .'   The `image` input turns it into an image-to-image edit (PIC2PIC).'."\n"
            .'4. Video generate → `video_generation`.'."\n";
    }

    private function givenGlobalPrompt(string $prompt): void
    {
        $this->connection->executeStatement(
            "DELETE FROM BPROMPTS WHERE BTOPIC = 'tools:plan' AND BOWNERID = 0"
        );
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO BPROMPTS (BOWNERID, BLANG, BTOPIC, BSHORTDESC, BPROMPT)
                VALUES (0, 'en', 'tools:plan', 'fixture', :prompt)
                SQL,
            ['prompt' => $prompt]
        );
    }

    private function fetchGlobalPrompt(): string
    {
        return (string) $this->connection->fetchOne(
            "SELECT BPROMPT FROM BPROMPTS WHERE BTOPIC = 'tools:plan' AND BOWNERID = 0"
        );
    }
}
