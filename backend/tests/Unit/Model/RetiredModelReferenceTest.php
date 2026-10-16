<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\ModelCatalog;
use PHPUnit\Framework\TestCase;

/**
 * A retired model id must never be what the code falls back to.
 *
 * The registry ({@see ModelCatalog}::RETIREMENTS) switches the catalog row off and
 * ModelRetirementSeeder switches the database row off, but neither can see an id
 * another class spelled out itself. `GoogleProvider::generateImage()` did exactly
 * that — `$options['model'] ?? 'imagen-4.0-generate-001'` — and kept naming an
 * endpoint Google shut down on 2026-08-17 whenever a caller left the model unset.
 * Nothing noticed for a month, because the id only appears on the path where the
 * caller forgot to pass one.
 *
 * Scoped deliberately to the positions that *choose* a model — assigned (`=`,
 * `??=`), defaulted (`??`, `?:`) or returned. Thirteen provider classes keep a
 * `DEFAULT_CHAT_MODEL` / `DEFAULT_VISION_MODEL` constant, and one of them named a
 * dead model once already: `GroqProvider::DEFAULT_VISION_MODEL` still pointed at
 * `meta-llama/llama-4-scout-17b-16e-instruct` when Groq dropped it, and a person
 * had to notice (#1513).
 *
 * A retired id is legitimate almost everywhere else and banning it outright would
 * be a lie: `str_starts_with($model, 'gpt-5')` is a family check, AnthropicProvider's
 * SUPPORTED_MODELS list is the compatibility surface for ids Claude Code sends us,
 * and an OpenAPI `example:` is documentation. What none of those do is decide, on
 * their own, which model a request goes to.
 */
final class RetiredModelReferenceTest extends TestCase
{
    /**
     * The catalog holds the retired rows and the registry itself, so it is the
     * one file that must name them.
     *
     * @var list<string>
     */
    private const OWNS_RETIRED_IDS = [
        'Model/ModelCatalog.php',
    ];

    public function testNoRetiredModelIdIsChosenAsAModel(): void
    {
        $retiredIds = self::retiredProviderIds();
        self::assertNotEmpty($retiredIds, 'The retirement registry is empty, so this guard would pass vacuously.');

        $offenders = [];

        foreach (self::sourceFiles() as $relativePath => $absolutePath) {
            foreach (self::chosenModelLiterals((string) file_get_contents($absolutePath)) as [$line, $literal]) {
                foreach ($retiredIds as $retiredId) {
                    if (self::namesTheId($literal, $retiredId)) {
                        $offenders[] = sprintf('%s:%d — picks retired model %s', $relativePath, $line, $retiredId);
                    }
                }
            }
        }

        self::assertSame([], $offenders, sprintf(
            "Retired model id(s) are assigned, defaulted or returned as a model:\n  %s\n"
            ."The catalog no longer offers them, so this path either hits a provider 404 or bills a\n"
            .'model with no price row. Point it at the successor the registry records.',
            implode("\n  ", $offenders),
        ));
    }

    /**
     * A guard nobody has seen fire is a guard nobody can trust: green above only
     * means no source file matched, not that a match would be found. This pins
     * both halves of the rule on a snippet holding every shape at once.
     */
    public function testTheGuardSeparatesChoosingAModelFromMentioningOne(): void
    {
        $found = array_column(self::chosenModelLiterals(<<<'PHP'
            <?php
            const DEFAULT_MODEL = 'chosen-by-const';
            final class Sample
            {
                private const FAMILY = ['mentioned-in-a-list', 'also-mentioned'];

                public function pick(array $options, string $fallback = 'chosen-by-parameter'): string
                {
                    $model = $options['model'] ?? 'chosen-by-coalesce';
                    $other = $options['other'] ?: 'chosen-by-elvis';
                    $map = ['model' => 'mentioned-as-array-value'];

                    if ($model === 'mentioned-in-a-comparison') {
                        return 'chosen-by-return';
                    }

                    return str_starts_with($model, 'mentioned-as-an-argument') ? $other : $fallback;
                }
            }
            PHP), 1);

        sort($found);

        self::assertSame([
            'chosen-by-coalesce',
            'chosen-by-const',
            'chosen-by-elvis',
            'chosen-by-parameter',
            'chosen-by-return',
        ], $found);
    }

    /**
     * @return list<string>
     */
    private static function retiredProviderIds(): array
    {
        $ids = [];
        foreach (ModelCatalog::retirements() as $record) {
            $ids[$record['providerId']] = true;
        }

        return array_keys($ids);
    }

    /**
     * @return array<string, string> relative path => absolute path
     */
    private static function sourceFiles(): array
    {
        $root = \dirname(__DIR__, 3).'/src';

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        $files = [];
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }

            $relativePath = substr($file->getPathname(), \strlen($root) + 1);
            if (\in_array($relativePath, self::OWNS_RETIRED_IDS, true)) {
                continue;
            }

            $files[$relativePath] = $file->getPathname();
        }

        ksort($files);

        return $files;
    }

    /**
     * String literals that stand where a model gets chosen: assigned (`=`, `??=`,
     * which also covers a `const` and a parameter default), or the right-hand side
     * of `??` / `?:`, or returned.
     *
     * `=>` is its own token, so array elements and keys stay out — that is what
     * keeps a capability list from being read as a choice. Comparisons are their
     * own tokens too, so `$model === 'gpt-5'` is not a choice either.
     *
     * `?:` is two separate tokens, and a bare `:` is far too common to key on — it
     * also ends a named argument, a match arm and a ternary — so the elvis case is
     * recognised from the `?` in front of it.
     *
     * @return list<array{int, string}>
     */
    private static function chosenModelLiterals(string $code): array
    {
        $significant = [];
        $literals = [];

        foreach (token_get_all($code) as $token) {
            if (\is_array($token) && \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            if (\is_array($token) && \T_CONSTANT_ENCAPSED_STRING === $token[0] && self::isChoicePosition($significant)) {
                $literals[] = [$token[2], trim($token[1], "'\"")];
            }

            $significant[] = \is_array($token) ? $token[0] : $token;
            if (\count($significant) > 2) {
                array_shift($significant);
            }
        }

        return $literals;
    }

    /**
     * @param list<int|string> $significant the last two significant tokens, oldest first
     */
    private static function isChoicePosition(array $significant): bool
    {
        $previous = end($significant);

        if ('=' === $previous || \T_COALESCE === $previous || \T_COALESCE_EQUAL === $previous || \T_RETURN === $previous) {
            return true;
        }

        return ':' === $previous && '?' === prev($significant);
    }

    /**
     * Whether a literal names the id as an id rather than as part of a longer
     * one. Without the boundaries, retired `gpt-5` would match live `gpt-5.5`
     * and retired `…DeepSeek-V4-Flash` would match live `…-Flash-0731`.
     */
    private static function namesTheId(string $literal, string $retiredId): bool
    {
        return 1 === preg_match(
            '/(?<![\w.\-\/])'.preg_quote($retiredId, '/').'(?![\w.\-\/])/',
            $literal,
        );
    }
}
