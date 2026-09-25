<?php

declare(strict_types=1);

namespace App\Service\ModelDiscovery;

/**
 * Labels a newly listed model id relative to known BMODELS ids (label only —
 * never filters discovery).
 *
 * Tokenisation (on the undated {@see ModelDiscoveryIdNormalizer::normalize()}
 * form, split on `-` / `/` / `:` / `_`):
 * - Drop non-content tokens before classifying: `latest`, `preview`, `exp`;
 *   snapshot/revision `/^0\d+$/` (001, 0309, 0613); and pure 4-digit tokens
 *   `/^\d{4}$/` that follow an earlier version token (1106, 2026). This is
 *   classifier-local — {@see ModelDiscoveryIdNormalizer} is unchanged.
 * - A token is a **version** when it starts with a digit (`5`, `5.6`, `4o`,
 *   `120b`). All other remaining tokens are **name** tokens.
 * - **O-series:** first token matching `/^[a-z]\d/i` (e.g. `o5`) splits into
 *   name `o` + version `5`.
 *
 * `line` = name tokens joined with `-`.
 *
 * **Generation** (vendor + version) is set only when:
 * - the second stream token is a version (`gpt-6-sol`, `gemini-3.5-flash`,
 *   `grok-4.7`, `o5-mini`), OR
 * - `$provider` is `anthropic` (generation trails the line: `claude-opus-5-5`
 *   → `claude 5`);
 * otherwise the version belongs to the line (`gpt-image-1`, `tts-1`) and
 * generation is null.
 *
 * A generation is **known** when a known id shares the same vendor token and
 * either the exact generation or the same **major** (leading integer of the
 * version token: `5.2`⇒`5`, `4o`⇒`4`, `4.20`⇒`4`). Label text uses the level
 * that matched (`New line in GPT-5` for major-only; spaced exact form when
 * exact).
 *
 * Labels (checked in order):
 * - known undated id K is a segment prefix of the listed undated id →
 *   `New variant of <K>` (longest K)
 * - line known (generation null / exact / major / or trailing-only version) →
 *   `New version of <Line>`
 * - line known, generation major-new → `New generation <Gen> of <Line>`
 * - line new, generation exact/major known → `New line in <Gen>`
 * - otherwise → `New family`
 */
final class ModelFamilyClassifier
{
    private const LABEL_PRIORITY = [
        'New family' => 0,
        'New generation' => 1,
        'New line' => 2,
        'New version' => 3,
        'New variant' => 4,
    ];

