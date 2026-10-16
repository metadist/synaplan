<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ModelDiscovery;

use App\Model\ModelCatalog;
use App\Model\ModelDiscoveryIgnoreList;
use App\Service\ModelDiscovery\ModelDiscoveryIdNormalizer;
use App\Service\ModelDiscovery\ModelFamilyClassifier;
use PHPUnit\Framework\TestCase;

final class ModelFamilyClassifierTest extends TestCase
{
    public function testClaudeOpus55IsNewVersionOfClaudeOpus(): void
    {
        // A version token after the known id is the next version, never a variant.
        $known = $this->keys(['claude-opus-5', 'claude-sonnet-5', 'claude-haiku-4-5-20251001']);
        $result = ModelFamilyClassifier::classify('claude-opus-5-5', $known, 'anthropic');

        $this->assertSame('New version of Claude Opus (known: claude-opus-5)', $result['label']);
    }

    public function testNameSuffixAfterKnownIdIsVariant(): void
    {
        $known = $this->keys(['gpt-4o-mini', 'gpt-5.4']);
        $result = ModelFamilyClassifier::classify('gpt-4o-mini-tts', $known, 'openai');

        $this->assertSame('New variant of gpt-4o-mini (known: gpt-4o-mini)', $result['label']);
    }

    public function testClaudeFable5IsNewLineInClaude5(): void
    {
        $known = $this->keys(['claude-opus-5', 'claude-sonnet-5']);
        $result = ModelFamilyClassifier::classify('claude-fable-5', $known, 'anthropic');

        $this->assertStringStartsWith('New line in Claude 5', $result['label']);
        $this->assertStringContainsString('known:', $result['label']);
    }

    public function testGpt6SolIsNewVersionWhenGenerationKnownViaAstra(): void
    {
        $known = $this->keys(['gpt-6-astra', 'gpt-5.6-sol', 'gpt-5.5']);
        $result = ModelFamilyClassifier::classify('gpt-6-sol', $known, 'openai');

        $this->assertSame('New version of GPT Sol (known: gpt-5.6-sol)', $result['label']);
    }

    public function testGpt6AstraIsNewFamilyWhenLineAndGenerationNew(): void
    {
        $known = $this->keys(['gpt-5.6-sol', 'gpt-5.5']);
        $result = ModelFamilyClassifier::classify('gpt-6-astra', $known, 'openai');

        $this->assertSame('New family', $result['label']);
    }

    public function testGemini4FlashIsNewGeneration(): void
    {
        $known = $this->keys(['gemini-3.5-flash', 'gemini-3.1-pro']);
        $result = ModelFamilyClassifier::classify('gemini-4-flash', $known, 'google');

        $this->assertSame(
            'New generation Gemini 4 of Gemini Flash (known: gemini-3.5-flash)',
            $result['label'],
        );
    }

    public function testOSeriesSplitsLetterDigitFirstToken(): void
    {
        $known = $this->keys(['o3', 'o4-mini']);
        $result = ModelFamilyClassifier::classify('o5-mini', $known, 'openai');

        $this->assertStringStartsWith('New generation O 5 of O Mini', $result['label']);
        $this->assertNotSame('New family', $result['label']);
    }

    public function testSlashSeparatedGroqOssIsNewVersion(): void
    {
        $known = $this->keys(['openai/gpt-oss-20b']);
        $result = ModelFamilyClassifier::classify('openai/gpt-oss-120b', $known, 'groq');

        $this->assertSame(
            'New version of Openai GPT Oss (known: openai/gpt-oss-20b)',
            $result['label'],
        );
    }

    public function testTrailingLatestSharesLineForNewVersion(): void
    {
        $known = $this->keys(['mistral-medium-latest', 'mistral-large-latest']);
        $result = ModelFamilyClassifier::classify('mistral-medium-3.5', $known, 'mistral');

        $this->assertSame(
            'New version of Mistral Medium (known: mistral-medium-latest)',
            $result['label'],
        );
    }

    public function testClassRulesDoNotMatchAnyCatalogModel(): void
    {
        foreach (ModelCatalog::all() as $row) {
            $provider = ModelCatalog::normalizeProvider($row['service']);
            $ids = [$row['providerId']];
            $paramsModel = $row['json']['params']['model'] ?? null;
            if (is_string($paramsModel) && '' !== $paramsModel) {
                $ids[] = $paramsModel;
            }
            foreach ($ids as $id) {
                $rule = ModelDiscoveryIgnoreList::matchingClassRule($provider, $id);
                $this->assertNull(
                    $rule,
                    sprintf(
                        'Class rule must not silence catalog model %s (%s / %s)',
                        $row['id'] ?? '?',
                        $provider,
                        $id,
                    ),
                );
            }
        }
    }

