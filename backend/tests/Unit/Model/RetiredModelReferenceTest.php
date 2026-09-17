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
 * Scoped deliberately to *default* positions (`??`, `?:`, `return`). A retired id
 * is legitimate almost everywhere else and banning it outright would be a lie:
 * `str_starts_with($model, 'gpt-5')` is a family check, AnthropicProvider's
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

    public function testNoRetiredModelIdIsUsedAsADefault(): void
    {
        $retiredIds = self::retiredProviderIds();
        self::assertNotEmpty($retiredIds, 'The retirement registry is empty, so this guard would pass vacuously.');

        $offenders = [];

        foreach (self::sourceFiles() as $relativePath => $absolutePath) {
            foreach (self::defaultValueLiterals($absolutePath) as [$line, $literal]) {
                foreach ($retiredIds as $retiredId) {
                    if (self::namesTheId($literal, $retiredId)) {
                        $offenders[] = sprintf('%s:%d — falls back to retired model %s', $relativePath, $line, $retiredId);
                    }
                }
            }
        }

        self::assertSame([], $offenders, sprintf(
            "Retired model id(s) are used as a fallback default:\n  %s\n"
            ."The catalog no longer offers them, so this path either hits a provider 404 or bills a\n"
            .'model with no price row. Point the default at the successor the registry records.',
            implode("\n  ", $offenders),
        ));
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
     * String literals that stand in a default position: the right-hand side of
     * `??` or `?:`, or a `return`ed constant.
     *
     * `?:` is two separate tokens, and a bare `:` is far too common to key on —
     * it also ends a named argument, a match arm and a ternary — so the elvis
     * case is recognised from the `?` in front of it.
     *
     * @return list<array{int, string}>
     */
    private static function defaultValueLiterals(string $path): array
    {
        $significant = [];
        $literals = [];

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (\is_array($token) && \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            if (\is_array($token) && \T_CONSTANT_ENCAPSED_STRING === $token[0] && self::isDefaultPosition($significant)) {
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
    private static function isDefaultPosition(array $significant): bool
    {
        $previous = end($significant);

        if (\T_COALESCE === $previous || \T_RETURN === $previous) {
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
