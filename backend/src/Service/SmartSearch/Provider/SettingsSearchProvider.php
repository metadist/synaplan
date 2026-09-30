<?php

declare(strict_types=1);

namespace App\Service\SmartSearch\Provider;

use App\Service\Admin\SystemConfigService;
use App\Service\SmartSearch\SearchHit;
use App\Service\SmartSearch\SearchProviderInterface;
use App\Service\SmartSearch\SearchRequest;

/**
 * System settings from the admin config schema — only for admins. The hit
 * opens the field on the System config page.
 */
final readonly class SettingsSearchProvider implements SearchProviderInterface
{
    public const KIND = 'setting';
    private const SNIPPET_LENGTH = 160;

    public function __construct(
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

        $schema = $this->systemConfig->getSchema();
        $hits = [];
        foreach ($schema['fields'] as $key => $field) {
            $tab = $schema['tabs'][$field['tab']] ?? null;
            $tabLabel = null === $tab ? $field['tab'] : $tab['label'];
            $sectionLabel = $tab['sections'][$field['section']]['label'] ?? $field['section'];

            $score = SettingMatcher::score($request->query, $key, $tabLabel.' '.$sectionLabel, $field['description']);
            if ($score <= 0) {
                continue;
            }

            $description = trim($field['description']);
            $hits[] = new SearchHit(
                kind: self::KIND,
                refId: $key,
                title: $key,
                route: '/admin/config?'.http_build_query(['tab' => $field['tab'], 'highlight' => $key]),
                matchedBy: SearchHit::MATCHED_LEXICAL,
                subtitle: $tabLabel.' › '.$sectionLabel,
                snippet: '' === $description ? null : mb_strimwidth($description, 0, self::SNIPPET_LENGTH, '…'),
                score: $score,
            );
        }

        usort($hits, static fn (SearchHit $a, SearchHit $b): int => $b->score <=> $a->score);

        return array_slice($hits, 0, $request->limit);
    }
}
