<?php

declare(strict_types=1);

namespace App\Service\Admin\SystemConfig\Tab;

use App\Service\Admin\SystemConfig\ConfigSection;
use App\Service\Admin\SystemConfig\DatabaseRow;
use App\Service\Admin\SystemConfig\FieldDefinition;
use App\Service\Admin\SystemConfig\SystemConfigTab;
use App\Service\Compute\ComputeConfig;
use App\Service\Media\MediaJobConfig;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The Processing tab: document extraction (Apache Tika, Docling), the PDF
 * rasterizer, Whisper, Brave web search, background media jobs and file work
 * on the compute sidecar.
 */
#[AsTaggedItem(priority: 70)]
final readonly class ProcessingTab implements SystemConfigTab
{
    public function id(): string
    {
        return 'processing';
    }

    public function label(): string
    {
        return 'Processing';
    }

    public function sections(): array
    {
        return [
            'tika' => $this->tika(),
            'docling' => $this->docling(),
            'rasterize' => $this->rasterize(),
            'whisper' => $this->whisper(),
            'brave' => $this->brave(),
            'media' => $this->media(),
            'compute' => $this->compute(),
        ];
    }

    private function tika(): ConfigSection
    {
        return new ConfigSection('Apache Tika', [
            'TIKA_BASE_URL' => new FieldDefinition(
                type: 'url',
                description: 'Apache Tika URL',
                default: 'http://tika:9998',
            ),
            'TIKA_TIMEOUT_MS' => new FieldDefinition(
                type: 'number',
                description: 'Request timeout (ms)',
                default: '30000',
            ),
            'TIKA_RETRIES' => new FieldDefinition(
                type: 'number',
                description: 'Max retries',
                default: '2',
            ),
            'TIKA_HTTP_USER' => new FieldDefinition(
                type: 'text',
                description: 'HTTP auth username',
            ),
            'TIKA_HTTP_PASS' => new FieldDefinition(
                type: 'password',
                description: 'HTTP auth password',
                sensitive: true,
            ),
        ]);
    }

    private function docling(): ConfigSection
    {
        return new ConfigSection('Docling', [
            'DOCLING_BASE_URL' => new FieldDefinition(
                type: 'url',
                description: 'Docling serve URL (empty = off)',
                placeholder: 'http://docling:5001',
            ),
            'DOCLING_TIMEOUT_MS' => new FieldDefinition(
                type: 'number',
                description: 'Request timeout (ms)',
                default: '120000',
            ),
            'DOCLING_MAX_BYTES' => new FieldDefinition(
                type: 'number',
                description: 'Max file size (bytes)',
                default: '52428800',
            ),
        ]);
    }

    private function rasterize(): ConfigSection
    {
        return new ConfigSection('PDF Rasterizer', [
            'RASTERIZE_DPI' => new FieldDefinition(
                type: 'number',
                description: 'PDF rasterization DPI',
                default: '150',
            ),
            'RASTERIZE_PAGE_CAP' => new FieldDefinition(
                type: 'number',
                description: 'Max pages to rasterize',
                default: '10',
            ),
            'RASTERIZE_TIMEOUT_MS' => new FieldDefinition(
                type: 'number',
                description: 'Rasterization timeout (ms)',
                default: '30000',
            ),
        ]);
    }

    private function whisper(): ConfigSection
    {
        return new ConfigSection('Whisper (Audio)', [
            'WHISPER_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'Enable audio transcription',
                default: 'true',
            ),
            'WHISPER_DEFAULT_MODEL' => new FieldDefinition(
                type: 'select',
                description: 'Default Whisper model',
                default: 'base',
                options: ['tiny', 'base', 'small', 'medium', 'large'],
            ),
        ]);
    }

    private function brave(): ConfigSection
    {
        return new ConfigSection('Web Search (Brave)', [
            'BRAVE_SEARCH_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'Enable web search',
                default: 'false',
            ),
            'BRAVE_SEARCH_API_KEY' => new FieldDefinition(
                type: 'password',
                description: 'Brave Search API key',
                sensitive: true,
            ),
            'BRAVE_SEARCH_COUNT' => new FieldDefinition(
                type: 'number',
                description: 'Results per search',
                default: '10',
            ),
        ]);
    }

    /**
     * Stored in BCONFIG group MEDIA / setting ASYNC_JOBS_ENABLED (the row
     * MediaJobConfig reads): the master switch for detaching media renders to
     * background jobs instead of running them inline.
     */
    private function media(): ConfigSection
    {
        return new ConfigSection('Async media generation', [
            'MEDIA_ASYNC_JOBS_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'Run media generation (image, video, audio) as background jobs so the chat is never blocked — the assistant shows a live status banner and a completion toast. When OFF, renders run inline and the turn blocks until they finish. Requires the worker container. Global default; existing users keep their own setting until they opt in.',
                default: 'true',
                row: new DatabaseRow(MediaJobConfig::CONFIG_GROUP, MediaJobConfig::KEY_ASYNC_JOBS_ENABLED),
            ),
        ]);
    }

    private function compute(): ConfigSection
    {
        return new ConfigSection('File work', [...$this->computeAccess(), ...$this->computeLimits()]);
    }

    /**
     * Whether file work runs, the isolation it needs, the folder it may keep
     * and its website access.
     *
     * @return array<string, FieldDefinition>
     */
    private function computeAccess(): array
    {
        return [
            'COMPUTE_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'Let the assistant do short file work (Python or Node) on copies of files you chose. Needs the compute sidecar (COMPUTE_URL + COMPUTE_TOKEN). Local `docker compose up` starts that sidecar and turns this on.',
                default: 'false',
                row: new DatabaseRow(ComputeConfig::CONFIG_GROUP, ComputeConfig::KEY_ENABLED),
            ),
            'COMPUTE_REQUIRE_TIER' => new FieldDefinition(
                type: 'select',
                description: 'Minimum isolation the compute sidecar must report before file work turns on: Standard (plain Docker), Strong isolation (gVisor), Virtual machine (microVM). Synaplan Cloud requires Strong isolation.',
                default: 'docker',
                row: new DatabaseRow(ComputeConfig::CONFIG_GROUP, ComputeConfig::KEY_REQUIRE_TIER),
                options: ['docker', 'gvisor', 'microvm'],
            ),
            'COMPUTE_WORKSPACES_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'Keep a folder between file-work runs so the assistant can continue yesterday\'s work. Off by default. Files → Workspace shows what is in it. Also needs file work itself to be on.',
                default: 'false',
                row: new DatabaseRow(ComputeConfig::CONFIG_GROUP, ComputeConfig::KEY_WORKSPACES_ENABLED),
            ),
            'COMPUTE_EGRESS_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'Let a file-work run fetch from a short list of public websites the assistant named. Off by default — runs stay offline. Private or local addresses are always refused. Can only be switched on when the connected compute sidecar offers website access (features.egress in its health report).',
                default: 'false',
                row: new DatabaseRow(ComputeConfig::CONFIG_GROUP, ComputeConfig::KEY_EGRESS_ENABLED),
            ),
            'COMPUTE_EGRESS_REQUIRES_APPROVAL' => new FieldDefinition(
                type: 'boolean',
                description: 'When a run will fetch from the web, ask the owner first even if everyday file work would run automatically. On by default.',
                default: 'true',
                row: new DatabaseRow(ComputeConfig::CONFIG_GROUP, ComputeConfig::KEY_EGRESS_REQUIRES_APPROVAL),
            ),
            'COMPUTE_EGRESS_MAX_HOSTS' => new FieldDefinition(
                type: 'number',
                description: 'How many websites one file-work run may list. Default 8.',
                default: (string) ComputeConfig::DEFAULT_EGRESS_MAX_HOSTS,
                row: new DatabaseRow(ComputeConfig::CONFIG_GROUP, ComputeConfig::KEY_EGRESS_MAX_HOSTS),
            ),
        ];
    }

    /**
     * What one run may use, when it runs without asking, and how long a kept
     * folder lives.
     *
     * @return array<string, FieldDefinition>
     */
    private function computeLimits(): array
    {
        return [
            'COMPUTE_DEFAULT_TIMEOUT_SEC' => new FieldDefinition(
                type: 'number',
                description: 'Default seconds a file-work run may take.',
                default: '60',
                row: new DatabaseRow(ComputeConfig::CONFIG_GROUP, ComputeConfig::KEY_DEFAULT_TIMEOUT_SEC),
            ),
            'COMPUTE_DEFAULT_MEMORY_MB' => new FieldDefinition(
                type: 'number',
                description: 'Default memory cap in megabytes for a file-work run.',
                default: '512',
                row: new DatabaseRow(ComputeConfig::CONFIG_GROUP, ComputeConfig::KEY_DEFAULT_MEMORY_MB),
            ),
            'COMPUTE_DEFAULT_CPU' => new FieldDefinition(
                type: 'number',
                description: 'Default CPU share for a file-work run.',
                default: '1.0',
                row: new DatabaseRow(ComputeConfig::CONFIG_GROUP, ComputeConfig::KEY_DEFAULT_CPU),
            ),
            'COMPUTE_DEFAULT_PIDS' => new FieldDefinition(
                type: 'number',
                description: 'Default process cap for a file-work run.',
                default: '128',
                row: new DatabaseRow(ComputeConfig::CONFIG_GROUP, ComputeConfig::KEY_DEFAULT_PIDS),
            ),
            'COMPUTE_DEFAULT_OUTPUT_MB' => new FieldDefinition(
                type: 'number',
                description: 'Default size cap in megabytes for files the run may create.',
                default: '50',
                row: new DatabaseRow(ComputeConfig::CONFIG_GROUP, ComputeConfig::KEY_DEFAULT_OUTPUT_MB),
            ),
            'COMPUTE_MAX_TIMEOUT_SEC' => new FieldDefinition(
                type: 'number',
                description: 'Hard ceiling in seconds for a file-work run.',
                default: '300',
                row: new DatabaseRow(ComputeConfig::CONFIG_GROUP, ComputeConfig::KEY_MAX_TIMEOUT_SEC),
            ),
            'COMPUTE_POLICY_INTERACTIVE' => new FieldDefinition(
                type: 'select',
                description: 'When the owner is present (chat or a live API call): run file work automatically, ask first, or never run it. An assistant cannot loosen this.',
                default: 'auto',
                row: new DatabaseRow(ComputeConfig::CONFIG_GROUP, ComputeConfig::KEY_POLICY_INTERACTIVE),
                options: ['auto', 'approve', 'block'],
            ),
            'COMPUTE_POLICY_UNATTENDED' => new FieldDefinition(
                type: 'select',
                description: 'When a saved task runs file work on its own: ask the owner first (default), run automatically, or never run it. An assistant cannot loosen this.',
                default: 'approve',
                row: new DatabaseRow(ComputeConfig::CONFIG_GROUP, ComputeConfig::KEY_POLICY_UNATTENDED),
                options: ['auto', 'approve', 'block'],
            ),
            'COMPUTE_WORKSPACE_TTL_DAYS' => new FieldDefinition(
                type: 'number',
                description: 'How many days a file-work folder may sit unused before cleanup can remove it. Default 90.',
                default: (string) ComputeConfig::DEFAULT_WORKSPACE_TTL_DAYS,
                row: new DatabaseRow(ComputeConfig::CONFIG_GROUP, ComputeConfig::KEY_WORKSPACE_TTL_DAYS),
            ),
        ];
    }
}
