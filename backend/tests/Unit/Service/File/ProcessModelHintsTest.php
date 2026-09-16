<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Repository\ModelRepository;
use App\Service\File\InvalidProcessModelHintException;
use App\Service\File\ProcessModelHints;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

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

    public function testJsonContentTypeReadsHintsFromBody(): void
    {
        $this->expectException(InvalidProcessModelHintException::class);
        $request = Request::create('/api/v1/files/1/process', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{"vectorize_model":"not-a-catalog-key"}');

        ProcessModelHints::fromRequest($request, $this->createMock(ModelRepository::class));
    }

    public function testInvalidJsonBodyIsRejected(): void
    {
        $this->expectException(InvalidProcessModelHintException::class);
        $this->expectExceptionMessage('valid JSON');
        $request = Request::create('/api/v1/files/1/process', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{not-json');

        ProcessModelHints::fromRequest($request, $this->createMock(ModelRepository::class));
    }

    public function testMultipartDoesNotParseBodyAsJson(): void
    {
        $request = Request::create('/api/v1/files/upload', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'multipart/form-data; boundary=----x',
        ], '{not-json');

        $hints = ProcessModelHints::fromRequest($request, $this->createMock(ModelRepository::class));
        $this->assertNull($hints->vectorizeModelId);
        $this->assertNull($hints->analyzeModelId);
    }
}
