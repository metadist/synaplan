<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Runner;

use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\TaskRunner;
use App\Service\Multitask\MultitaskRoutingConfig;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Multitask\Skill\SkillDescriptor;
use App\Service\UrlContentResult;
use App\Service\UrlContentService;
use Psr\Log\LoggerInterface;

/**
 * `url_fetch` runner — "load this URL and read the content" as a planner-placed
 * DAG node (release-4.0 plan 09 §3.1, the first external DATA node).
 *
 * Thin adapter over the existing {@see UrlContentService}: SSRF-guarded,
 * robots.txt + noindex compliant, size-capped. No new fetch code.
 *
 * Reuse-first: when MessageProcessor step 2.7 already fetched the same turn's
 * URLs (topic prompt with `tool_url_screenshot`), the pre-formatted block in
 * `classification['url_content']` is reused instead of re-fetching — the same
 * pattern WebSearchRunner uses for pre-fetched search results.
 *
 * Uniform data-node contract (plan 09 §2): planner-placed, timeout-bounded,
 * read-only, isolated failure (`NodeResult::failed()` — never throws), output
 * is formatted citable text downstream nodes consume via `$nX.text`.
 */
final readonly class UrlFetchRunner implements TaskRunner
{
    /** Hard cap on the formatted node output (token control, plan 09 §3.1). */
    private const MAX_OUTPUT_CHARS = 12000;

    public function __construct(
        private UrlContentService $urlContentService,
        private MultitaskRoutingConfig $routingConfig,
        private LoggerInterface $logger,
    ) {
    }

    public function supportedCapabilities(): array
    {
        return [Capability::UrlFetch];
    }

    /**
     * @return list<SkillDescriptor>
     */
    public function describe(): array
    {
        return [
            new SkillDescriptor(
                Capability::UrlFetch,
                'Fetch and read the content of specific URL(s) the user named in the message (inputs.urls, falls back to URLs in the message text). Use when the answer depends on that page\'s content; prefer web_search when no concrete URL is given.',
                enabledFlag: MultitaskRoutingConfig::KEY_URL_FETCH_ENABLED,
                // Validated in plan-09 S2 verification — rollout default ON
                // (S6). Operators can still disable via BCONFIG.
                enabledDefault: true,
            ),
        ];
    }

    public function run(TaskNode $node, NodeContext $context): NodeResult
    {
        // Defense in depth: the SkillCatalog already hides this block from the
        // planner when the flag is off; a stale/hallucinated plan still must
        // not fetch.
        if (!$this->routingConfig->isFeatureEnabled(
            MultitaskRoutingConfig::KEY_URL_FETCH_ENABLED,
            $context->userId,
            true,
        )) {
            return NodeResult::failed('url_fetch is disabled');
        }

        $urls = $this->resolveUrls($node, $context);
        if ([] === $urls) {
            return NodeResult::failed('no URL found to fetch');
        }

        // Reuse step 2.7's fetch when it already ran for this turn and this
        // node has no narrower URL selection than the message itself.
        $preFetched = $context->classification['url_content'] ?? null;
        if (is_string($preFetched) && '' !== trim($preFetched)
            && $urls === $this->urlContentService->extractUrls((string) $context->message->getText())) {
            $this->logger->info('UrlFetchRunner: reusing pre-fetched URL content (step 2.7)');

            return NodeResult::ok($preFetched, [], [
                'url_fetch' => true,
                'query' => implode(', ', $this->hostnames($urls)),
                'urls' => $urls,
                'reused_prefetched' => true,
            ]);
        }

        $results = [];
        foreach ($urls as $url) {
            $results[] = $this->urlContentService->fetchForCrawling($url);
        }

        $successful = array_values(array_filter($results, static fn (UrlContentResult $r): bool => $r->success));
        if ([] === $successful) {
            $firstError = $results[0]->error ?? 'fetch failed';

            return NodeResult::failed('could not read the page: '.$firstError);
        }

        $text = $this->urlContentService->formatForPrompt($successful);
        if (mb_strlen($text) > self::MAX_OUTPUT_CHARS) {
            $text = mb_substr($text, 0, self::MAX_OUTPUT_CHARS).'…';
        }

        $this->logger->info('UrlFetchRunner: fetched URL content', [
            'requested' => count($urls),
            'successful' => count($successful),
            'chars' => mb_strlen($text),
        ]);

        return NodeResult::ok($text, [], [
            'url_fetch' => true,
            'query' => implode(', ', $this->hostnames($urls)),
            'urls' => $urls,
            'titles' => array_map(static fn (UrlContentResult $r): string => $r->title, $successful),
        ]);
    }

    /**
     * URLs from the planner's `inputs.urls` / `inputs.url` (literal or list),
     * falling back to URLs embedded in the user message. Capped at 3
     * (UrlContentService's per-message limit).
     *
     * @return list<string>
     */
    private function resolveUrls(TaskNode $node, NodeContext $context): array
    {
        $inputs = $context->resolveInputs($node);

        $candidates = [];
        foreach (['urls', 'url'] as $key) {
            $value = $inputs[$key] ?? null;
            if (is_string($value)) {
                $candidates = array_merge($candidates, $this->urlContentService->extractUrls($value));
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if (is_string($item)) {
                        $candidates = array_merge($candidates, $this->urlContentService->extractUrls($item));
                    }
                }
            }
        }

        if ([] === $candidates) {
            $candidates = $this->urlContentService->extractUrls((string) $context->message->getText());
        }

        return array_slice(array_values(array_unique($candidates)), 0, 3);
    }

    /**
     * @param list<string> $urls
     *
     * @return list<string>
     */
    private function hostnames(array $urls): array
    {
        $hosts = [];
        foreach ($urls as $url) {
            $host = parse_url($url, \PHP_URL_HOST);
            if (is_string($host) && '' !== $host) {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }
}
