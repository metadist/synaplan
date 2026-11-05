<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bundle;

use App\Bundle\BundleEnvelopeException;
use App\Bundle\BundleEnvelopeValidator;
use PHPUnit\Framework\TestCase;

final class BundleEnvelopeValidatorTest extends TestCase
{
    private BundleEnvelopeValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new BundleEnvelopeValidator();
    }

    public function testAcceptsMinimalDocument(): void
    {
        $parsed = $this->validator->parse($this->document(), ['prompts', 'agents']);

        self::assertSame('synaplan-bundle.v1', $parsed['schema']);
        self::assertCount(1, $parsed['sections']);
        self::assertSame('prompts', $parsed['sections'][0]['kind']);
    }

    public function testRejectsUnknownEnvelopeKeyWithPath(): void
    {
        $this->expectException(BundleEnvelopeException::class);
        $this->expectExceptionMessage('$.secret');
        $doc = json_decode($this->document(), true, 512, JSON_THROW_ON_ERROR);
        $doc['secret'] = 'nope';
        $this->validator->parse(json_encode($doc, JSON_THROW_ON_ERROR), ['prompts']);
    }

    public function testRejectsWrongSchema(): void
    {
        $this->expectException(BundleEnvelopeException::class);
        $this->expectExceptionMessage('$.schema');
        $doc = json_decode($this->document(), true, 512, JSON_THROW_ON_ERROR);
        $doc['schema'] = 'synaplan-bundle.v2';
        $this->validator->parse(json_encode($doc, JSON_THROW_ON_ERROR), ['prompts']);
    }

    public function testAcceptsOptionalTemplateBlock(): void
    {
        $doc = json_decode($this->document(), true, 512, JSON_THROW_ON_ERROR);
        $doc['template'] = [
            'id' => 'offer-writer',
            'version' => '2026.11.1',
            'titleKey' => 'templates.offerWriter.title',
            'summaryKey' => 'templates.offerWriter.summary',
            'category' => 'sales',
            'requires' => [
                ['kind' => 'capability', 'value' => 'chat'],
                ['kind' => 'knowledgeFolder', 'hintKey' => 'templates.offerWriter.needsPriceList'],
            ],
            'tryIt' => ['templates.offerWriter.try1'],
        ];

        $parsed = $this->validator->parse(json_encode($doc, JSON_THROW_ON_ERROR), ['prompts']);

        self::assertSame('offer-writer', $parsed['template']['id']);
        self::assertNull($this->validator->parse($this->document(), ['prompts'])['template']);
    }

    public function testRejectsUnknownTemplateKey(): void
    {
        $this->expectException(BundleEnvelopeException::class);
        $this->expectExceptionMessage('$.template.acceptance');
        $doc = json_decode($this->document(), true, 512, JSON_THROW_ON_ERROR);
        $doc['template'] = [
            'id' => 'offer-writer',
            'version' => '2026.11.1',
            'titleKey' => 'templates.offerWriter.title',
            'summaryKey' => 'templates.offerWriter.summary',
            'category' => 'sales',
            'requires' => [],
            'tryIt' => ['templates.offerWriter.try1'],
            'acceptance' => 'nope',
        ];
        $this->validator->parse(json_encode($doc, JSON_THROW_ON_ERROR), ['prompts']);
    }

    public function testRejectsUnknownSectionKind(): void
    {
        $this->expectException(BundleEnvelopeException::class);
        $this->expectExceptionMessage('unknown section kind');
        $this->validator->parse($this->document(), ['agents']);
    }

    public function testRejectsTooManyItems(): void
    {
        $this->expectException(BundleEnvelopeException::class);
        $items = [];
        for ($i = 0; $i < 201; ++$i) {
            $items[] = ['key' => 'p'.$i];
        }
        $doc = json_decode($this->document(), true, 512, JSON_THROW_ON_ERROR);
        $doc['sections'][0]['items'] = $items;
        $this->validator->parse(json_encode($doc, JSON_THROW_ON_ERROR), ['prompts']);
    }

    private function document(): string
    {
        return json_encode([
            'schema' => 'synaplan-bundle.v1',
            'createdAt' => '2026-10-01T09:12:00Z',
            'sourceInstance' => 'sha256:abc',
            'sourceVersion' => 'dev',
            'scope' => 'user',
            'sections' => [
                ['kind' => 'prompts', 'version' => 1, 'items' => [['key' => 'hello']]],
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
