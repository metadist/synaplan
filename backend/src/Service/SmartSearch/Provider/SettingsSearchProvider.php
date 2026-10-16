<?php

declare(strict_types=1);

namespace App\Service\SmartSearch\Provider;

use App\Repository\SearchIndexRepository;
use App\Service\Admin\SystemConfigService;
use App\Service\SmartSearch\Index\CatalogSync;
use App\Service\SmartSearch\Index\SettingsCatalog;
use App\Service\SmartSearch\Index\SettingSearchTerms;
use App\Service\SmartSearch\SearchHit;
use App\Service\SmartSearch\SearchProviderInterface;
use App\Service\SmartSearch\SearchRequest;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * System settings from the admin config schema — only for admins. Matches
 * by key and wording, and by meaning over the shared catalog rows. The hit
 * opens the field on the System config page.
 *
 * Database-backed toggles and choices carry an inline action; the palette
 * writes them through the admin config endpoint after a confirmation.
 *
 * @phpstan-import-type SettingEntry from SettingsCatalog
 * @phpstan-import-type SettingAction from SearchHit
 */
#[AsTaggedItem(priority: 90)]
final readonly class SettingsSearchProvider implements SearchProviderInterface
{
    public const KIND = SettingsCatalog::KIND;
    private const SNIPPET_LENGTH = 160;

    /**
     * A close meaning (similarity ~0.7 → 2.8) outranks a match on common
     * description words only (1 per word), but never a key word (6) or the
     * key itself. Tuned with app:search:eval: 0.49 → nDCG@10 0.88, 4 → 0.91,
     * higher gained nothing.
     */
    private const SEMANTIC_WEIGHT = 4.0;

    public function __construct(
        private SettingsCatalog $catalog,
        private CatalogSync $catalogSync,
        private SearchIndexRepository $repository,
        private SystemConfigService $systemConfig,
    ) {
    }

    public function name(): string
    {
        return 'settings';
    }

    public function search(SearchRequest $request): array
    {
        if (!$request->wants(self::KIND) || !$request->user->isAdmin()) {
            return [];
        }
        $this->catalogSync->ensureFresh();

        $entries = $this->catalog->entries();
        $scores = [];
        foreach ($entries as $key => $entry) {
            $labels = $entry['tabLabel'].' '.$entry['sectionLabel'].' '.SettingSearchTerms::of($entry['key']);
            $score = SettingMatcher::score($request->query, $key, $labels, $entry['description']);
            if ($score > 0) {
                $scores[$key] = [$score, SearchHit::MATCHED_LEXICAL];
            }
        }

        foreach ($this->semanticScores($request) as $key => $similarity) {
            if (isset($scores[$key])) {
                $scores[$key] = [$scores[$key][0] + $similarity * self::SEMANTIC_WEIGHT, SearchHit::MATCHED_BOTH];
            } elseif (isset($entries[$key])) {
                $scores[$key] = [$similarity * self::SEMANTIC_WEIGHT, SearchHit::MATCHED_SEMANTIC];
            }
        }

        uasort($scores, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        $top = array_slice($scores, 0, $request->limit, true);

        $inlineKeys = array_values(array_filter(
            array_map('strval', array_keys($top)),
            static fn (string $key): bool => $entries[$key]['inline'],
        ));
        $values = [] === $inlineKeys ? [] : $this->systemConfig->getValues(null, $inlineKeys);

        $hits = [];
        foreach ($top as $key => [$score, $matchedBy]) {
            $entry = $entries[$key];
            $hits[] = $this->hit($entry, $score, $matchedBy, isset($values[$key]) ? $this->action($entry, $values[$key]) : null);
        }

        return $hits;
    }

    /**
     * @param SettingEntry                                                      $entry
     * @param array{value: string, envOverride?: bool, effectiveValue?: string} $value
     *
     * @return SettingAction
     */
    private function action(array $entry, array $value): array
    {
        $pinned = true === ($value['envOverride'] ?? false);

        return [
            'type' => 'boolean' === $entry['type'] ? 'toggle' : 'select',
            'key' => $entry['key'],
            'current' => $pinned ? ($value['effectiveValue'] ?? $value['value']) : $value['value'],
            'options' => 'boolean' === $entry['type'] ? [] : $entry['options'],
            'scope' => 'system',
            'envPinned' => $pinned,
        ];
    }

    /**
     * @return array<string, float> setting key => cosine similarity
     */
    private function semanticScores(SearchRequest $request): array
    {
        $query = $request->vectors->forIndex();
        if (null === $query) {
            return [];
        }

        $scores = [];
        $rows = $this->repository->searchSemantic(
            SettingsCatalog::CATALOG_USER_ID,
            $query['vector'],
            $query['modelId'],
            [self::KIND],
            $request->limit,
            $request->vectors->indexMinScore(),
        );
        foreach ($rows as $row) {
            $scores[$row['refId']] = $row['score'];
        }

        return $scores;
    }

    /**
     * The route names the backend tab and section; the router sends it on to
     * the page that shows the section today (System config or AI setup).
     *
     * @param SettingEntry       $entry
     * @param SettingAction|null $action
     */
    private function hit(array $entry, float $score, string $matchedBy, ?array $action): SearchHit
    {
        return new SearchHit(
            kind: self::KIND,
            refId: $entry['key'],
            title: SettingLabel::of($entry['key'], $entry['description']),
            route: '/admin/config?'.http_build_query(['tab' => $entry['tab'], 'section' => $entry['section'], 'highlight' => $entry['key']]),
            matchedBy: $matchedBy,
            subtitle: $entry['tabLabel'].' › '.$entry['sectionLabel'].' · '.$entry['key'],
            snippet: '' === $entry['description'] ? null : mb_strimwidth($entry['description'], 0, self::SNIPPET_LENGTH, '…'),
            score: $score,
            action: $action,
        );
    }
}
