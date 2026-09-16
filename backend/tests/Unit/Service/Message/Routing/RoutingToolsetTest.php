<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Routing;

use App\AI\ToolCalling\ToolCall;
use App\Service\Message\Capability\SystemCapabilityRegistry;
use App\Service\Message\Routing\RoutingToolset;
use PHPUnit\Framework\TestCase;

final class RoutingToolsetTest extends TestCase
{
    private RoutingToolset $toolset;

    protected function setUp(): void
    {
        $this->toolset = new RoutingToolset(new SystemCapabilityRegistry());
    }

    /**
     * `general` is expressed by the ABSENCE of a tool call — offering a tool
     * for it would turn the free case (just answer) into a decision the model
     * has to make explicitly.
     */
    public function testEverySystemCapabilityExceptGeneralGetsAHandoffTool(): void
    {
        $names = array_map(static fn ($tool): string => $tool->name, $this->toolset->build());

        self::assertSame(['handoff_mediamaker', 'handoff_officemaker', 'handoff_docsummary'], $names);
        self::assertNotContains('handoff_general', $names);
    }

    public function testToolDescriptionsCarryTheRegistersExamples(): void
    {
        $mediamaker = $this->toolset->build()[0];

        self::assertStringContainsString('Generate or edit an image', $mediamaker->description);
        self::assertStringContainsString('Make an image of a cat', $mediamaker->description);
    }

    public function testMediaParametersAreDerivedFromTheRegistersEnums(): void
    {
        $mediamaker = $this->toolset->build()[0];

        self::assertSame(
            SystemCapabilityRegistry::MEDIAMAKER_MEDIA_TYPES,
            $mediamaker->parameters['properties']['media_type']['enum'],
        );
        self::assertSame(
            SystemCapabilityRegistry::MEDIAMAKER_VIDEO_RESOLUTIONS,
            $mediamaker->parameters['properties']['resolution']['enum'],
        );
        // Nothing required: a resolution on an audio clip would be a
        // plausible-looking value the pipeline would then act on.
        self::assertSame([], $mediamaker->parameters['required']);
    }

    /**
     * `properties: []` would encode as a JSON array and be rejected by
     * Anthropic and Google alike.
     */
    public function testAParameterlessToolStillDeclaresAnObjectSchema(): void
    {
        $officemaker = $this->toolset->build()[1];

        self::assertSame('object', $officemaker->parameters['type']);
        self::assertInstanceOf(\stdClass::class, $officemaker->parameters['properties']);
    }

    public function testAToolCallMapsBackToItsTopic(): void
    {
        self::assertSame('mediamaker', $this->toolset->topicForToolCall(new ToolCall('1', 'handoff_mediamaker')));
        self::assertSame('docsummary', $this->toolset->topicForToolCall(new ToolCall('1', 'handoff_docsummary')));
    }

    public function testAHallucinatedOrUnroutableToolNameMapsToNothing(): void
    {
        self::assertNull($this->toolset->topicForToolCall(new ToolCall('1', 'search_the_web')));
        self::assertNull($this->toolset->topicForToolCall(new ToolCall('1', 'handoff_nonexistent')));
        // `general` has no tool, so a model inventing its name is not a hand-off.
        self::assertNull($this->toolset->topicForToolCall(new ToolCall('1', 'handoff_general')));
    }

    public function testOnlyRegisteredParameterValuesSurviveIntoTheClassification(): void
    {
        $call = new ToolCall('1', 'handoff_mediamaker', [
            'media_type' => 'video',
            'resolution' => '8K',          // outside the register's enum
            'duration' => '30',            // not a declared parameter at all
        ]);

        self::assertSame(['media_type' => 'video'], $this->toolset->classificationFieldsFor('mediamaker', $call));
    }

    public function testATopicWithoutDeclaredParametersContributesNoFields(): void
    {
        $call = new ToolCall('1', 'handoff_officemaker', ['media_type' => 'image']);

        self::assertSame([], $this->toolset->classificationFieldsFor('officemaker', $call));
        self::assertSame([], $this->toolset->classificationFieldsFor('unknown-topic', $call));
    }
}
