<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SmartSearch;

use App\Service\SmartSearch\Provider\SettingLabel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SettingLabelTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function labels(): iterable
    {
        yield 'head before a colon' => ['FEATURE_IAM_GROUPS_ENABLED', 'People & groups: show People under Operate.', 'People & groups'];
        yield 'first sentence' => ['DIGEST_ENABLED', 'Deep memory master switch. A daily job condenses messages.', 'Deep memory master switch'];
        yield 'range in brackets' => ['TOOLS_APPROVAL_EXPIRY_HOURS', 'Hours a pending approval waits before it expires (1–720). Default 72.', 'Hours a pending approval waits before it expires'];
        yield 'dash' => ['GOOGLE_GEMINI_API_KEY', 'Google Gemini API key — also unlocks Imagen.', 'Google Gemini API key'];
        yield 'whole short description' => ['OLLAMA_BASE_URL', 'Ollama server URL', 'Ollama server URL'];
        yield 'long head is cut at a word' => [
            'REGISTRATION_ENABLED',
            'Allow visitors to create their own account with email and password without an invitation',
            'Allow visitors to create their own account with email and…',
        ];
        yield 'no description' => ['MIN_EXTRACTION_SCORE', '', 'Min extraction score'];
        yield 'feature prefix dropped' => ['FEATURE_BUNDLE_ENABLED', ' ', 'Bundle enabled'];
    }

    #[DataProvider('labels')]
    public function testLabelComesFromTheDescriptionHead(string $key, string $description, string $expected): void
    {
        self::assertSame($expected, SettingLabel::of($key, $description));
    }
}
