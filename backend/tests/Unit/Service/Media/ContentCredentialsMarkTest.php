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
use Symfony\Component\HttpClient\Response\MockResponse;

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

    public function testProviderMarkedFileStaysByteIdenticalEvenWhenTheSignerIsConfigured(): void
    {
        $dir = $this->directory();
        $png = (string) base64_decode(self::PNG, true).'c2pa';
        file_put_contents($dir.'/cat.png', $png);
        $file = $this->file('cat.png');

        $files = $this->createMock(FileRepository::class);
        $files->expects($this->once())->method('save');
        $http = new MockHttpClient(static function (): never {
            throw new \LogicException('The signer must not be called for provider-marked bytes');
        });
        $mark = new ContentCredentialsMark(
            $files,
            new C2paSignerClient($http, 'http://c2pa-signer:8787', 'secret-token'),
            new XmpDigitalSource(),
            $dir,
        );

        $mark->apply($file);

        self::assertSame($png, file_get_contents($dir.'/cat.png'));
        self::assertSame(ContentCredentialsMark::PROVIDER, $file->getCredentials());
    }

    public function testUnsignedPngGetsTheXmpFallback(): void
    {
        $dir = $this->directory();
        $png = (string) base64_decode(self::PNG, true);
        file_put_contents($dir.'/plain.png', $png);
        $file = $this->file('plain.png');
        $file->setFileSize(strlen($png));

        $this->marker($dir, 1)->apply($file);

        $stored = file_get_contents($dir.'/plain.png');
        self::assertIsString($stored);
        self::assertNotSame($png, $stored);
        self::assertStringContainsString('trainedAlgorithmicMedia', $stored);
        self::assertSame(ContentCredentialsMark::NONE, $file->getCredentials());
        self::assertSame(strlen($stored), $file->getFileSize());
    }

    public function testReSignPassesParentWhenTheModelLabelIsRefined(): void
    {
        $dir = $this->directory();
        $png = (string) base64_decode(self::PNG, true);
        file_put_contents($dir.'/generated.png', $png);
        $file = $this->file('generated.png');

        $calls = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$calls, $png): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('http://127.0.0.1:8787/sign', $url);
            $body = json_decode((string) ($options['body'] ?? '{}'), true);
            self::assertIsArray($body);
            $calls[] = $body;

            return new MockResponse(
                (string) json_encode(['bytes' => base64_encode($png.'jumdc2pa-'.count($calls))]),
            );
        });

        $files = $this->createMock(FileRepository::class);
        $files->expects($this->exactly(2))->method('save');
        $mark = new ContentCredentialsMark(
            $files,
            new C2paSignerClient($http, 'http://127.0.0.1:8787', 'secret-token'),
            new XmpDigitalSource(),
            $dir,
        );

        $mark->apply($file);
        self::assertSame(ContentCredentialsMark::SIGNED, $file->getCredentials());
        self::assertSame('gpt-image', $file->getGeneratedModel());

        $mark->label($file, 'openai gpt-image-1');
        self::assertSame('openai gpt-image-1', $file->getGeneratedModel());
        self::assertCount(2, $calls);
        self::assertFalse($calls[0]['parent']);
        self::assertSame('gpt-image', $calls[0]['model']);
        self::assertTrue($calls[1]['parent']);
        self::assertSame('openai gpt-image-1', $calls[1]['model']);
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
