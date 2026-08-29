<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Stt;

use App\Service\Stt\SttAudioAssembler;
use PHPUnit\Framework\TestCase;

final class SttAudioAssemblerTest extends TestCase
{
    private SttAudioAssembler $assembler;
    private string $dir;

    protected function setUp(): void
    {
        $this->assembler = new SttAudioAssembler();
        $this->dir = sys_get_temp_dir().'/stt-asm-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        $pending = $this->assembler->pendingPath($this->dir);
        if (is_file($pending)) {
            unlink($pending);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testAppendAndPendingSize(): void
    {
        $path = $this->assembler->pendingPath($this->dir);

        $this->assertSame(0, $this->assembler->pendingSize($path));
        $this->assertSame(4, $this->assembler->append($path, 'abcd'));
        $this->assertSame(3, $this->assembler->append($path, 'efg'));
        $this->assertSame(7, $this->assembler->pendingSize($path));
        $this->assertSame('abcdefg', file_get_contents($path));
    }

    public function testWrapPcmWavHasValidHeader(): void
    {
        $pcm = str_repeat("\x00\x01", 16);
        $wav = $this->assembler->wrapPcmWav($pcm, 16000, 1);

        $this->assertStringStartsWith('RIFF', $wav);
        $this->assertStringContainsString('WAVE', substr($wav, 0, 12));
        $this->assertStringContainsString('fmt ', $wav);
        $this->assertSame(44 + strlen($pcm), strlen($wav));
    }

    public function testDetectFormatSniffsWavAndFallsBackToPcm(): void
    {
        $wav = $this->assembler->wrapPcmWav('xxxx', 16000, 1);
        $this->assertSame(
            ['extension' => 'wav', 'wrap_pcm' => false],
            $this->assembler->detectFormat($wav, SttAudioAssembler::ENCODING_AUTO),
        );
        $this->assertSame(
            ['extension' => 'wav', 'wrap_pcm' => true],
            $this->assembler->detectFormat('not-a-container', SttAudioAssembler::ENCODING_AUTO),
        );
        $this->assertSame(
            ['extension' => 'webm', 'wrap_pcm' => false],
            $this->assembler->detectFormat('xxxx', SttAudioAssembler::ENCODING_WEBM),
        );
    }

    public function testBuildTranscribeFileWrapsBarePcm(): void
    {
        $path = $this->assembler->pendingPath($this->dir);
        $this->assembler->append($path, str_repeat("\x00\x01", 32));

        $tmp = $this->assembler->buildTranscribeFile($path, SttAudioAssembler::ENCODING_PCM, 16000, 1);
        try {
            $this->assertStringEndsWith('.wav', $tmp);
            $contents = file_get_contents($tmp);
            $this->assertIsString($contents);
            $this->assertStringStartsWith('RIFF', $contents);
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    public function testClearPendingEmptiesFile(): void
    {
        $path = $this->assembler->pendingPath($this->dir);
        $this->assembler->append($path, 'hello');
        $this->assembler->clearPending($path);

        $this->assertSame(0, $this->assembler->pendingSize($path));
    }
}
