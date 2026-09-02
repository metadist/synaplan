<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Capability;

use App\Service\Message\Capability\SystemCapability;
use App\Service\Message\Capability\SystemCapabilityRegistry;
use PHPUnit\Framework\TestCase;

final class SystemCapabilityRegistryTest extends TestCase
{
    private SystemCapabilityRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new SystemCapabilityRegistry();
    }

    public function testRegistryDeclaresExactlyTheFourSystemTopics(): void
    {
        self::assertSame(
            ['general', 'mediamaker', 'officemaker', 'docsummary'],
            $this->registry->topics(),
        );
    }

    /**
     * Every capability MUST declare a non-empty handler. This is the
     * structural fix for the gap the plan calls out: a capability can no
     * longer exist without a handler, because {@see SystemCapability}'s
     * constructor requires it.
     */
    public function testEveryCapabilityDeclaresANonEmptyHandler(): void
    {
        foreach ($this->registry->all() as $capability) {
            self::assertNotSame('', $capability->handlerName, "Capability '{$capability->topic}' has no handler declared.");
        }
    }

    /**
     * The regression test for the closed structural gap: `document_generation`
     * existed as an intent but had no entry in InferenceRouter::getHandler(),
     * silently defaulting to 'chat' via `?? 'chat'`. That default happened to
     * be correct (ChatHandler runs the officemaker structured-output path
     * internally) but was never an explicit decision. This test locks in the
     * EXPLICIT declaration.
     */
    public function testOfficemakerExplicitlyDeclaresDocumentGenerationHandledByChat(): void
    {
        $officemaker = $this->registry->byTopic('officemaker');

        self::assertInstanceOf(SystemCapability::class, $officemaker);
        self::assertSame('document_generation', $officemaker->intent);
        self::assertSame('chat', $officemaker->handlerName);
    }

    public function testByTopicReturnsNullForAnUnknownTopic(): void
    {
        self::assertNull($this->registry->byTopic('some-user-custom-topic'));
    }

    public function testTopicToIntentMapMatchesTheExistingMessageClassifierContract(): void
    {
        self::assertSame(
            [
                'general' => 'chat',
                'mediamaker' => 'image_generation',
                'officemaker' => 'document_generation',
                'docsummary' => 'chat',
            ],
            $this->registry->topicToIntentMap(),
        );
    }

    public function testIntentToHandlerMapMatchesTheExistingInferenceRouterContract(): void
    {
        self::assertSame(
            [
                'chat' => 'chat',
                'image_generation' => 'image_generation',
                'document_generation' => 'chat',
            ],
            $this->registry->intentToHandlerMap(),
        );
    }

    public function testMediamakerDeclaresTheMediaParameterSchema(): void
    {
        $mediamaker = $this->registry->byTopic('mediamaker');

        self::assertInstanceOf(SystemCapability::class, $mediamaker);
        self::assertSame(
            [
                'media_type' => SystemCapabilityRegistry::MEDIAMAKER_MEDIA_TYPES,
                'input_mode' => SystemCapabilityRegistry::MEDIAMAKER_INPUT_MODES,
                'resolution' => SystemCapabilityRegistry::MEDIAMAKER_VIDEO_RESOLUTIONS,
            ],
            $mediamaker->parameterSchema,
        );
    }

    public function testNonMediaCapabilitiesHaveNoParameterSchema(): void
    {
        foreach (['general', 'officemaker', 'docsummary'] as $topic) {
            $capability = $this->registry->byTopic($topic);
            self::assertInstanceOf(SystemCapability::class, $capability);
            self::assertNull($capability->parameterSchema);
        }
    }

    public function testEveryCapabilityDeclaresAtLeastOneExampleUtterance(): void
    {
        foreach ($this->registry->all() as $capability) {
            self::assertNotEmpty($capability->exampleUtterances, "Capability '{$capability->topic}' has no example utterances (needed for Phase 8's embedding-router anchors).");
        }
    }
}