    public function testFixtureNoFalseNewFamilyWhenVendorMajorIsKnown(): void
    {
        $fixture = $this->fixture();
        foreach ($fixture['listed'] as $provider => $ids) {
            $known = $this->keys($fixture['known'][$provider] ?? []);
            $knownMajorsByVendor = $this->majorsByVendor($fixture['known'][$provider] ?? [], $provider);

            foreach ($ids as $id) {
                if (ModelDiscoveryIdNormalizer::isKnown($id, $known)) {
                    continue;
                }
                if (null !== ModelDiscoveryIgnoreList::matchingClassRule($provider, $id)) {
                    continue;
                }

                $result = ModelFamilyClassifier::classify($id, $known, $provider);
                if (!str_starts_with($result['label'], 'New family')) {
                    continue;
                }

                $analysed = ModelFamilyClassifier::analyse($id, $provider);
                $vendor = $analysed['nameTokens'][0] ?? '';
                $version = $analysed['versionForGeneration'];
                if (null === $version || '' === $vendor) {
                    continue;
                }
                if (1 !== preg_match('/^(\\d+)/', $version, $m)) {
                    continue;
                }
                $major = (int) $m[1];
                $this->assertFalse(
                    isset($knownMajorsByVendor[$vendor][$major]),
                    sprintf(
                        '%s:%s labelled New family but vendor %s major %d is known',
                        $provider,
                        $id,
                        $vendor,
                        $major,
                    ),
                );
            }
        }
    }

    public function testFixturePinnedLabelPrefixes(): void
    {
        $fixture = $this->fixture();
        $expected = [
            'openai:gpt-5.1-codex' => 'New line in GPT-5',
            'openai:gpt-5-mini' => 'New version of GPT Mini',
            'openai:gpt-4o-mini-tts' => 'New variant of gpt-4o-mini',
            'openai:gpt-image-1-mini' => 'New variant of gpt-image-1',
            'openai:gpt-image-2' => 'New version of GPT Image',
            'openai:gpt-audio-1.5' => 'New family',
            'openai:sora-2' => 'New family',
            'openai:o3' => 'New family',
            'xai:grok-4.20-0309-reasoning' => 'New line in Grok-4',
            'xai:grok-4.3' => 'New version of Grok',
            'anthropic:claude-opus-4-7' => 'New version of Claude Opus',
            'google:gemini-3.8-flash-tts' => 'New variant of gemini-3.8-flash',
            'google:gemini-embedding-001' => 'New family',
            'google:gemma-4-31b-it' => 'New family',
        ];

        foreach ($expected as $key => $prefix) {
            [$provider, $id] = explode(':', $key, 2);
            $known = $this->keys($fixture['known'][$provider] ?? []);
            $label = ModelFamilyClassifier::classify($id, $known, $provider)['label'];
            $this->assertStringStartsWith(
                $prefix,
                $label,
                sprintf('%s got "%s"', $key, $label),
            );
        }
    }

    /**
     * @return array{known: array<string, list<string>>, listed: array<string, list<string>>}
     */
    private function fixture(): array
    {
        $path = dirname(__DIR__, 3).'/Fixtures/ModelDiscovery/listings-2026-09-25.json';
        $decoded = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, array<int, true>>
     */
    private function majorsByVendor(array $ids, string $provider): array
    {
        $out = [];
        foreach ($ids as $id) {
            $analysed = ModelFamilyClassifier::analyse($id, $provider);
            $vendor = $analysed['nameTokens'][0] ?? '';
            $version = $analysed['versionForGeneration'];
            if ('' === $vendor || null === $version) {
                continue;
            }
            if (1 !== preg_match('/^(\\d+)/', $version, $m)) {
                continue;
            }
            $out[$vendor][(int) $m[1]] = true;
        }

        return $out;
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, true>
     */
    private function keys(array $ids): array
    {
        $known = [];
        foreach ($ids as $id) {
            $known[ModelDiscoveryIdNormalizer::normalize($id)] = true;
        }

        return $known;
    }
}
