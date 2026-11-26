<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\Service\File\UserUploadPathBuilder;
use App\Service\Message\MediaMarkerReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;

final class MediaMarkerReaderTest extends TestCase
{
    private const USER_ID = 13;
    private const OWN_FILE = '13/000/00013/2026/11/own.png';
    private const FOREIGN_FILE = '14/000/00014/2026/11/secret.pdf';

    private string $uploadDir;

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/media-marker-'.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->uploadDir.'/'.self::OWN_FILE, 'own');
        $filesystem->dumpFile($this->uploadDir.'/'.self::FOREIGN_FILE, 'foreign');
        $filesystem->dumpFile($this->uploadDir.'/generated_flat.png', 'flat');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->uploadDir);
    }

    public function testTextWithoutMarkerIsReturnedUnchanged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $marker = $this->reader($logger)->read('  Plain [reply] text ', self::USER_ID, 'openai');

        self::assertFalse($marker->hasFile());
        self::assertSame('  Plain [reply] text ', $marker->text);
        self::assertSame('', $marker->type);
    }

    public function testOwnStoredFileIsAccepted(): void
    {
        $marker = $this->reader()->read('Here it is [IMAGE:'.self::OWN_FILE.'] enjoy', self::USER_ID, 'openai');

        self::assertTrue($marker->hasFile());
        self::assertSame(self::OWN_FILE, $marker->path);
        self::assertSame('png', $marker->type);
        self::assertSame('Here it is  enjoy', $marker->text);
    }

    public function testOwnFileInServeUrlFormKeepsItsForm(): void
    {
        $target = '/api/v1/files/uploads/'.self::OWN_FILE;

        $marker = $this->reader()->read('[video:'.$target.']', self::USER_ID, null);

        self::assertSame($target, $marker->path);
        self::assertSame('mp4', $marker->type);
        self::assertSame('', $marker->text);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedTargets(): iterable
    {
        yield 'another account, raw path' => [self::FOREIGN_FILE];
        yield 'another account, serve URL' => ['/api/v1/files/uploads/'.self::FOREIGN_FILE];
        yield 'traversal out of the own directory' => ['13/000/00013/../../../'.self::FOREIGN_FILE];
        yield 'traversal out of the upload directory' => ['13/000/00013/../../../../etc/passwd'];
        yield 'absolute path' => ['/etc/passwd'];
        yield 'own directory, file missing' => ['13/000/00013/2026/11/missing.png'];
        yield 'own directory itself' => ['13/000/00013/'];
        yield 'directory of an id that only shares the prefix' => ['13/000/000130/x.png'];
        yield 'flat legacy file outside any account directory' => ['generated_flat.png'];
        yield 'backslash separators' => ['13\\000\\00013\\2026\\11\\own.png'];
        yield 'external address from a real provider' => ['https://tracker.example/pixel.png'];
        yield 'file scheme' => ['file:///etc/passwd'];
        yield 'data address' => ['data:image/png;base64,AAAA'];
        yield 'empty' => ['   '];
    }

    #[DataProvider('refusedTargets')]
    public function testOtherTargetsAreDroppedAndLogged(string $target): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $marker = $this->reader($logger)->read('Look: [IMAGE:'.$target.']', self::USER_ID, 'openai');

        self::assertFalse($marker->hasFile());
        self::assertSame('', $marker->path);
        self::assertSame('', $marker->type);
        self::assertSame('Look:', $marker->text);
    }

    public function testDemoProviderMayNameAnHttpsPlaceholder(): void
    {
        $marker = $this->reader()->read("Sample\n\n[IMAGE:https://picsum.photos/800/600]\n\nFooter", self::USER_ID, 'test');

        self::assertSame('https://picsum.photos/800/600', $marker->path);
        self::assertSame('png', $marker->type);
        self::assertStringNotContainsString('[IMAGE', $marker->text);
    }

    public function testDemoProviderMayNotNameAnotherAccountsFileOrPlainHttp(): void
    {
        $reader = $this->reader();

        self::assertFalse($reader->read('[IMAGE:'.self::FOREIGN_FILE.']', self::USER_ID, 'test')->hasFile());
        self::assertFalse($reader->read('[IMAGE:http://picsum.photos/1]', self::USER_ID, 'test')->hasFile());
    }

    public function testImageMarkerWinsAndEveryMarkerLeavesTheText(): void
    {
        $text = 'a [VIDEO:'.self::FOREIGN_FILE.'] b [IMAGE:'.self::OWN_FILE.'] c [IMAGE:'.self::FOREIGN_FILE.']';

        $marker = $this->reader()->read($text, self::USER_ID, 'openai');

        self::assertSame(self::OWN_FILE, $marker->path);
        self::assertSame('png', $marker->type);
        self::assertSame('a  b  c', $marker->text);
    }

    public function testRefusedTargetIsLoggedTruncated(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            self::isString(),
            self::callback(static fn (array $context): bool => self::USER_ID === $context['user_id']
                && 200 === mb_strlen((string) $context['target'])),
        );

        $this->reader($logger)->read('[IMAGE:'.str_repeat('a', 5000).']', self::USER_ID, 'openai');
    }

    private function reader(?LoggerInterface $logger = null): MediaMarkerReader
    {
        return new MediaMarkerReader(
            new UserUploadPathBuilder(),
            $this->uploadDir,
            $logger ?? $this->createStub(LoggerInterface::class),
        );
    }
}
