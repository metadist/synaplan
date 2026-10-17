<?php

declare(strict_types=1);

namespace App\Service\Widget;

use App\Entity\User;
use App\Entity\Widget;
use App\Service\Billing\BillingService;
use App\Service\Prompt\PromptService;
use App\Service\Research\UrlContentService;
use Psr\Log\LoggerInterface;

/**
 * Live data for a widget answer: the external API endpoints the widget's
 * configuration and its prompt's flow rules name, fetched for this visitor
 * and handed to the AI as one prompt section. Free accounts get none while
 * billing is on.
 */
final readonly class WidgetApiContext
{
    private const MAX_TOTAL_LENGTH = 16000;

    public function __construct(
        private BillingService $billingService,
        private PromptService $promptService,
        private UrlContentService $urlContentService,
        private LoggerInterface $logger,
    ) {
    }

    public function fetch(Widget $widget, User $owner, string $externalUserId = ''): string
    {
        $endpoints = $this->endpoints($widget, $owner);
        if ([] === $endpoints) {
            return '';
        }

        $token = $widget->getConfig()['externalApiToken'] ?? null;
        $sections = $this->sections($endpoints, \is_string($token) ? $token : '', $externalUserId);
        if ([] === $sections) {
            return '';
        }

        return "## Live API Data\nThe following data was fetched from configured API endpoints. Use this data to answer the user's question:\n\n"
            .implode("\n\n", $sections);
    }

    /**
     * The widget's own API URL, then every `api` response of its prompt's
     * flow rules; none while the prompt has no flow rules.
     *
     * @return list<array{url: string, method: mixed, label: mixed}>
     */
    private function endpoints(Widget $widget, User $owner): array
    {
        if ($this->billingService->isEnabled() && \in_array($owner->getUserLevel(), ['NEW', 'ANONYMOUS'], true)) {
            return [];
        }

        $topic = $widget->getTaskPromptTopic();
        $promptData = $topic ? $this->promptService->getPromptWithMetadata($topic, $owner->getId()) : null;
        $flowRulesRaw = $promptData['metadata']['widgetFlowRules'] ?? null;
        if (!\is_string($flowRulesRaw) || '' === $flowRulesRaw) {
            return [];
        }

        try {
            $flow = json_decode($flowRulesRaw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        $endpoints = [];
        $configApiUrl = $widget->getConfig()['externalApiUrl'] ?? null;
        $configApiUrl = \is_string($configApiUrl) ? trim($configApiUrl) : '';
        if ('' !== $configApiUrl) {
            if (!str_starts_with($configApiUrl, 'http://') && !str_starts_with($configApiUrl, 'https://')) {
                $configApiUrl = 'https://'.$configApiUrl;
            }
            $endpoints[] = ['url' => $configApiUrl, 'method' => 'GET', 'label' => 'User Profile'];
        }

        foreach ($flow['responses'] ?? [] as $response) {
            $url = $response['meta']['url'] ?? null;
            if ('api' === ($response['type'] ?? null) && \is_string($url) && '' !== trim($url)) {
                $endpoints[] = ['url' => trim($url), 'method' => $response['meta']['method'] ?? 'GET', 'label' => $response['label'] ?? ''];
            }
        }

        return $endpoints;
    }

    /**
     * One section per endpoint that answered, until the context is full. An
     * endpoint that needs the visitor's id is skipped without one.
     *
     * @param list<array{url: string, method: mixed, label: mixed}> $endpoints
     *
     * @return list<string>
     */
    private function sections(array $endpoints, string $token, string $externalUserId): array
    {
        $sections = [];
        $totalLength = 0;

        foreach ($endpoints as $api) {
            if ($totalLength >= self::MAX_TOTAL_LENGTH) {
                break;
            }

            $url = $api['url'];
            if (str_contains($url, '{externalUserId}')) {
                if ('' === $externalUserId) {
                    continue;
                }
                $url = str_replace('{externalUserId}', rawurlencode($externalUserId), $url);
            }

            try {
                $result = $this->urlContentService->fetchApi($url, $api['method'], '' !== $token ? ['Authorization' => 'Bearer '.$token] : []);
                if ($result->success && '' !== $result->extractedText) {
                    $section = sprintf("[%s %s] %s:\n%s", $api['method'], $api['url'], $api['label'], $result->extractedText);

                    $remaining = self::MAX_TOTAL_LENGTH - $totalLength;
                    if (\strlen($section) > $remaining) {
                        $section = mb_substr($section, 0, $remaining).'... [truncated]';
                    }

                    $sections[] = $section;
                    $totalLength += \strlen($section);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Widget API fetch failed', [
                    'url' => $api['url'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $sections;
    }
}
