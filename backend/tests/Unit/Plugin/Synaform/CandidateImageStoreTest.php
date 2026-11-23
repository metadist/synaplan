<?php

declare(strict_types=1);

namespace App\Tests\Unit\Plugin\Synaform;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugin\Synaform\Service\CandidateImageStore;
use Symfony\Component\Filesystem\Filesystem;

final class CandidateImageStoreTest extends TestCase
{
    private const USER = 7;

    private const CANDIDATE = 'entry_a1b2c3d4e5f6';

    private string $root;

    private string $uploads;

    private CandidateImageStore $store;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/synaform-images-'.bin2hex(random_bytes(6));
        $this->uploads = $this->root.'/uploads';
        $this->store = new CandidateImageStore($this->uploads);

        mkdir($this->store->directory(self::USER, self::CANDIDATE), 0o755, true);
        file_put_contents($this->store->directory(self::USER, self::CANDIDATE).'/photo.png', 'png-bytes');
        file_put_contents($this->root.'/secret.env', 'APP_SECRET=not-for-clients');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testFindsTheUploadedImageOfTheDataset(): void
    {
        $stored = ['path' => $this->store->directory(self::USER, self::CANDIDATE).'/photo.png', 'mime' => 'image/png'];

        $file = $this->store->locate(self::USER, self::CANDIDATE, $stored);

        self::assertSame(realpath($this->store->directory(self::USER, self::CANDIDATE).'/photo.png'), $file);
    }

    public function testAnImageStoredUnderAnotherUploadRootIsStillFoundByItsName(): void
    {
        $file = $this->store->locate(self::USER, self::CANDIDATE, ['path' => '/mnt/old-node/uploads/7/synaform/candidates/x/images/photo.png']);

        self::assertNotNull($file);
        self::assertSame('photo.png', basename($file));
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function valuesThatAreNotAnImageOfTheDataset(): iterable
    {
        yield 'system file' => [['path' => '/etc/hostname', 'mime' => 'text/plain']];
        yield 'file next to the upload root' => [['path' => '../../../../../secret.env']];
        yield 'windows separators' => [['path' => '..\\..\\..\\..\\..\\secret.env']];
        yield 'name that is not in the directory' => [['path' => 'secret.env']];
        yield 'the directory itself' => [['path' => '.']];
        yield 'parent directory' => [['path' => '..']];
        yield 'empty path' => [['path' => '']];
        yield 'path is not a string' => [['path' => ['photo.png']]];
        yield 'plain text value' => ['photo.png'];
        yield 'no value' => [null];
    }

    #[DataProvider('valuesThatAreNotAnImageOfTheDataset')]
    public function testRefusesEverythingOutsideTheImageDirectory(mixed $value): void
    {
        self::assertNull($this->store->locate(self::USER, self::CANDIDATE, $value));
    }

    public function testRefusesAnAbsolutePathToAFileThatExists(): void
    {
        self::assertFileExists($this->root.'/secret.env');

        self::assertNull($this->store->locate(self::USER, self::CANDIDATE, ['path' => $this->root.'/secret.env']));
    }

    public function testRefusesALinkThatLeavesTheImageDirectory(): void
    {
        symlink($this->root.'/secret.env', $this->store->directory(self::USER, self::CANDIDATE).'/link.png');

        self::assertNull($this->store->locate(self::USER, self::CANDIDATE, ['path' => 'link.png']));
    }

    public function testRefusesADatasetIdThatWalksOutOfTheUploadTree(): void
    {
        self::assertNull($this->store->locate(self::USER, '../'.self::CANDIDATE, ['path' => 'photo.png']));
        self::assertNull($this->store->locate(self::USER, '..', ['path' => 'photo.png']));
    }

    public function testAnotherDatasetDoesNotSeeTheImage(): void
    {
        self::assertNull($this->store->locate(self::USER, 'entry_ffffffffffff', ['path' => 'photo.png']));
        self::assertNull($this->store->locate(8, self::CANDIDATE, ['path' => 'photo.png']));
    }

    public function testTheContentTypeFollowsTheFileNotTheStoredRecord(): void
    {
        self::assertSame('image/png', $this->store->mimeOf('/x/photo.PNG'));
        self::assertSame('image/jpeg', $this->store->mimeOf('/x/photo.jpg'));
        self::assertSame('application/octet-stream', $this->store->mimeOf('/x/photo.bin'));
        self::assertSame('application/octet-stream', $this->store->mimeOf('/x/page.html'));
    }

    public function testAnImageRecordFromTheClientIsNotStored(): void
    {
        $values = $this->store->withoutClientImages([
            'firstname' => 'Ada',
            'pic' => ['path' => '/etc/hostname', 'mime' => 'text/html'],
            'jobs' => [['title' => 'Engineer', 'path' => 'career']],
        ]);

        self::assertSame(['firstname' => 'Ada', 'jobs' => [['title' => 'Engineer', 'path' => 'career']]], $values);
    }

    public function testAStoredImageSurvivesAnUpdateThatSendsItBackChanged(): void
    {
        $stored = ['firstname' => 'Ada', 'pic' => ['path' => '/uploads/7/synaform/candidates/e/images/pic.png', 'mime' => 'image/png']];

        $values = $this->store->withoutClientImages(
            ['firstname' => 'Grace', 'pic' => ['path' => '/etc/hostname', 'mime' => 'text/html']],
            $stored,
        );

        self::assertSame('Grace', $values['firstname']);
        self::assertSame($stored['pic'], $values['pic']);
    }

    public function testAStoredImageSurvivesAnUpdateThatLeavesItOut(): void
    {
        $stored = ['firstname' => 'Ada', 'pic' => ['path' => '/uploads/pic.png']];

        $values = $this->store->withoutClientImages(['firstname' => 'Grace'], $stored);

        self::assertSame(['firstname' => 'Grace', 'pic' => ['path' => '/uploads/pic.png']], $values);
    }

    public function testAPlainValueReplacesAStoredImageAndOtherStoredValuesAreNotCarriedOver(): void
    {
        $stored = ['pic' => ['path' => '/uploads/pic.png'], 'note' => 'old'];

        $values = $this->store->withoutClientImages(['pic' => ''], $stored);

        self::assertSame(['pic' => ''], $values);
    }
}
