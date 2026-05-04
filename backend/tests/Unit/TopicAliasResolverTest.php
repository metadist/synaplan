<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Message\TopicAliasResolver;
use PHPUnit\Framework\TestCase;

/**
 * Covers the bidirectional contract:
 *  - granular Synapse-v2 topics resolve to legacy canonical topics
 *  - downstream callers can also iterate over the inverse mapping
 *  - non-aliased topics are passed through untouched
 */
final class TopicAliasResolverTest extends TestCase
{
    private TopicAliasResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new TopicAliasResolver();
    }

    public function testGeneralChatResolvesToGeneral(): void
    {
        $result = $this->resolver->resolve('general-chat');

        $this->assertSame('general', $result['topic']);
        $this->assertNull($result['media']);
        $this->assertSame('general-chat', $result['alias_source']);
    }

    public function testCodingResolvesToGeneral(): void
    {
        $result = $this->resolver->resolve('coding');

        $this->assertSame('general', $result['topic']);
        $this->assertNull($result['media']);
        $this->assertSame('coding', $result['alias_source']);
    }

    public function testImageGenerationResolvesToMediamakerWithImageMedia(): void
    {
        $result = $this->resolver->resolve('image-generation');

        $this->assertSame('mediamaker', $result['topic']);
        $this->assertSame('image', $result['media']);
        $this->assertSame('image-generation', $result['alias_source']);
    }

    public function testVideoGenerationResolvesToMediamakerWithVideoMedia(): void
    {
        $result = $this->resolver->resolve('video-generation');

        $this->assertSame('mediamaker', $result['topic']);
        $this->assertSame('video', $result['media']);
    }

    public function testAudioGenerationResolvesToMediamakerWithAudioMedia(): void
    {
        $result = $this->resolver->resolve('audio-generation');

        $this->assertSame('mediamaker', $result['topic']);
        $this->assertSame('audio', $result['media']);
    }

    public function testCanonicalTopicPassesThroughUnchanged(): void
    {
        $result = $this->resolver->resolve('docsummary');

        $this->assertSame('docsummary', $result['topic']);
        $this->assertNull($result['media']);
        $this->assertNull($result['alias_source']);
    }

    public function testLegacyGeneralPassesThroughUnchanged(): void
    {
        $result = $this->resolver->resolve('general');

        $this->assertSame('general', $result['topic']);
        $this->assertNull($result['alias_source']);
    }

    public function testCustomTopicPassesThroughUnchanged(): void
    {
        $result = $this->resolver->resolve('my-custom-topic');

        $this->assertSame('my-custom-topic', $result['topic']);
        $this->assertNull($result['alias_source']);
    }

    public function testIsAliasReturnsTrueForKnownGranularTopics(): void
    {
        $this->assertTrue($this->resolver->isAlias('general-chat'));
        $this->assertTrue($this->resolver->isAlias('coding'));
        $this->assertTrue($this->resolver->isAlias('image-generation'));
    }

    public function testIsAliasReturnsFalseForCanonicalAndCustomTopics(): void
    {
        $this->assertFalse($this->resolver->isAlias('general'));
        $this->assertFalse($this->resolver->isAlias('mediamaker'));
        $this->assertFalse($this->resolver->isAlias('docsummary'));
        $this->assertFalse($this->resolver->isAlias('my-custom'));
    }

    public function testAliasesForReturnsAllGranularTopicsForGeneral(): void
    {
        $aliases = $this->resolver->aliasesFor('general');

        $this->assertContains('general-chat', $aliases);
        $this->assertContains('coding', $aliases);
    }

    public function testAliasesForReturnsAllMediaTopicsForMediamaker(): void
    {
        $aliases = $this->resolver->aliasesFor('mediamaker');

        $this->assertContains('image-generation', $aliases);
        $this->assertContains('video-generation', $aliases);
        $this->assertContains('audio-generation', $aliases);
    }

    public function testAliasesForReturnsEmptyArrayForUnknownCanonical(): void
    {
        $this->assertSame([], $this->resolver->aliasesFor('unknown-topic'));
    }

    public function testGetAliasMapReturnsAllRegisteredAliases(): void
    {
        $map = $this->resolver->getAliasMap();

        $this->assertSame('general', $map['general-chat']);
        $this->assertSame('general', $map['coding']);
        $this->assertSame('mediamaker', $map['image-generation']);
        $this->assertSame('mediamaker', $map['video-generation']);
        $this->assertSame('mediamaker', $map['audio-generation']);
    }
}
