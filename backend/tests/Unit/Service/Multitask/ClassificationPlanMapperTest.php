<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask;

use App\Service\Multitask\ClassificationPlanMapper;
use App\Service\Multitask\Plan\Capability;
use PHPUnit\Framework\TestCase;

final class ClassificationPlanMapperTest extends TestCase
{
    private ClassificationPlanMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new ClassificationPlanMapper();
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function classificationProvider(): iterable
    {
        yield 'plain chat' => [[
            'topic' => 'general', 'intent' => 'chat', 'language' => 'en',
            'web_search' => null, 'source' => 'fast_path_heuristic', 'skip_sorting' => true,
        ]];

        yield 'mediamaker video with params' => [[
            'topic' => 'mediamaker', 'intent' => 'image_generation', 'language' => 'en',
            'media_type' => 'video', 'duration' => 8, 'resolution' => '720p',
            'web_search' => false, 'source' => 'ai_sorting', 'skip_sorting' => false,
        ]];

        yield 'file analysis' => [[
            'topic' => 'analyzefile', 'intent' => 'file_analysis', 'language' => 'de',
            'source' => 'attachment_document_or_audio', 'skip_sorting' => true,
        ]];

        yield 'custom topic with prompt metadata' => [[
            'topic' => 'legal-review', 'intent' => 'chat', 'language' => 'en',
            'prompt_metadata' => ['aiModel' => 42, 'tool_internet' => false],
            'rag_group_key' => 'contracts', 'rag_limit' => 5, 'rag_min_score' => 0.7,
        ]];

        yield 'widget fixed prompt with override model' => [[
            'topic' => 'tools:widget-default', 'intent' => 'chat', 'language' => 'auto',
            'source' => 'widget', 'is_widget_mode' => true, 'override_model_id' => 99,
        ]];
    }

    /**
     * The round-trip MUST be lossless — the executor relies on this to feed the
     * handler the EXACT array it would have received from the legacy path.
     *
     * @param array<string, mixed> $classification
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('classificationProvider')]
    public function testRoundTripIsLossless(array $classification): void
    {
        $plan = $this->mapper->toSingleNodePlan($classification);

        self::assertTrue($plan->isSingleNode());

        $recovered = $this->mapper->classificationFromNode($plan->nodes[0]);

        self::assertSame($classification, $recovered);
    }

    public function testReplyNodeAndLanguage(): void
    {
        $plan = $this->mapper->toSingleNodePlan(['intent' => 'chat', 'language' => 'fr']);

        self::assertSame('n1', $plan->replyNode);
        self::assertSame('fr', $plan->language);
    }

    public function testMissingLanguageDefaultsToEn(): void
    {
        $plan = $this->mapper->toSingleNodePlan(['intent' => 'chat']);

        self::assertSame('en', $plan->language);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: Capability}>
     */
    public static function capabilityProvider(): iterable
    {
        yield 'chat' => [['intent' => 'chat'], Capability::Chat];
        yield 'image' => [['intent' => 'image_generation', 'media_type' => 'image'], Capability::ImageGeneration];
        yield 'image default (no media_type)' => [['intent' => 'image_generation'], Capability::ImageGeneration];
        yield 'video' => [['intent' => 'image_generation', 'media_type' => 'video'], Capability::VideoGeneration];
        yield 'audio' => [['intent' => 'image_generation', 'media_type' => 'audio'], Capability::Text2Sound];
        yield 'file analysis' => [['intent' => 'file_analysis'], Capability::FileAnalysis];
        yield 'document generation' => [['intent' => 'document_generation'], Capability::DocumentGeneration];
        yield 'unknown intent falls back to chat' => [['intent' => 'mystery'], Capability::Chat];
    }

    /**
     * @param array<string, mixed> $classification
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('capabilityProvider')]
    public function testCapabilityDerivation(array $classification, Capability $expected): void
    {
        $plan = $this->mapper->toSingleNodePlan($classification);

        self::assertSame($expected, $plan->nodes[0]->capability);
    }
}
