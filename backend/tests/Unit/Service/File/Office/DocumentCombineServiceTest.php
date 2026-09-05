<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File\Office;

use App\Entity\File;
use App\Entity\User;
use App\Repository\FileRepository;
use App\Service\File\Office\DocumentCombineException;
use App\Service\File\Office\DocumentCombineService;
use App\Service\File\Office\DocumentExportService;
use App\Service\File\Office\OfficeConverterClient;
use App\Service\File\UserUploadPathBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DocumentCombineServiceTest extends TestCase
{
    public function testRejectsFewerThanTwoFiles(): void
    {
        $service = $this->service();

        $this->expectException(DocumentCombineException::class);
        try {
            $service->combineToPdf($this->user(), [1]);
        } catch (DocumentCombineException $e) {
            $this->assertSame('too_few', $e->reason);
            throw $e;
        }
    }

    public function testRejectsOfficeInputsWhenEngineOff(): void
    {
        $docx = $this->file(1, 'brief.docx', 'docx');
        $pdf = $this->file(2, 'a.pdf', 'pdf');
        $repo = $this->createMock(FileRepository::class);
        $repo->method('findByUserAndIds')->willReturn([$docx, $pdf]);

        $converter = $this->createMock(OfficeConverterClient::class);
        $converter->method('isEnabled')->willReturn(false);

        $service = $this->service($repo, $converter);

        $this->expectException(DocumentCombineException::class);
        try {
            $service->combineToPdf($this->user(), [1, 2]);
        } catch (DocumentCombineException $e) {
            $this->assertSame('engine_required', $e->reason);
            $this->assertSame(503, $e->getCode());
            throw $e;
        }
    }

    public function testResolvedDisplayNameUsesFirstSourceStemWhenFilenameMissing(): void
    {
        $xlsx = $this->file(1, 'Finanzmodell_Pro_Forma_Forecast.xlsx', 'xlsx');
        $pdf = $this->file(2, 'Finanzmodell_Pro_Forma_Forecast.pdf', 'pdf');

        self::assertSame(
            'Finanzmodell_Pro_Forma_Forecast_combined.pdf',
            DocumentCombineService::resolvedDisplayName(null, [$xlsx, $pdf]),
        );
        self::assertSame(
            'Quarterly_Report.pdf',
            DocumentCombineService::resolvedDisplayName('Quarterly_Report.pdf', [$xlsx, $pdf]),
        );
        self::assertSame('combined.pdf', DocumentCombineService::resolvedDisplayName(null, []));
    }

    public function testRejectsUnsupportedTypes(): void
    {
        $png = $this->file(1, 'pic.png', 'png');
        $pdf = $this->file(2, 'a.pdf', 'pdf');
        $repo = $this->createMock(FileRepository::class);
        $repo->method('findByUserAndIds')->willReturn([$png, $pdf]);

        $service = $this->service($repo);

        $this->expectException(DocumentCombineException::class);
        try {
            $service->combineToPdf($this->user(), [1, 2]);
        } catch (DocumentCombineException $e) {
            $this->assertSame('unsupported', $e->reason);
            throw $e;
        }
    }

    private function service(
        ?FileRepository $repo = null,
        ?OfficeConverterClient $converter = null,
    ): DocumentCombineService {
        return new DocumentCombineService(
            $this->createMock(DocumentExportService::class),
            $converter ?? $this->createMock(OfficeConverterClient::class),
            $repo ?? $this->createMock(FileRepository::class),
            new UserUploadPathBuilder(),
            $this->createMock(EntityManagerInterface::class),
            new NullLogger(),
            sys_get_temp_dir(),
            20,
        );
    }

    private function user(): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(7);

        return $user;
    }

    private function file(int $id, string $name, string $type): File
    {
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn($id);
        $file->method('getFileName')->willReturn($name);
        $file->method('getFileType')->willReturn($type);
        $file->method('getUserId')->willReturn(7);

        return $file;
    }
}
