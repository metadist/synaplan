<?php

declare(strict_types=1);

namespace App\AI\Messages\Vision;

use App\Service\MessagesGateway\MessagesGatewayConfig;
use Psr\Log\LoggerInterface;

/**
 * Applies the operator's per-image transport settings to an Anthropic-shaped
 * request body before it reaches a translator.
 *
 * This is the cost side of image handling, and it is deliberately separate from
 * {@see MessagesGatewayConfig::visionMode()}: the mode decides *which model*
 * reads an image turn, while the settings here decide *how many* images travel
 * and *at which resolution* they are read. Screenshot-heavy clients (Claude
 * Code above all) resend every image of a session on every turn, so a cap is
 * the difference between an affordable gateway and a surprise bill.
 *
 * Omitted images leave a short text block behind instead of disappearing: a
 * user turn whose only block was an image would otherwise become empty content,
 * which every provider rejects.
 *
 * @phpstan-type VisionOutcome array{
 *     body: array<string, mixed>,
 *     mutated: bool,
 *     mode: string,
 *     detail: string,
 *     images_forwarded: int,
 *     images_omitted: int
 * }
 */
final readonly class VisionPolicy
{
    public const PLACEHOLDER_LIMIT = '[Image omitted: this gateway forwards only the %d most recent images.]';

    public function __construct(
        private MessagesGatewayConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $requestBody
     *
     * @return VisionOutcome
     */
    public function apply(array $requestBody, ?int $userId): array
    {
        $mode = $this->config->visionMode($userId);
        $detail = $this->config->visionImageDetail($userId);
        $maxImages = $this->config->visionMaxImages($userId);

        $messages = $requestBody['messages'] ?? null;
        if (!\is_array($messages)) {
            return $this->outcome($requestBody, false, $mode, $detail, 0, 0);
        }

        $total = $this->countImages($messages);
        if (0 === $total) {
            return $this->outcome($requestBody, false, $mode, $detail, 0, 0);
        }

        $forwarded = $maxImages > 0 ? min($total, $maxImages) : $total;

        $omitted = $total - $forwarded;
        if (0 === $omitted) {
            return $this->outcome($requestBody, false, $mode, $detail, $forwarded, 0);
        }

        $placeholder = sprintf(self::PLACEHOLDER_LIMIT, $forwarded);

        // The oldest images go first: in an agent loop the newest screenshot is
        // the one the current turn is actually about.
        $seen = 0;
        $decide = static function () use (&$seen, $omitted, $placeholder): ?string {
            ++$seen;

            return $seen <= $omitted ? $placeholder : null;
        };

        $requestBody['messages'] = $this->rewriteMessages($messages, $decide);

        $this->logger->info('VisionPolicy: image blocks omitted', [
            'mode' => $mode,
            'max_images' => $maxImages,
            'total' => $total,
            'omitted' => $omitted,
        ]);

        return $this->outcome($requestBody, true, $mode, $detail, $forwarded, $omitted);
    }

    /**
     * @param array<mixed> $messages
     */
    private function countImages(array $messages): int
    {
        $count = 0;
        foreach ($messages as $message) {
            if (\is_array($message) && \is_array($message['content'] ?? null)) {
                $count += $this->countImagesInContent($message['content']);
            }
        }

        return $count;
    }

    /**
     * @param array<mixed> $content
     */
    private function countImagesInContent(array $content): int
    {
        $count = 0;
        foreach ($content as $block) {
            if (!\is_array($block)) {
                continue;
            }
            if ('image' === ($block['type'] ?? null)) {
                ++$count;
                continue;
            }
            // Tool results carry their own content array — a screenshot tool
            // returns its image in there.
            if (\is_array($block['content'] ?? null)) {
                $count += $this->countImagesInContent($block['content']);
            }
        }

        return $count;
    }

    /**
     * @param array<mixed>              $messages
     * @param callable(): (string|null) $decide   returns the placeholder text for an omitted image, or null to forward it
     *
     * @return list<mixed>
     */
    private function rewriteMessages(array $messages, callable $decide): array
    {
        $out = [];
        foreach ($messages as $message) {
            if (\is_array($message) && \is_array($message['content'] ?? null)) {
                $message['content'] = $this->rewriteContent($message['content'], $decide);
            }
            $out[] = $message;
        }

        return $out;
    }

    /**
     * @param array<mixed>              $content
     * @param callable(): (string|null) $decide
     *
     * @return list<mixed>
     */
    private function rewriteContent(array $content, callable $decide): array
    {
        $out = [];
        foreach ($content as $block) {
            if (!\is_array($block)) {
                $out[] = $block;
                continue;
            }

            if ('image' === ($block['type'] ?? null)) {
                $placeholder = $decide();
                $out[] = null === $placeholder
                    ? $block
                    : ['type' => 'text', 'text' => $placeholder];
                continue;
            }

            if (\is_array($block['content'] ?? null)) {
                $block['content'] = $this->rewriteContent($block['content'], $decide);
            }

            $out[] = $block;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return VisionOutcome
     */
    private function outcome(
        array $body,
        bool $mutated,
        string $mode,
        string $detail,
        int $forwarded,
        int $omitted,
    ): array {
        return [
            'body' => $body,
            'mutated' => $mutated,
            'mode' => $mode,
            'detail' => $detail,
            'images_forwarded' => $forwarded,
            'images_omitted' => $omitted,
        ];
    }
}
