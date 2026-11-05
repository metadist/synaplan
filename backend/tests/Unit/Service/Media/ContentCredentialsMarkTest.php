<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Media;

use App\Entity\File;
use App\Repository\FileRepository;
use App\Service\Media\C2paSignerClient;
use App\Service\Media\ContentCredentialsMark;
use App\Service\Media\XmpDigitalSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

final class ContentCredentialsMarkTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function testProviderMarkedPngAndMp3StayByteIdentical(): void
    {
        $dir = $this->directory();
        $png = (string) base64_decode(self::PNG, true).'c2pa';
        $mp3 = "ID3\x00\x00\x00".'c2pa-audio';
        file_put_contents($dir.'/cat.png', $png);
        file_put_contents($dir.'/voice.mp3', $mp3);
        $mark = $this->marker($dir, 2);

        foreach (['cat.png' => $png, 'voice.mp3' => $mp3] as $name => $bytes) {
            $file = $this->file($name);
            $mark->apply($file);
            self::assertSame($bytes, file_get_contents($dir.'/'.$name));
            self::assertSame(ContentCredentialsMark::PROVIDER, $file->getCredentials());
            self::assertTrue($file->isGenerated());
            self::assertSame('gpt-image', $file->getGeneratedModel());
        }
    }

    public function testUnsignedPngGetsTheXmpFallback(): void
    {
        $dir = $this->directory();
        $png = (string) base64_decode(self::PNG, true);
        file_put_contents($dir.'/plain.png', $png);
        $file = $this->file('plain.png');

        $this->marker($dir, 1)->apply($file);

        $stored = file_get_contents($dir.'/plain.png');
        self::assertIsString($stored);
        self::assertNotSame($png, $stored);
        self::assertStringContainsString('trainedAlgorithmicMedia', $stored);
        self::assertSame(ContentCredentialsMark::NONE, $file->getCredentials());
    }

    private function marker(string $dir, int $saves): ContentCredentialsMark
    {
        $files = $this->createMock(FileRepository::class);
        $files->expects($this->exactly($saves))->method('save');

        return new ContentCredentialsMark(
            $files,
            new C2paSignerClient(new MockHttpClient(), ''),
            new XmpDigitalSource(),
            $dir,
        );
    }

    private function file(string $path): File
    {
        $file = new File();
        $file->setFilePath($path);
        $file->setProvider('gpt-image');
        $file->setSource('generated');

        return $file;
    }

    private function directory(): string
    {
        $dir = sys_get_temp_dir().'/cred-'.bin2hex(random_bytes(4));
        mkdir($dir);

        return $dir;
    }
}
