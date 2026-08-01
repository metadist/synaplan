<?php

namespace App\Tests\Unit\Service\Capability;

use App\Service\Capability\CapabilityService;
use App\Service\File\FileStorageService;
use App\Service\Summary\SummaryOptions;
use PHPUnit\Framework\TestCase;

class CapabilityServiceTest extends TestCase
{
    private CapabilityService $service;

    protected function setUp(): void
    {
        $this->service = new CapabilityService();
    }

    public function testExposesSummaryOptionsAndLanguages(): void
    {
        $capabilities = $this->service->getCapabilities();

        $this->assertSame(SummaryOptions::LANGUAGES, $capabilities['languages']);
        $this->assertSame(SummaryOptions::TYPES, $capabilities['summary']['types']);
        $this->assertSame(SummaryOptions::LENGTHS, $capabilities['summary']['lengths']);
        $this->assertSame(SummaryOptions::FOCUS_AREAS, $capabilities['summary']['focus_areas']);
    }

    public function testMaxFileSizeMatchesStorageService(): void
    {
        $capabilities = $this->service->getCapabilities();

        $this->assertSame(FileStorageService::getMaxFileSize(), $capabilities['max_file_size_bytes']);
    }

    public function testFileFormatsOnlyContainAllowedExtensions(): void
    {
        $capabilities = $this->service->getCapabilities();
        $allowed = FileStorageService::getAllowedExtensions();

        $advertised = [];
        foreach ($capabilities['file_formats'] as $extensions) {
            foreach ($extensions as $ext) {
                $advertised[] = $ext;
            }
        }

        // Every advertised extension is genuinely accepted by the backend...
        foreach ($advertised as $ext) {
            $this->assertContains($ext, $allowed, sprintf('Advertised extension "%s" is not in the allow-list', $ext));
        }

        // ...and no allowed extension is silently dropped (the "other" bucket
        // catches anything without a dedicated category).
        sort($advertised);
        $expected = $allowed;
        sort($expected);
        $this->assertSame($expected, $advertised);
    }

    public function testKnownCategoriesArePopulated(): void
    {
        $formats = $this->service->getCapabilities()['file_formats'];

        $this->assertContains('pdf', $formats['documents']);
        $this->assertContains('xlsx', $formats['spreadsheets']);
        $this->assertContains('pptx', $formats['presentations']);
        $this->assertContains('png', $formats['images']);
        $this->assertContains('mp4', $formats['video']);
    }
}
