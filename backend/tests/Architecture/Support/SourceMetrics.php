<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Support;

/**
 * The three size numbers of a PHP source file, read from the token stream.
 *
 * - lines of the file
 * - parameters of every constructor (one per class in the file)
 * - lines of every function or method body, from the `function` keyword to
 *   the closing brace
 *
 * Pure PHP (`PhpToken`), no dependency. Used by {@see \App\Tests\Architecture\SizeRatchetTest}
 * and by `make metrics`. A closure is not a method and is not counted;
 * an abstract or interface method has no body and is not counted.
 */
final class SourceMetrics
{
    /** Scope name for `new class { … }`; its methods are not the enclosing class's. */
    public const ANONYMOUS = '{anonymous}';

    /**
     * @param array<string, int> $constructorParams "Class" => parameter count
     * @param array<string, int> $methodLines       "Class::method" => lines; "::function" for a free function
     */
    private function __construct(
        public readonly int $lines,
        public readonly array $constructorParams,
        public readonly array $methodLines,
    ) {
    }

    public static function ofFile(string $path): self
    {
        $code = file_get_contents($path);
        if (false === $code) {
            throw new \RuntimeException("Cannot read {$path}");
        }

        return self::ofCode($code);
    }

    public static function ofCode(string $code): self
    {
        $tokens = \PhpToken::tokenize($code);
        $count = count($tokens);

        $constructorParams = [];
        $methodLines = [];
        $depth = 0;
        /** @var list<array{0: string, 1: int}> $classStack name and the brace depth outside the class body */
        $classStack = [];
        $currentClass = '';

        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];

            if ($token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])) {
                $previous = self::previousMeaningful($tokens, $i);
                if (null !== $previous && $previous->is(T_DOUBLE_COLON)) {
                    continue; // Foo::class
                }
                if (null !== $previous && $previous->is(T_NEW)) {
                    $classStack[] = [self::ANONYMOUS, $depth];
                    $currentClass = self::ANONYMOUS;
                    continue;
                }
                $name = self::nextMeaningful($tokens, $i);
                if (null !== $name && $name->is(T_STRING)) {
                    $classStack[] = [$name->text, $depth];
                    $currentClass = $name->text;
                }
                continue;
            }

            if ($token->is('{') || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                ++$depth;
                continue;
            }
            if ($token->is('}')) {
                --$depth;
                if ([] !== $classStack && $depth === $classStack[count($classStack) - 1][1]) {
                    array_pop($classStack);
                    $currentClass = [] === $classStack ? '' : $classStack[count($classStack) - 1][0];
                }
                continue;
            }

            if (!$token->is(T_FUNCTION)) {
                continue;
            }

            $nameToken = self::nextMeaningful($tokens, $i);
            if (null === $nameToken || !$nameToken->is(T_STRING)) {
                continue; // closure: `function (` or `function &(`
            }
            $method = $nameToken->text;
            $startLine = $token->line;

            // Parameter list.
            $open = self::indexOfNext($tokens, $i, '(');
            if (null === $open) {
                continue;
            }
            [$close, $params] = self::countTopLevelParameters($tokens, $open);

            if ('__construct' === $method) {
                $constructorParams[$currentClass] = $params;
            }

            // Body: the first `{` or `;` after the parameter list (return
            // types and attributes cannot contain braces).
            $bodyStart = null;
            for ($j = $close + 1; $j < $count; ++$j) {
                if ($tokens[$j]->is('{')) {
                    $bodyStart = $j;
                    break;
                }
                if ($tokens[$j]->is(';')) {
                    break;
                }
            }
            if (null === $bodyStart) {
                continue; // abstract / interface method
            }

            $bodyDepth = 0;
            $endLine = $tokens[$bodyStart]->line;
            for ($j = $bodyStart; $j < $count; ++$j) {
                $t = $tokens[$j];
                if ($t->is('{') || $t->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                    ++$bodyDepth;
                } elseif ($t->is('}')) {
                    --$bodyDepth;
                    if (0 === $bodyDepth) {
                        $endLine = $t->line;
                        break;
                    }
                }
            }

            $methodLines[$currentClass.'::'.$method] = $endLine - $startLine + 1;
        }

        return new self(substr_count($code, "\n") + ('' === $code || str_ends_with($code, "\n") ? 0 : 1), $constructorParams, $methodLines);
    }

    /**
     * @param list<\PhpToken> $tokens
     *
     * @return array{0: int, 1: int} index of the closing `)` and the parameter count
     */
    private static function countTopLevelParameters(array $tokens, int $open): array
    {
        $count = count($tokens);
        $depth = 0;
        $commas = 0;
        $sawParameter = false;
        $lastMeaningfulWasComma = false;

        for ($j = $open; $j < $count; ++$j) {
            $t = $tokens[$j];
            if ($t->is(['(', '[', '{']) || $t->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE])) {
                ++$depth;
                if ($j !== $open) {
                    $sawParameter = true;
                    $lastMeaningfulWasComma = false;
                }
                continue;
            }
            if ($t->is([')', ']', '}'])) {
                --$depth;
                if (0 === $depth) {
                    $params = $sawParameter ? $commas + 1 - ($lastMeaningfulWasComma ? 1 : 0) : 0;

                    return [$j, $params];
                }
                $lastMeaningfulWasComma = false;
                continue;
            }
            if ($t->isIgnorable()) {
                continue;
            }
            if (1 === $depth && $t->is(',')) {
                ++$commas;
                $lastMeaningfulWasComma = true;
                continue;
            }
            $sawParameter = true;
            $lastMeaningfulWasComma = false;
        }

        return [$count - 1, $sawParameter ? $commas + 1 : 0];
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private static function nextMeaningful(array $tokens, int $from): ?\PhpToken
    {
        $count = count($tokens);
        for ($j = $from + 1; $j < $count; ++$j) {
            if (!$tokens[$j]->isIgnorable()) {
                return $tokens[$j];
            }
        }

        return null;
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private static function previousMeaningful(array $tokens, int $from): ?\PhpToken
    {
        for ($j = $from - 1; $j >= 0; --$j) {
            if (!$tokens[$j]->isIgnorable()) {
                return $tokens[$j];
            }
        }

        return null;
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private static function indexOfNext(array $tokens, int $from, string $text): ?int
    {
        $count = count($tokens);
        for ($j = $from + 1; $j < $count; ++$j) {
            if ($tokens[$j]->is($text)) {
                return $j;
            }
            if ($tokens[$j]->is(['{', ';'])) {
                return null;
            }
        }

        return null;
    }
}
