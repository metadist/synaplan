<?php

declare(strict_types=1);

namespace App\Service\Admin\SystemConfig\Tab;

use App\Bundle\BundleConfig;
use App\Module\Gate\ModuleGateConfig;
use App\Module\ModuleRegistry;
use App\Seed\ModuleGateSeeder;
use App\Service\Admin\SystemConfig\ConfigSection;
use App\Service\Admin\SystemConfig\DatabaseRow;
use App\Service\Admin\SystemConfig\FieldDefinition;
use App\Service\Admin\SystemConfig\SystemConfigTab;
use App\Service\Agent\AgentConfig;
use App\Service\Desktop\DesktopAgentConfig;
use App\Service\Document\DocumentToolsConfig;
use App\Service\Feature\FeatureFlagEnv;
use App\Service\Iam\IamConfig;
use App\Service\Multitask\MultitaskRoutingConfig;
use App\Service\PlatformLink\PlatformLinksConfig;
use App\Service\SavedTask\WorkflowsConfig;
use App\Service\SmartSearch\SmartSearchConfig;
use App\Service\Tool\ToolsConfig;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The Features tab: every wave feature flag (docs/FEATURE_FLAGS.md), grouped
 * by what the user sees, then one switch per optional module.
 *
 * BCONFIG rows, no restart; the flags are seeded ON since 4.8. A field key is
 * also the environment variable that pins the flag for automated deployments
 * (FEATURE_<GROUP>_<SETTING>=false), which the values report as
 * `envOverride`.
 */
