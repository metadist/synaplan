<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Service\Research\Run\ResearchOutcome;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ResearchOutcomeTranslationsTest extends TestCase
{
    private const ROLES = ['reading', 'sorting', 'planning', 'writing', 'fact-checking', 'selected'];

    /**
     * @return iterable<string, array{string}>
     */
    public static function locales(): iterable
    {
        foreach (['en', 'de', 'es', 'fr', 'tr'] as $locale) {
            yield $locale => [$locale];
        }
    }

    #[DataProvider('locales')]
    public function testEveryOutcomeHasItsSentenceWithTheSamePlaceholders(string $locale): void
    {
        $english = self::load('en');
        $translated = self::load($locale);

        foreach ([...ResearchOutcome::CODES, 'unknown'] as $code) {
            self::assertArrayHasKey($code, $translated['outcome'], $locale.' misses '.$code);
            self::assertSame(
                self::placeholders($english['outcome'][$code]),
                self::placeholders($translated['outcome'][$code]),
                $locale.' changes the placeholders of '.$code,
            );
        }
        foreach (self::ROLES as $role) {
            self::assertArrayHasKey($role, $translated['role'], $locale.' misses the role '.$role);
        }
    }

    /**
     * @return array{outcome: array<string, string>, role: array<string, string>}
     */
    private static function load(string $locale): array
    {
        $parsed = Yaml::parseFile(dirname(__DIR__, 5).'/translations/research.'.$locale.'.yaml');
        self::assertIsArray($parsed);

        /** @var array{outcome: array<string, string>, role: array<string, string>} $research */
        $research = $parsed['research'];

        return $research;
    }

    /**
     * @return list<string>
     */
    private static function placeholders(string $sentence): array
    {
        preg_match_all('/%[a-z_]+%/', $sentence, $matches);
        $found = $matches[0];
        sort($found);

        return $found;
    }
}
