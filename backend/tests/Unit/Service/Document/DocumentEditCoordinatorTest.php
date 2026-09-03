<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Document;

use App\Entity\Message;
use App\Entity\Model;
use App\Repository\FileRepository;
use App\Service\Document\ChatToolLoop;
use App\Service\Document\DocumentEditCoordinator;
use App\Service\Document\DocumentToolsConfig;
use App\Service\Document\Import\DocumentImporter;
use App\Service\Document\Persist\DocumentRevisionService;
use App\Service\Document\Persist\DocumentTextProjector;
use App\Service\Document\Render\DocumentRenderer;
use App\Service\Document\Serializer\DocumentModelSerializer;
use App\Service\File\UserUploadPathBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DocumentEditCoordinatorTest extends TestCase
{
    public function testShouldRunRequiresOfficemakerEnabledFlagAndToolUse(): void
    {
        $config = $this->createMock(DocumentToolsConfig::class);
        $config->method('isEnabled')->willReturn(true);
        $coordinator = $this->coordinator($config);

        $message = new Message();
        $message->setUserId(1);
        $model = $this->createMock(Model::class);
        $model->expects(self::any())->method('hasFeature')->with('tool_use')->willReturn(true);

        self::assertTrue($coordinator->shouldRun('officemaker', $model, $message));
        self::assertFalse($coordinator->shouldRun('general', $model, $message));
        self::assertFalse($coordinator->shouldRun('officemaker', null, $message));
    }

    public function testShouldRunIsFalseWhenFlagOff(): void
    {
        $config = $this->createMock(DocumentToolsConfig::class);
        $config->method('isEnabled')->willReturn(false);
        $coordinator = $this->coordinator($config);
        $message = new Message();
        $message->setUserId(1);
        $model = $this->createMock(Model::class);
        $model->expects(self::any())->method('hasFeature')->with('tool_use')->willReturn(true);

        self::assertFalse($coordinator->shouldRun('officemaker', $model, $message));
    }

    private function coordinator(DocumentToolsConfig $config): DocumentEditCoordinator
    {
        return new DocumentEditCoordinator(
            $config,
            $this->createMock(ChatToolLoop::class),
            $this->createMock(DocumentRevisionService::class),
            $this->createMock(DocumentImporter::class),
            $this->createMock(DocumentModelSerializer::class),
            $this->createMock(DocumentRenderer::class),
            $this->createMock(DocumentTextProjector::class),
            $this->createMock(FileRepository::class),
            $this->createMock(UserUploadPathBuilder::class),
            $this->createMock(EntityManagerInterface::class),
            new NullLogger(),
            sys_get_temp_dir(),
        );
    }
}
