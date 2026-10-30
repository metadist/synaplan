<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Handler\Media;

use App\Service\Message\Handler\Media\MediaDownloader;
use PHPUnit\Framework\TestCase;

/**
 * Fetches from PHP's built-in web server on 127.0.0.1, never from an external host.
 */
final class MediaDownloaderTest extends TestCase
{
    private const PIXEL = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAACklEQVR4nGMAAQAABQABDQottAAAAABJRU5ErkJggg==';

    private static string $root = '';
    private static int $port = 0;
    /** @var resource|null */
    private static $server;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir().'/media-downloader-'.bin2hex(random_bytes(4));
        mkdir(self::$root);
        file_put_contents(self::$root.'/pixel.png', base64_decode(self::PIXEL));
        file_put_contents(self::$root.'/router.php', <<<'PHP'
            <?php
            if ('/moved' === parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) {
                header('Location: /pixel.png', true, 302);

                return true;
            }

            return false;
            PHP);

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        self::$port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $server = proc_open(
            [\PHP_BINARY, '-S', '127.0.0.1:'.self::$port, '-t', self::$root, self::$root.'/router.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        self::assertIsResource($server);
        self::$server = $server;

        for ($attempt = 0; $attempt < 50; ++$attempt) {
            $probe = @fsockopen('127.0.0.1', self::$port, $errorCode, $errorMessage, 0.1);
            if (false !== $probe) {
                fclose($probe);

                return;
            }
            usleep(100_000);
        }
        self::fail('The local web server did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        foreach (glob(self::$root.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir(self::$root);
    }

    public function testItReturnsTheBytesOfTheFile(): void
    {
        self::assertSame(base64_decode(self::PIXEL), (new MediaDownloader(\Symfony\Component\HttpClient\HttpClient::create()))->fetch(self::url('/pixel.png')));
    }

    public function testItFollowsARedirect(): void
    {
        self::assertSame(base64_decode(self::PIXEL), (new MediaDownloader(\Symfony\Component\HttpClient\HttpClient::create()))->fetch(self::url('/moved')));
    }

    public function testAMissingFileFailsWithItsStatus(): void
    {
        $this->expectExceptionMessage('cURL download failed (HTTP 404): ');

        (new MediaDownloader(\Symfony\Component\HttpClient\HttpClient::create()))->fetch(self::url('/missing.png'));
    }

    public function testAHostThatRefusesTheConnectionFailsWithTheCurlError(): void
    {
        $this->expectExceptionMessageMatches('/^cURL download failed \(HTTP 0\): .+/');

        (new MediaDownloader(\Symfony\Component\HttpClient\HttpClient::create()))->fetch('http://127.0.0.1:1/pixel.png');
    }

    private static function url(string $path): string
    {
        return 'http://127.0.0.1:'.self::$port.$path;
    }
}
