<?php

declare(strict_types=1);

namespace App\Service\Admin\SystemConfig\Tab;

use App\Service\Admin\SystemConfig\ConfigSection;
use App\Service\Admin\SystemConfig\DatabaseRow;
use App\Service\Admin\SystemConfig\FieldDefinition;
use App\Service\Admin\SystemConfig\SystemConfigTab;
use App\Service\Chat\ProgressNarrationConfig;
use App\Service\Usage\UsageTaximeterConfig;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The Interface tab: the in-chat usage display and how much the chat narrates
 * while a turn runs. BCONFIG rows (owner 0), no restart.
 */
#[AsTaggedItem(priority: 30)]
final readonly class InterfaceTab implements SystemConfigTab
{
    public function id(): string
    {
        return 'interface';
    }

    public function label(): string
    {
        return 'Interface';
    }

    public function sections(): array
    {
        return [
            'usage_display' => $this->usageDisplay(),
            'progress_narration' => $this->progressNarration(),
        ];
    }

    /**
     * The master switch (BCONFIG group USAGE_TAXIMETER, owner 0) for the
     * in-chat consumption bar and ring and the per-message token-cost badge.
     * Default on.
     */
    private function usageDisplay(): ConfigSection
    {
        return new ConfigSection('Usage display', [
            'USAGE_TAXIMETER_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'Show the in-chat usage display: a consumption bar (desktop) / ring (mobile) with today\'s spend, a session statistics popover, and a token-cost badge on each AI reply. When OFF, none of these render and the app skips the extra daily-total queries. This does NOT affect the full Statistics page or the recorded usage data — it only toggles the in-chat display. On by default.',
                default: 'true',
                row: new DatabaseRow(UsageTaximeterConfig::CONFIG_GROUP, UsageTaximeterConfig::KEY_ENABLED),
            ),
        ]);
    }

    /**
     * Three switches (BCONFIG group PROGRESS_NARRATION, owner 0) for how much
     * the chat tells the user while a turn runs. All default on.
     */
    private function progressNarration(): ConfigSection
    {
        return new ConfigSection('Chat progress narration', [
            'PROGRESS_SHOW_STEPS' => new FieldDefinition(
                type: 'boolean',
                description: 'Show the step-by-step progress of an answer while it is being prepared: understanding the request, web search, pages read, request sent to the model, model thinking, memory check. Finished steps stay listed until the answer streams, then fold into a one-line summary that remains on the finished message. When OFF, only the current phase is shown (one line, no history). On by default.',
                default: 'true',
                row: new DatabaseRow(ProgressNarrationConfig::CONFIG_GROUP, ProgressNarrationConfig::KEY_STEPS),
            ),
            'PROGRESS_SHOW_MODELS' => new FieldDefinition(
                type: 'boolean',
                description: 'Name the model and provider doing the work in the progress lines ("Sending your request to Claude Opus 4.8 by Anthropic…", "Creating your audio with tts-1 by OpenAI…"). Turn OFF for white-label deployments that must not reveal which vendors are used; the lines then read generically ("Sending your request…"). On by default.',
                default: 'true',
                row: new DatabaseRow(ProgressNarrationConfig::CONFIG_GROUP, ProgressNarrationConfig::KEY_MODELS),
            ),
            'PROGRESS_SHOW_TIMINGS' => new FieldDefinition(
                type: 'boolean',
                description: 'Show how long each finished step took and a live elapsed counter on the running step. When OFF, steps are listed without durations. On by default.',
                default: 'true',
                row: new DatabaseRow(ProgressNarrationConfig::CONFIG_GROUP, ProgressNarrationConfig::KEY_TIMINGS),
            ),
        ]);
    }
}