    /**
     * @param array<string, true> $knownKeys normalised BPROVID / params.model keys
     *
     * @return array{label: string, sortKey: int, line: string, generation: ?string}
     */
    public static function classify(string $listedId, array $knownKeys, string $provider = ''): array
    {
        $provider = strtolower(trim($provider));
        $tokens = self::analyse($listedId, $provider);

        $variantOf = self::longestVariantPrefix($listedId, $knownKeys);
        if (null !== $variantOf) {
            $label = 'New variant of '.$variantOf.' (known: '.$variantOf.')';

            return [
                'label' => $label,
                'sortKey' => self::LABEL_PRIORITY['New variant'],
                'line' => $tokens['line'],
                'generation' => $tokens['generation'],
            ];
        }

        $known = [];
        foreach (array_keys($knownKeys) as $exact) {
            $known[] = self::analyse($exact, $provider);
        }

        $lineKnown = false;
        $knownOfLine = [];
        $lineHasOnlyNullGeneration = true;
        $sawLine = false;
        $exactGenerationKnown = false;
        $majorGenerationKnown = false;
        $knownOfExactGeneration = [];
        $knownOfMajorGeneration = [];
        $matchedMajor = null;

        $vendor = $tokens['nameTokens'][0] ?? '';
        $listedMajor = null !== $tokens['versionForGeneration']
            ? self::majorOf($tokens['versionForGeneration'])
            : null;

        foreach ($known as $entry) {
            if ($entry['line'] === $tokens['line'] && '' !== $tokens['line']) {
                $lineKnown = true;
                $sawLine = true;
                $knownOfLine[] = $entry['id'];
                if (null !== $entry['generation']) {
                    $lineHasOnlyNullGeneration = false;
                }
            }

            $knownVendor = $entry['nameTokens'][0] ?? '';
            if ('' === $vendor || $knownVendor !== $vendor) {
                continue;
            }

            if (null !== $tokens['generation']
                && $entry['generation'] === $tokens['generation']) {
                $exactGenerationKnown = true;
                $knownOfExactGeneration[] = $entry['id'];
            }

            if (null !== $listedMajor
                && null !== $entry['versionForGeneration']
                && self::majorOf($entry['versionForGeneration']) === $listedMajor) {
                $majorGenerationKnown = true;
                $matchedMajor = $listedMajor;
                $knownOfMajorGeneration[] = $entry['id'];
            }
        }

        if (!$sawLine) {
            $lineHasOnlyNullGeneration = false;
        }

        $generationKnown = $exactGenerationKnown || $majorGenerationKnown;
        $lineDisplay = self::displayLine($tokens['nameTokens']);
        $exactGenDisplay = null === $tokens['generation']
            ? null
            : self::displayExactGeneration($vendor, $tokens['versionForGeneration'] ?? '');
        $majorGenDisplay = null !== $matchedMajor
            ? self::displayMajorGeneration($vendor, $matchedMajor)
            : (null !== $listedMajor ? self::displayMajorGeneration($vendor, $listedMajor) : null);

        if ($lineKnown && (
            null === $tokens['generation']
            || $generationKnown
            || $lineHasOnlyNullGeneration
        )) {
            $label = 'New version of '.$lineDisplay.self::knownSuffix($knownOfLine);

            return [
                'label' => $label,
                'sortKey' => self::LABEL_PRIORITY['New version'],
                'line' => $tokens['line'],
                'generation' => $tokens['generation'],
            ];
        }

        if ($lineKnown && null !== $exactGenDisplay) {
            $label = 'New generation '.$exactGenDisplay.' of '.$lineDisplay.self::knownSuffix($knownOfLine);

            return [
                'label' => $label,
                'sortKey' => self::LABEL_PRIORITY['New generation'],
                'line' => $tokens['line'],
                'generation' => $tokens['generation'],
            ];
        }

        if (!$lineKnown && $generationKnown && null !== $tokens['generation']) {
            if ($exactGenerationKnown && null !== $exactGenDisplay) {
                $label = 'New line in '.$exactGenDisplay.self::knownSuffix($knownOfExactGeneration);
            } else {
                $label = 'New line in '.($majorGenDisplay ?? $exactGenDisplay ?? 'Unknown')
                    .self::knownSuffix($knownOfMajorGeneration);
            }

            return [
                'label' => $label,
                'sortKey' => self::LABEL_PRIORITY['New line'],
                'line' => $tokens['line'],
                'generation' => $tokens['generation'],
            ];
        }

        return [
            'label' => 'New family',
            'sortKey' => self::LABEL_PRIORITY['New family'],
            'line' => $tokens['line'],
            'generation' => $tokens['generation'],
        ];
    }

