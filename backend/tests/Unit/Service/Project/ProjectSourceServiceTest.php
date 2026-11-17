<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Project;

use App\Entity\File;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\FileRepository;
use App\Service\File\FileGroupDeletionService;
use App\Service\File\FileUploadService;
use App\Service\Project\ProjectException;
use App\Service\Project\ProjectsConfig;
use App\Service\Project\ProjectSource;
use App\Service\Project\ProjectSourceIndexer;
use App\Service\Project\ProjectSourceService;
use App\Service\Research\UrlContentResult;
use App\Service\Research\UrlContentService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class ProjectSourceServiceTest extends TestCase
{
    public function testLinkThatAnswers403EndsAsFailedWithAReason(): void
    {
        $urls = $this->createStub(UrlContentService::class);
        $urls->method('fetchForReading')->willReturn(new UrlContentResult('https://example.com/a', '', '', 'example.com', false, 'HTTP 403', blockedReason: 'bot_blocked'));
        $indexer = $this->createMock(ProjectSourceIndexer::class);
        $indexer->expects(self::once())->method('fail')->willReturnCallback(static function (File $file, string $reason): void {
            $file->setStatus('failed')->setVectorState(File::VECTOR_STATE_FAILED)->setFileText($reason);
        });
        $indexer->expects(self::never())->method('index');

        $source = $this->service($urls, $indexer)->addLink($this->project(), new User(), 'https://example.com/a');

        self::assertSame(ProjectSource::STATE_FAILED, $source->state);
        self::assertSame('HTTP 403', $source->reason);
        self::assertSame(ProjectSource::KIND_LINK, $source->kind);
    }

    public function testLinkIsReadInFullThroughTheGuardedReader(): void
    {
        $urls = $this->createMock(UrlContentService::class);
        $urls->expects(self::never())->method('fetch');
        $urls->expects(self::once())->method('fetchForReading')
            ->with('https://example.com/a', self::greaterThan(UrlContentService::DEFAULT_READ_TEXT_LENGTH))
            ->willReturn(new UrlContentResult('https://example.com/a', 'Body', 'Title', 'example.com', true));
        $indexer = $this->createMock(ProjectSourceIndexer::class);
        $indexer->expects(self::once())->method('index')->with(self::anything(), self::anything(), self::anything(), 'Body', 'Title');

        $this->service($urls, $indexer)->addLink($this->project(), new User(), 'https://example.com/a');
    }

    public function testRateLimitStopsTheLinkBeforeAnyRowOrFetch(): void
    {
        $urls = $this->createMock(UrlContentService::class);
        $urls->expects(self::never())->method('fetchForReading');
        $indexer = $this->createStub(ProjectSourceIndexer::class);
        $indexer->method('assertRate')->willThrowException(ProjectException::rateLimited('slow down'));
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');

        $this->expectExceptionCode(ProjectException::RATE_LIMITED);
        $this->service($urls, $indexer, $em)->addLink($this->project(), new User(), 'https://example.com/a');
    }

    public function testFailedRefreshKeepsTheEarlierSnapshot(): void
    {
        $file = (new File())->setUserId(7)->setGroupKey('project:u-1')->setSource(ProjectSourceService::SOURCE_LINK)
            ->setSourceId('https://example.com/a')->setFilePath('2026/11/a.txt')->setFileText('old');
        $file->markVectorized();
        $files = $this->createStub(FileRepository::class);
        $files->method('find')->willReturn($file);
        $urls = $this->createStub(UrlContentService::class);
        $urls->method('fetchForReading')->willReturn(new UrlContentResult('https://example.com/a', '', '', 'example.com', false, 'HTTP 500'));
        $indexer = $this->createMock(ProjectSourceIndexer::class);
        $indexer->expects(self::never())->method('fail');

        try {
            $this->service($urls, $indexer, null, $files)->refresh($this->project(), new User(), 1);
            self::fail('A failed refresh must answer with the reason');
        } catch (ProjectException $e) {
            self::assertSame(ProjectException::UPSTREAM, $e->getCode());
        }
        self::assertSame('old', $file->getFileText());
    }

    private function project(): Project
    {
        return new Project(7, 'Kitchen', 'u-1', 'project:u-1');
    }

    private function service(
        UrlContentService $urls,
        ProjectSourceIndexer $indexer,
        ?EntityManagerInterface $em = null,
        ?FileRepository $files = null,
    ): ProjectSourceService {
        if (null === $files) {
            $files = $this->createStub(FileRepository::class);
            $files->method('findByUserAndGroupKey')->willReturn([]);
        }
        $config = $this->createStub(ProjectsConfig::class);
        $config->method('maxSources')->willReturn(50);

        return new ProjectSourceService(
            $files,
            $this->createStub(FileUploadService::class),
            $urls,
            $indexer,
            $this->createStub(FileGroupDeletionService::class),
            $config,
            $em ?? $this->createStub(EntityManagerInterface::class),
        );
    }
}
