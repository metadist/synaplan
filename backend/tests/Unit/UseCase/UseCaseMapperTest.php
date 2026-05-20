<?php

declare(strict_types=1);

namespace App\Tests\Unit\UseCase;

use App\UseCase\UseCaseMapper;
use PHPUnit\Framework\TestCase;

final class UseCaseMapperTest extends TestCase
{
    private UseCaseMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new UseCaseMapper();
    }

    public function testTopicToUseCaseIdMapsGranularMediaTopics(): void
    {
        self::assertSame('media_generation', $this->mapper->topicToUseCaseId('image-generation'));
        self::assertSame('text_chat', $this->mapper->topicToUseCaseId('general', 'general-chat'));
    }

    public function testUseCaseToLegacyTopicBridgesToHandlers(): void
    {
        self::assertSame('analyzefile', $this->mapper->useCaseToLegacyTopic('file_analytics'));
        self::assertSame('mediamaker', $this->mapper->useCaseToLegacyTopic('media_generation', 'video'));
    }

    public function testAttachPrimaryUseCaseIdPreservesExistingValue(): void
    {
        $result = $this->mapper->attachPrimaryUseCaseId([
            'topic' => 'general',
            'primary_use_case_id' => 'file_analytics',
        ]);

        self::assertSame('file_analytics', $result['primary_use_case_id']);
    }

    public function testAttachPrimaryUseCaseIdDerivesFromTopic(): void
    {
        $result = $this->mapper->attachPrimaryUseCaseId([
            'topic' => 'officemaker',
        ]);

        self::assertSame('file_generation', $result['primary_use_case_id']);
    }
}
