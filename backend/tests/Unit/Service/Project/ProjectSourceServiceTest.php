<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Project;

use App\Entity\Project;
use App\Entity\User;
use App\Repository\FileRepository;
use App\Service\File\FileStorageService;
use App\Service\File\FileUploadService;
use App\Service\File\VectorizationService;
use App\Service\Project\ProjectsConfig;
use App\Service\Project\ProjectSource;
use App\Service\Project\ProjectSourceService;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use App\Service\RateLimitService;
use App\Service\Research\UrlContentResult;
use App\Service\Research\UrlContentService;
use App\Service\Usage\StorageQuotaService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class ProjectSourceServiceTest extends TestCase
{
    public function testLinkThatAnswers403EndsAsFailedWithAReason(): void
    {
        $urls = $this->createStub(UrlContentService::class);
        $urls->method('fetch')->willReturn(new UrlContentResult('https://example.com/a', '', '', 'example.com', false, 'The page answered HTTP 403'));
        $files = $this->createStub(FileRepository::class);
        $files->method('findByUserAndGroupKey')->willReturn([]);
        $config = $this->createStub(ProjectsConfig::class);
        $config->method('maxSources')->willReturn(50);

        $service = new ProjectSourceService(
            $files,
            $this->createStub(FileUploadService::class),
            $urls,
            $this->createStub(FileStorageService::class),
            $this->createStub(VectorizationService::class),
            $this->createStub(VectorStorageFacade::class),
            $this->createStub(StorageQuotaService::class),
            $this->createStub(RateLimitService::class),
            $config,
            $this->createStub(EntityManagerInterface::class),
        );

        $source = $service->addLink(new Project(7, 'Kitchen', 'u-1', 'project:u-1'), new User(), 'https://example.com/a');

        self::assertSame(ProjectSource::STATE_FAILED, $source->state);
        self::assertSame('The page answered HTTP 403', $source->reason);
        self::assertSame(ProjectSource::KIND_LINK, $source->kind);
    }
}
