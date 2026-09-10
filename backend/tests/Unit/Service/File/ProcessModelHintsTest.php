<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Repository\ModelRepository;
use App\Service\File\InvalidProcessModelHintException;
use App\Service\File\ProcessModelHints;
use PHPUnit\Framework\TestCase;

final class ProcessModelHintsTest extends TestCase
{
    public function testOmittedKeysStayNull(): void
    {
        $hints = ProcessModelHints::fromRaw(null, '', $this->createMock(ModelRepository::class));
        $this->assertNull($hints->vectorizeModelId);
        $this->assertNull($hints->analyzeModelId);
    }

    public function testUnknownKeyIsRejected(): void
    {
        $this->expectException(InvalidProcessModelHintException::class);
        ProcessModelHints::fromRaw('not-a-catalog-key', null, $this->createMock(ModelRepository::class));
    }

    public function testGarbageCatalogKeyIsRejected(): void
    {
        $this->expectException(InvalidProcessModelHintException::class);
        ProcessModelHints::fromRaw('ollama:definitely-missing:vectorize', null, $this->createMock(ModelRepository::class));
    }
}
