<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\WebSpeechConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * WEB_SPEECH_ENABLED env parsing (default ON, explicit falsey OFF), mirroring
 * {@see GuestChatConfigTest}: air-gapped instances turn the browser's
 * cloud-backed Web Speech API off so the chat input records for the server-side
 * transcription path instead.
 */
final class WebSpeechConfigTest extends TestCase
{
    private ?string $originalEnv = null;
    private bool $envWasSet = false;

    protected function setUp(): void
    {
        $this->envWasSet = \array_key_exists('WEB_SPEECH_ENABLED', $_ENV);
        $this->originalEnv = $this->envWasSet ? (string) $_ENV['WEB_SPEECH_ENABLED'] : null;
    }

    protected function tearDown(): void
    {
        if ($this->envWasSet) {
            $_ENV['WEB_SPEECH_ENABLED'] = $this->originalEnv;
        } else {
            unset($_ENV['WEB_SPEECH_ENABLED']);
        }
    }

    public function testEnabledByDefaultWhenUnset(): void
    {
        unset($_ENV['WEB_SPEECH_ENABLED']);

        self::assertTrue((new WebSpeechConfig())->isEnabled());
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function envValueProvider(): iterable
    {
        yield 'false' => ['false', false];
        yield '0' => ['0', false];
        yield 'off' => ['off', false];
        yield 'no' => ['no', false];
        yield 'true' => ['true', true];
        yield '1' => ['1', true];
        yield 'on' => ['on', true];
        // Unrecognized value keeps the safe default (Web Speech allowed).
        yield 'garbage' => ['nonsense', true];
        yield 'empty string' => ['', true];
    }

    #[DataProvider('envValueProvider')]
    public function testIsEnabledParsesEnv(string $value, bool $expected): void
    {
        $_ENV['WEB_SPEECH_ENABLED'] = $value;

        self::assertSame($expected, (new WebSpeechConfig())->isEnabled());
    }
}
