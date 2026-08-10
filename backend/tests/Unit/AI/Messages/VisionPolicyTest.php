<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Messages\Vision\VisionPolicy;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class VisionPolicyTest extends TestCase
{
    private MessagesGatewayConfig&MockObject $config;
    private VisionPolicy $policy;

    protected function setUp(): void
    {
        $this->config = $this->createMock(MessagesGatewayConfig::class);
        $this->policy = new VisionPolicy($this->config, new NullLogger());
    }

    public function testAnUncappedRequestIsLeftUntouched(): void
    {
        $this->configure(MessagesGatewayConfig::VISION_AUTO, MessagesGatewayConfig::IMAGE_DETAIL_AUTO, 0);
        $body = $this->bodyWithImages(3);

        $result = $this->policy->apply($body, 1);

        $this->assertFalse($result['mutated']);
        $this->assertSame($body, $result['body']);
        $this->assertSame(3, $result['images_forwarded']);
        $this->assertSame(0, $result['images_omitted']);
    }

    public function testDetailIsReportedForTranslators(): void
    {
        $this->configure(MessagesGatewayConfig::VISION_AUTO, MessagesGatewayConfig::IMAGE_DETAIL_LOW, 0);

        $result = $this->policy->apply($this->bodyWithImages(1), 1);

        $this->assertSame(MessagesGatewayConfig::IMAGE_DETAIL_LOW, $result['detail']);
    }

    public function testTheLimitKeepsTheMostRecentImages(): void
    {
        $this->configure(MessagesGatewayConfig::VISION_AUTO, MessagesGatewayConfig::IMAGE_DETAIL_AUTO, 2);

        $result = $this->policy->apply($this->bodyWithImages(5), 1);

        $this->assertTrue($result['mutated']);
        $this->assertSame(2, $result['images_forwarded']);
        $this->assertSame(3, $result['images_omitted']);
        $this->assertSame(['img-3', 'img-4'], $this->imageIdsOf($result['body']));
        $this->assertSame(
            array_fill(0, 3, sprintf(VisionPolicy::PLACEHOLDER_LIMIT, 2)),
            $this->placeholdersOf($result['body']),
        );
    }

    public function testTurnsWithOnlyAnImageKeepNonEmptyContent(): void
    {
        $this->configure(MessagesGatewayConfig::VISION_AUTO, MessagesGatewayConfig::IMAGE_DETAIL_AUTO, 1);
        $body = ['messages' => [
            ['role' => 'user', 'content' => [$this->imageBlock('old')]],
            ['role' => 'user', 'content' => [$this->imageBlock('new')]],
        ]];

        $result = $this->policy->apply($body, 1);

        $this->assertSame(
            [['type' => 'text', 'text' => sprintf(VisionPolicy::PLACEHOLDER_LIMIT, 1)]],
            $result['body']['messages'][0]['content'],
        );
        $this->assertSame(['new'], $this->imageIdsOf($result['body']));
    }

    public function testTheLimitIsANoOpBelowTheThreshold(): void
    {
        $this->configure(MessagesGatewayConfig::VISION_AUTO, MessagesGatewayConfig::IMAGE_DETAIL_AUTO, 10);
        $body = $this->bodyWithImages(2);

        $result = $this->policy->apply($body, 1);

        $this->assertFalse($result['mutated']);
        $this->assertSame($body, $result['body']);
    }

    public function testImagesInsideToolResultsCount(): void
    {
        $this->configure(MessagesGatewayConfig::VISION_AUTO, MessagesGatewayConfig::IMAGE_DETAIL_AUTO, 1);
        $body = ['messages' => [
            [
                'role' => 'user',
                'content' => [[
                    'type' => 'tool_result',
                    'tool_use_id' => 'call_1',
                    'content' => [
                        ['type' => 'text', 'text' => 'screenshot taken'],
                        $this->imageBlock('shot'),
                    ],
                ]],
            ],
            ['role' => 'user', 'content' => [$this->imageBlock('newer')]],
        ]];

        $result = $this->policy->apply($body, 1);

        $this->assertSame(1, $result['images_omitted']);
        $this->assertSame(
            ['type' => 'text', 'text' => sprintf(VisionPolicy::PLACEHOLDER_LIMIT, 1)],
            $result['body']['messages'][0]['content'][0]['content'][1],
        );
    }

    public function testTextOnlyRequestsAreNeverRewritten(): void
    {
        $this->configure(MessagesGatewayConfig::VISION_AUTO, MessagesGatewayConfig::IMAGE_DETAIL_AUTO, 1);
        $body = ['messages' => [['role' => 'user', 'content' => 'hello']]];

        $result = $this->policy->apply($body, 1);

        $this->assertFalse($result['mutated']);
        $this->assertSame($body, $result['body']);
    }

    private function configure(string $mode, string $detail, int $maxImages): void
    {
        $this->config->method('visionMode')->willReturn($mode);
        $this->config->method('visionImageDetail')->willReturn($detail);
        $this->config->method('visionMaxImages')->willReturn($maxImages);
    }

    /**
     * One image per user turn, ids `img-0` … `img-{n-1}` in send order.
     *
     * @return array<string, mixed>
     */
    private function bodyWithImages(int $count): array
    {
        $messages = [];
        for ($i = 0; $i < $count; ++$i) {
            $messages[] = [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'look at this'],
                    $this->imageBlock('img-'.$i),
                ],
            ];
        }

        return ['model' => 'claude-sonnet-4-5', 'messages' => $messages];
    }

    /**
     * @return array<string, mixed>
     */
    private function imageBlock(string $id): array
    {
        return [
            'type' => 'image',
            'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => $id],
        ];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    private function imageIdsOf(array $body): array
    {
        $ids = [];
        foreach ($this->blocksOf($body) as $block) {
            if ('image' === ($block['type'] ?? null)) {
                $ids[] = (string) $block['source']['data'];
            }
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    private function placeholdersOf(array $body): array
    {
        $texts = [];
        foreach ($this->blocksOf($body) as $block) {
            $text = $block['text'] ?? null;
            if (\is_string($text) && str_starts_with($text, '[Image omitted')) {
                $texts[] = $text;
            }
        }

        return $texts;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<array<string, mixed>>
     */
    private function blocksOf(array $body): array
    {
        $blocks = [];
        foreach ($body['messages'] as $message) {
            foreach ($message['content'] as $block) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }
}