    /**
     * @return array{
     *     id: string,
     *     nameTokens: list<string>,
     *     versionTokens: list<string>,
     *     line: string,
     *     generation: ?string,
     *     versionForGeneration: ?string,
     *     hasInfixVersion: bool
     * }
     */
    public static function analyse(string $id, string $provider = ''): array
    {
        $provider = strtolower(trim($provider));
        $normalized = ModelDiscoveryIdNormalizer::undated(ModelDiscoveryIdNormalizer::normalize($id));
        $raw = preg_split('/[-\\/:_]+/', $normalized, -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        /** @var list<array{kind: 'name'|'version', value: string}> $stream */
        $stream = [];
        $sawVersion = false;
        $first = true;
        foreach ($raw as $token) {
            if (self::shouldDropToken($token, $sawVersion)) {
                continue;
            }

            if ($first && 1 === preg_match('/^[a-z]\\d/i', $token)) {
                $stream[] = ['kind' => 'name', 'value' => strtolower($token[0])];
                $stream[] = ['kind' => 'version', 'value' => strtolower(substr($token, 1))];
                $sawVersion = true;
                $first = false;
                continue;
            }
            $first = false;

            if (1 === preg_match('/^\\d/', $token)) {
                $stream[] = ['kind' => 'version', 'value' => $token];
                $sawVersion = true;
            } else {
                $stream[] = ['kind' => 'name', 'value' => $token];
            }
        }

        $nameTokens = [];
        $versionTokens = [];
        $hasInfixVersion = false;
        $sawVersionInStream = false;
        foreach ($stream as $part) {
            if ('version' === $part['kind']) {
                $versionTokens[] = $part['value'];
                $sawVersionInStream = true;
            } else {
                if ($sawVersionInStream) {
                    $hasInfixVersion = true;
                }
                $nameTokens[] = $part['value'];
            }
        }

        $versionForGeneration = null;
        if (isset($stream[0], $stream[1])
            && 'name' === $stream[0]['kind']
            && 'version' === $stream[1]['kind']) {
            $versionForGeneration = $stream[1]['value'];
        } elseif ('anthropic' === $provider && [] !== $versionTokens && [] !== $nameTokens) {
            $versionForGeneration = $versionTokens[0];
        }

        $line = implode('-', $nameTokens);
        $generation = null;
        if (null !== $versionForGeneration && [] !== $nameTokens) {
            $generation = $nameTokens[0].' '.$versionForGeneration;
        }

        return [
            'id' => $id,
            'nameTokens' => $nameTokens,
            'versionTokens' => $versionTokens,
            'line' => $line,
            'generation' => $generation,
            'versionForGeneration' => $versionForGeneration,
            'hasInfixVersion' => $hasInfixVersion,
        ];
    }

    /**
     * @param array<string, true> $knownKeys
     */
    private static function longestVariantPrefix(string $listedId, array $knownKeys): ?string
    {
        $listedUndated = ModelDiscoveryIdNormalizer::undated(ModelDiscoveryIdNormalizer::normalize($listedId));
        $best = null;
        $bestLen = 0;
        foreach (array_keys($knownKeys) as $exact) {
            $candidate = ModelDiscoveryIdNormalizer::undated($exact);
            if ('' === $candidate || $candidate === $listedUndated) {
                continue;
            }
            if (!str_starts_with($listedUndated, $candidate.'-')) {
                continue;
            }
            // `claude-opus-5` + `-5` is the next version, not a variant of it.
            $firstSuffixToken = strtok(substr($listedUndated, strlen($candidate) + 1), '-/:_');
            if (false === $firstSuffixToken || 1 === preg_match('/^\d/', $firstSuffixToken)) {
                continue;
            }
            $len = strlen($candidate);
            if ($len > $bestLen) {
                $best = $candidate;
                $bestLen = $len;
            }
        }

        return $best;
    }

    private static function shouldDropToken(string $token, bool $sawVersion): bool
    {
        if (in_array($token, ['latest', 'preview', 'exp'], true)) {
            return true;
        }
        if (1 === preg_match('/^0\\d+$/', $token)) {
            return true;
        }
        if ($sawVersion && 1 === preg_match('/^\\d{4}$/', $token)) {
            return true;
        }

        return false;
    }

    private static function majorOf(string $versionToken): ?int
    {
        if (1 !== preg_match('/^(\\d+)/', $versionToken, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * @param list<string> $ids
     */
    private static function knownSuffix(array $ids): string
    {
        $ids = array_values(array_unique($ids));
        if ([] === $ids) {
            return '';
        }

        return ' (known: '.implode(', ', array_slice($ids, 0, 2)).')';
    }

    /**
     * @param list<string> $nameTokens
     */
    private static function displayLine(array $nameTokens): string
    {
        if ([] === $nameTokens) {
            return 'Unknown';
        }

        return implode(' ', array_map(self::displayToken(...), $nameTokens));
    }

    private static function displayExactGeneration(string $vendor, string $version): string
    {
        return trim(self::displayToken($vendor).' '.$version);
    }

    private static function displayMajorGeneration(string $vendor, int $major): string
    {
        return self::displayToken($vendor).'-'.$major;
    }

    private static function displayToken(string $token): string
    {
        if ('gpt' === strtolower($token)) {
            return 'GPT';
        }

        if (1 === preg_match('/^\\d/', $token)) {
            return $token;
        }

        return ucfirst(strtolower($token));
    }
}
