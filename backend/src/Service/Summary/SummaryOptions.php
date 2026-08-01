<?php

namespace App\Service\Summary;

/**
 * Canonical option lists for the document summary / translation feature.
 *
 * Single source of truth shared by {@see \App\Controller\SummaryController}
 * (request validation) and the public capabilities endpoint
 * (\App\Controller\ConfigController::getCapabilities) so consumer
 * integrations can discover them instead of hardcoding — see issue #676.
 */
final class SummaryOptions
{
    /**
     * Supported summary types.
     *
     * @var list<string>
     */
    public const TYPES = ['abstractive', 'extractive', 'bullet-points'];

    /**
     * Supported target lengths ("custom" pairs with an explicit word count).
     *
     * @var list<string>
     */
    public const LENGTHS = ['short', 'medium', 'long', 'custom'];

    /**
     * Areas the summary can be told to emphasize.
     *
     * @var list<string>
     */
    public const FOCUS_AREAS = ['main-ideas', 'key-facts', 'conclusions', 'action-items', 'numbers-dates'];

    /**
     * Output/translation language codes exposed to API consumers.
     *
     * @var list<string>
     */
    public const LANGUAGES = ['en', 'de', 'fr', 'es', 'it'];
}
