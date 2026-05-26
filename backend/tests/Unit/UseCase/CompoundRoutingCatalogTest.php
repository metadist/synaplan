<?php

declare(strict_types=1);

namespace App\Tests\Unit\UseCase;

use App\UseCase\CompoundRoutingCatalog;
use PHPUnit\Framework\TestCase;

class CompoundRoutingCatalogTest extends TestCase
{
    public function testAllReturnsNonEmptyArray(): void
    {
        $all = CompoundRoutingCatalog::all();

        $this->assertNotEmpty($all);
        $this->assertArrayHasKey('compound_research_image', $all);
        $this->assertArrayHasKey('compound_write_audio', $all);
        $this->assertArrayHasKey('compound_image_email', $all);
        $this->assertArrayHasKey('compound_research_file', $all);
        $this->assertArrayHasKey('compound_file_analyze_reply', $all);
    }

    public function testGetReturnsCompound(): void
    {
        $compound = CompoundRoutingCatalog::get('compound_research_image');

        $this->assertNotNull($compound);
        $this->assertArrayHasKey('steps', $compound);
        $this->assertArrayHasKey('example_queries', $compound);
        $this->assertCount(2, $compound['steps']);
        $this->assertEquals('CHAT', $compound['steps'][0]['capability']);
        $this->assertTrue($compound['steps'][0]['web_search']);
        $this->assertEquals('IMAGE_GENERATION', $compound['steps'][1]['capability']);
    }

    public function testGetReturnsNullForUnknown(): void
    {
        $this->assertNull(CompoundRoutingCatalog::get('nonexistent'));
    }

    public function testBuildStepPlan(): void
    {
        $plan = CompoundRoutingCatalog::buildStepPlan('compound_write_audio');

        $this->assertNotNull($plan);
        $this->assertTrue($plan->isCompound());
        $this->assertEquals(2, $plan->stepCount());
        $this->assertEquals('CHAT', $plan->steps[0]->capability);
        $this->assertEquals('AUDIO_GENERATION', $plan->steps[1]->capability);
        $this->assertEquals('audio', $plan->steps[1]->mediaType);
        $this->assertEquals('catalog', $plan->source);
    }

    public function testBuildStepPlanReturnsNullForUnknown(): void
    {
        $this->assertNull(CompoundRoutingCatalog::buildStepPlan('nonexistent'));
    }

    public function testExportTrainingData(): void
    {
        $data = CompoundRoutingCatalog::exportTrainingData();

        $this->assertNotEmpty($data);

        foreach ($data as $row) {
            $this->assertArrayHasKey('text', $row);
            $this->assertArrayHasKey('label', $row);
            $this->assertArrayHasKey('source', $row);
            $this->assertEquals('catalog', $row['source']);
            $this->assertNotEmpty($row['text']);
            $this->assertStringStartsWith('compound_', $row['label']);
        }

        $labels = array_unique(array_column($data, 'label'));
        $this->assertCount(5, $labels);
    }
}
