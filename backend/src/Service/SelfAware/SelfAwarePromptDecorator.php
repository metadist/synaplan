<?php

declare(strict_types=1);

namespace App\Service\SelfAware;

use App\Service\Knowledge\KnowledgeContextFormatter;
use App\Service\SelfAware\Docs\PlatformDocsHits;

/**
 * Replaces (or strips) the `[PLATFORM_CAPABILITIES]` and `[PLATFORM_DOCS]`
 * placeholders in a topic prompt. One call site for both ChatHandler paths.
 */
final readonly class SelfAwarePromptDecorator
{
    public const PLACEHOLDER_CAPABILITIES = '[PLATFORM_CAPABILITIES]';
    public const PLACEHOLDER_DOCS = '[PLATFORM_DOCS]';

    public function __construct(
        private SelfAwareConfig $config,
        private CapabilityInventory $inventory,
        private CapabilityReportRenderer $renderer,
        private KnowledgeContextFormatter $knowledgeContextFormatter,
    ) {
    }

    /**
     * Widget conversations (public embeds) are excluded — they answer for the
     * operator's business, not for Synaplan.
     *
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $options
     */
    public static function isWidgetConversation(array $classification, array $options): bool
    {
        return 'WIDGET' === ($options['channel'] ?? null)
            || 'widget' === ($classification['source'] ?? null);
    }

    public function apply(
        string $prompt,
        string $topic,
        int $userId,
        bool $isWidget,
        ?PlatformDocsHits $docsHits = null,
    ): string {
        $enabled = $this->config->isEnabled($userId > 0 ? $userId : null);
        $injectInventory = $enabled && !$isWidget && (
            SelfAwareConfig::ROUTABLE_TOPIC === $topic
            || ('general' === $topic && $this->config->isInventoryInGeneral($userId > 0 ? $userId : null))
        );

        if ($injectInventory) {
            $block = $this->renderer->render($this->inventory->build($userId));
            $prompt = str_replace(self::PLACEHOLDER_CAPABILITIES, $block, $prompt);
        } else {
            $prompt = str_replace(self::PLACEHOLDER_CAPABILITIES, '', $prompt);
        }

        $injectDocs = $enabled
            && !$isWidget
            && SelfAwareConfig::ROUTABLE_TOPIC === $topic
            && null !== $docsHits
            && !$docsHits->isEmpty();

        if ($injectDocs) {
            $prompt = str_replace(
                self::PLACEHOLDER_DOCS,
                $this->knowledgeContextFormatter->formatPlatformDocsContext($docsHits),
                $prompt,
            );
        } else {
            $prompt = str_replace(self::PLACEHOLDER_DOCS, '', $prompt);
        }

        return $prompt;
    }
}