#[AsTaggedItem(priority: 60)]
final readonly class FeaturesTab implements SystemConfigTab
{
    public function __construct(
        private ?ModuleRegistry $modules = null,
    ) {
    }

    public function id(): string
    {
        return 'features';
    }

    public function label(): string
    {
        return 'Features';
    }

    public function sections(): array
    {
        $sections = [
            'people' => $this->people(),
            'assistants' => $this->assistants(),
            'tasks' => $this->tasks(),
            'tools' => $this->tools(),
            'documents' => $this->documents(),
            'platforms' => $this->platforms(),
            'search' => $this->search(),
        ];
        $gates = $this->moduleGates();
        if ([] !== $gates->fields) {
            $sections['modules'] = $gates;
        }

        return $sections;
    }

    private function people(): ConfigSection
    {
        return new ConfigSection('Users & sharing', [
            'FEATURE_IAM_GROUPS_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'Users & groups: show Users under Operate and let administrators create groups. Members see their groups under Account. Sharing and group policies need this to be on.',
                default: 'true',
                row: new DatabaseRow(IamConfig::CONFIG_GROUP, IamConfig::KEY_GROUPS_ENABLED),
            ),
            'FEATURE_IAM_SHARING_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'Sharing: let owners share a knowledge folder, chat, AI assistant, saved task or chat widget with a user, a group or everyone. Adds Share buttons and "Shared with me" filters. Requires Users & groups.',
                default: 'true',
                row: new DatabaseRow(IamConfig::CONFIG_GROUP, IamConfig::KEY_SHARING_ENABLED),
            ),
            'FEATURE_IAM_USER_SEARCH_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'User search in the share picker. Off: an owner can share with users in a group they share, and with a group. On: an owner can share with any account on the instance. Groups are always searchable. Adding a member under Users → Groups still searches every account.',
                default: 'false',
                row: new DatabaseRow(IamConfig::CONFIG_GROUP, IamConfig::KEY_USER_SEARCH_ENABLED),
            ),
            'FEATURE_IAM_GROUP_POLICIES_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'Group policies: let administrators set default models, allowed models, feature flags and a rate-limit tier per group (Users → Policies). Locked global defaults cannot be overridden. Requires Users & groups.',
                default: 'true',
                row: new DatabaseRow(IamConfig::CONFIG_GROUP, IamConfig::KEY_GROUP_POLICIES_ENABLED),
            ),
            'FEATURE_IAM_DIRECTORY_SYNC_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'Directory groups: at sign-in, put users into groups from the company login (OIDC groups claim). Does nothing without OIDC. The claim path and display names live under Sharing → Directory groups.',
                default: 'true',
                row: new DatabaseRow(IamConfig::CONFIG_GROUP, IamConfig::KEY_DIRECTORY_SYNC_ENABLED),
            ),
        ]);
    }

    private function assistants(): ConfigSection
    {
        return new ConfigSection('AI assistants', [
            'FEATURE_AGENTS_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'AI assistants: the assistant builder (instructions, knowledge folders, tools, publish as chat widget) and the /api/v1/agents API. When off, every assistant route answers 404 and the builder is hidden.',
                default: 'true',
                row: new DatabaseRow(AgentConfig::CONFIG_GROUP, AgentConfig::KEY_ENABLED),
            ),
            'FEATURE_AGENTS_ROUTABLE_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'Assistants in routing: let the message sorter pick an assistant that was marked "reachable from chat" as the answer for a matching request. Off keeps assistants reachable only by explicit selection.',
                default: 'true',
                row: new DatabaseRow(AgentConfig::CONFIG_GROUP, AgentConfig::KEY_ROUTABLE_ENABLED),
            ),
            'FEATURE_BUNDLE_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'Export and import: move assistants, instructions and saved tasks between installs as a synaplan-bundle file (/api/v1/bundles). The instance scope is admin-only.',
                default: 'true',
                row: new DatabaseRow(BundleConfig::CONFIG_GROUP, BundleConfig::KEY_ENABLED),
            ),
        ]);
    }

    private function tasks(): ConfigSection
    {
        return new ConfigSection('Saved tasks & watched pages', [
            'FEATURE_WORKFLOWS_BUILDER_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'Steps editor and webhook trigger for Saved Tasks: pin the exact steps a task runs, ask before a tool step, and start a task from an incoming webhook. When off, saved tasks keep Run now and the schedule only.',
                default: 'true',
                row: new DatabaseRow(WorkflowsConfig::CONFIG_GROUP, WorkflowsConfig::KEY_BUILDER_ENABLED),
            ),
            'FEATURE_MULTITASK_URL_FETCH_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'Watched pages: fetch a web address, keep one saved copy per address, and let a scheduled Saved Task compare it and mail the differences. Also powers "get this URL" in chat. When off, the Watched pages tab is hidden.',
                default: 'true',
                row: new DatabaseRow(MultitaskRoutingConfig::CONFIG_GROUP, MultitaskRoutingConfig::KEY_URL_FETCH_ENABLED),
            ),
        ]);
    }

    private function tools(): ConfigSection
    {
        return new ConfigSection('Tools & approvals', [
            'FEATURE_TOOLS_REGISTRY_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'Tool registry: list every callable (MCP, custom HTTP, built-in) in one registry the assistant and Saved Tasks pick from. Off restores the previous per-loop catalogs — a kill switch, not a feature to leave off.',
                default: 'true',
                row: new DatabaseRow(ToolsConfig::CONFIG_GROUP, ToolsConfig::KEY_REGISTRY_ENABLED),
            ),
            'FEATURE_TOOLS_APPROVALS_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'Approvals: ask before a tool that changes or deletes something runs — in chat and in unattended Saved Tasks, which pause under Approvals until you decide. Defaults per tool class live under Routing → Tool policies.',
                default: 'true',
                row: new DatabaseRow(ToolsConfig::CONFIG_GROUP, ToolsConfig::KEY_APPROVALS_ENABLED),
            ),
            'FEATURE_TOOLS_CUSTOM_HTTP_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'Custom tools: let users declare their own HTTP / OpenAPI tools under Connections and use them from chat and Saved Tasks.',
                default: 'true',
                row: new DatabaseRow(ToolsConfig::CONFIG_GROUP, ToolsConfig::KEY_CUSTOM_HTTP_ENABLED),
            ),
        ]);
    }

    private function documents(): ConfigSection
    {
        return new ConfigSection('Office documents', [
            'FEATURE_DOCUMENT_TOOLS_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'Office document tools: let the assistant build and revise Word, Excel and PowerPoint files step by step (with revisions) instead of the one-shot generator. Editing files a user uploaded stays a separate, off-by-default setting.',
                default: 'true',
                row: new DatabaseRow(DocumentToolsConfig::CONFIG_GROUP, DocumentToolsConfig::KEY_ENABLED),
            ),
        ]);
    }

    private function platforms(): ConfigSection
    {
        return new ConfigSection('Desktop & partner platforms', [
            'FEATURE_DESKTOP_AGENT_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'Synaplan Desktop, under Manage → Channels. A paired computer can chat in the app and pick up waiting tasks. When this is off, that page is hidden, pairing stops, the app cannot chat, and waiting tasks are not picked up until you turn it on. There is no installer yet — the app is built from the source repository.',
                default: 'true',
                row: new DatabaseRow(DesktopAgentConfig::CONFIG_GROUP, DesktopAgentConfig::KEY_ENABLED),
            ),
            'FEATURE_PLATFORM_LINKS_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'Linked platforms: let Nextcloud, ownCloud and similar partner platforms connect their users to this instance (Operate → Users → Platform instances, Account → Linked platforms). The Outlook add-in connect path stays available either way.',
                default: 'true',
                row: new DatabaseRow(PlatformLinksConfig::CONFIG_GROUP, PlatformLinksConfig::KEY_ENABLED),
            ),
        ]);
    }

    private function search(): ConfigSection
    {
        return new ConfigSection('Search', [
            'FEATURE_SEARCH_AI_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'AI help in the search palette (Ctrl/Cmd+K): for a question in plain words, the search model (the chat model of each person unless an admin pins one) picks the best matching result and says why. Each use is one short AI call and counts as one message. Off keeps keyword and meaning search.',
                default: 'true',
                row: new DatabaseRow(SmartSearchConfig::CONFIG_GROUP, SmartSearchConfig::KEY_AI_ENABLED),
            ),
        ]);
    }

    /**
     * One switch per declared feature module, `MODULES.GATE_<ID>`, with the
     * new-install default of {@see ModuleGateSeeder::defaultValue()}. On
     * answers 404 on the module's routes and hides its cards while the
     * module is not configured.
     */
    private function moduleGates(): ConfigSection
    {
        $fields = [];
        foreach ($this->modules?->ids() ?? [] as $id) {
            $setting = ModuleGateConfig::settingFor($id);
            $onByDefault = '1' === ModuleGateSeeder::defaultValue($id);
            $fields[FeatureFlagEnv::envVarFor(ModuleGateConfig::GROUP, $setting)] = new FieldDefinition(
                type: 'boolean',
                description: sprintf(
                    'Hide the "%s" module while it is not configured: its API routes answer 404 and the interface shows no card for it. %s Configure the module first — see Operate → System status.',
                    $id,
                    $onByDefault
                        ? 'On is the shipped default for new installs.'
                        : 'Off (the shipped default) keeps the module visible with a "needs setup" state.',
                ),
                default: $onByDefault ? 'true' : 'false',
                row: new DatabaseRow(ModuleGateConfig::GROUP, $setting),
            );
        }

        return new ConfigSection('Optional modules — hide what is not configured', $fields);
    }
}
