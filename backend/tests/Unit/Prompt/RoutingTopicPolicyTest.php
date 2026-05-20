<?php

declare(strict_types=1);

namespace App\Tests\Unit\Prompt;

use App\Prompt\RoutingTopicPolicy;
use PHPUnit\Framework\TestCase;

final class RoutingTopicPolicyTest extends TestCase
{
    public function testRoutingExcludedCanonicalTopics(): void
    {
        $this->assertTrue(RoutingTopicPolicy::isRoutingExcluded('general'));
        $this->assertTrue(RoutingTopicPolicy::isRoutingExcluded('mediamaker'));
        $this->assertFalse(RoutingTopicPolicy::isRoutingExcluded('general-chat'));
        $this->assertFalse(RoutingTopicPolicy::isRoutingExcluded('image-generation'));
    }

    public function testPromptLookupForGeneralUsesGeneralChatFirst(): void
    {
        $topics = RoutingTopicPolicy::promptLookupTopics('general');

        $this->assertSame(['general-chat', 'general'], $topics);
    }

    public function testPromptLookupPrefersGranularTopic(): void
    {
        $topics = RoutingTopicPolicy::promptLookupTopics('general', 'general-chat');

        $this->assertSame(['general-chat', 'general'], $topics);
    }

    public function testPromptLookupForMediamakerUsesMediaSubtype(): void
    {
        $this->assertSame(
            ['video-generation', 'mediamaker'],
            RoutingTopicPolicy::promptLookupTopics('mediamaker', null, 'video'),
        );
        $this->assertSame(
            ['audio-generation', 'mediamaker'],
            RoutingTopicPolicy::promptLookupTopics('mediamaker', null, 'audio'),
        );
        $this->assertSame(
            ['image-generation', 'mediamaker'],
            RoutingTopicPolicy::promptLookupTopics('mediamaker', null, 'image'),
        );
    }
}
