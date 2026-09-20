<?php

declare(strict_types=1);

namespace App\Service\SelfAware;

use App\AI\Credential\ChatReadinessService;
use App\Entity\User;
use App\Module\ModuleRegistry;
use App\Plug\WebSearch\WebSearchGateway;
use App\Repository\ConnectionRepository;
use App\Repository\PromptRepository;
use App\Repository\UserRepository;
use App\Service\Agent\AgentConfig;
use App\Service\BillingService;
use App\Service\Capability\CapabilityService;
use App\Service\Compute\ComputeConfig;
use App\Service\Desktop\DesktopAgentConfig;
use App\Service\Iam\IamConfig;
use App\Service\MailerConfig;
use App\Service\Mcp\McpClientConfig;
use App\Service\ModelConfigService;
use App\Service\Multitask\MultitaskRoutingConfig;
use App\Service\Plugin\PluginManager;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use App\Service\SavedTask\SavedTaskConfig;
use App\Service\Tool\ToolsConfig;
use App\Service\Update\UpdateStatusService;

/**
 * Builds a live capability report from sources that already gate behaviour
 * (invariant C6). There is no hand-maintained feature list — only
 * {@see self::KNOWN_ABSENT}, because an absence cannot be derived from a
 * registry.
 */
final readonly class PlatformCapabilityInventory implements CapabilityInventory
{
    /**
     * Head of the upload-format list kept verbatim before a "+N more" suffix.
     * Long enough to name the everyday formats (TXT, PDF, DOCX, …).
     */
    private const UPLOAD_FORMAT_HEAD_CHARS = 120;

    /**
     * Deliberately unsupported capabilities. Reviewed on every release that
     * adds a capability (see docs/ADMIN.md).
     *
     * @var list<array{id: string, label: string, detail: string, alternative: string, adminHint: ?string, docsSlug: ?string}>
     */
    public const KNOWN_ABSENT = [
        [
            'id' => 'music_generation',
            'label' => 'Composing or producing music',
            'detail' => 'No music or song model exists',
            'alternative' => 'original lyrics in that style',
            'adminHint' => null,
            'docsSlug' => null,
        ],
        [
            'id' => 'code_execution',
            'label' => 'Running arbitrary code',
            'detail' => 'The assistant cannot execute Python, shell, or other code on the server',
            'alternative' => 'Synaplan Desktop skills on the user\'s computer',
            'adminHint' => 'Manage → Developer & devices → Desktop',
            'docsSlug' => 'desktop-skills',
        ],
        [
            'id' => 'phone_calls',
            'label' => 'Phone calls',
            'detail' => 'The assistant cannot place or receive voice calls',
            'alternative' => 'WhatsApp or email',
            'adminHint' => null,
            'docsSlug' => 'channels',
        ],
        [
            'id' => 'authenticated_browsing',
            'label' => 'Browsing sites behind a login',
            'detail' => 'Web fetch only reads public pages',
            'alternative' => 'paste the text or connect an MCP server',
            'adminHint' => 'Manage → Connections → MCP Servers',
            'docsSlug' => 'mcp',
        ],
        [
            'id' => 'pdf_inplace_editing',
            'label' => 'Editing an existing PDF in place',
            'detail' => 'PDFs can be analysed; they cannot be rewritten as PDF',
            'alternative' => 'analyse the PDF and regenerate the result as DOCX',
            'adminHint' => null,
            'docsSlug' => 'using-synaplan',
        ],
        [
            'id' => 'live_human_operator_in_chat',
            'label' => 'A live human operator in this chat',
            'detail' => 'This conversation is with the AI assistant',
            'alternative' => 'widget live support (operator-side)',
            'adminHint' => null,
            'docsSlug' => 'architecture',
        ],
    ];

    public function __construct(
        private ChatReadinessService $chatReadiness,
        private ModelConfigService $modelConfig,
        private VectorStorageFacade $vectorStorage,
        private WebSearchGateway $webSearch,
        private MultitaskRoutingConfig $routingConfig,
        private MailerConfig $mailerConfig,
        private SavedTaskConfig $savedTaskConfig,
        private DesktopAgentConfig $desktopAgentConfig,
        private McpClientConfig $mcpClientConfig,
        private PluginManager $pluginManager,
        private CapabilityService $capabilityService,
        private PromptRepository $promptRepository,
        private UpdateStatusService $updateStatus,
        private BillingService $billingService,
        private ConnectionRepository $connectionRepository,
        private UserRepository $userRepository,
        private ModuleRegistry $modules,
        private AgentConfig $agentConfig,
        private ToolsConfig $toolsConfig,
        private IamConfig $iamConfig,
        private ?ComputeConfig $computeConfig = null,
    ) {
    }

    public function build(int $userId): CapabilityReport
    {
        $user = $userId > 0 ? $this->userRepository->find($userId) : null;
        $isAdmin = $user instanceof User && $user->isAdmin();
        $chatReady = $this->chatReadiness->isChatReady(userId: $userId > 0 ? $userId : null);
        $ttsAvailable = $this->modelResolves('TEXT2SOUND', $userId) || $this->moduleConfigured('text_to_speech');

        $facts = [];
        $facts[] = $this->fact(
            'chat',
            'Chat',
            $chatReady,
            $chatReady ? 'workspace model' : 'no chat provider key configured',
            'ask your administrator to connect an AI provider',
            'Operate → AI infrastructure → Models & keys',
            'using-synaplan',
        );
        $fileAnalysisReady = $this->modelResolves('PIC2TEXT', $userId) && $chatReady;
        $facts[] = $this->fact(
            'file_analysis',
            'File analysis',
            $fileAnalysisReady,
            $fileAnalysisReady ? 'documents, images, audio' : 'no vision / analysis model configured',
            'upload the file once a vision / analysis model is configured',
            'Operate → AI infrastructure → Models & keys',
            'using-synaplan',
        );
        $vectorizeReady = $this->modelResolves('VECTORIZE', $userId);
        $facts[] = $this->fact(
            'knowledge_search',
            'Knowledge search over your files',
            $vectorizeReady,
            $vectorizeReady ? $this->vectorStorage->getProviderName() : 'no embedding model configured',
            'upload files after an embedding model is set',
            'Operate → AI infrastructure → Models & keys',
            'using-synaplan',
        );
        $qdrantConfigured = $this->envNonEmpty('QDRANT_URL');
        $facts[] = $this->fact(
            'memories',
            'Memories',
            $qdrantConfigured,
            $qdrantConfigured ? 'Qdrant' : 'Qdrant is not configured',
            'memories remember preferences across chats once Qdrant is set',
            'Set QDRANT_URL',
            'using-synaplan',
        );
        $imageReady = $this->modelResolves('TEXT2PIC', $userId);
        $facts[] = $this->fact(
            'image_generation',
            'Image generation (/pic)',
            $imageReady,
            $imageReady ? '' : 'no image model configured',
            'describe the image in words, or add an image model',
            'Operate → AI infrastructure → Models & keys',
            'using-synaplan',
        );
        $videoReady = $this->modelResolves('TEXT2VID', $userId);
        $facts[] = $this->fact(
            'video_generation',
            'Video generation (/vid)',
            $videoReady,
            $videoReady ? '' : 'no video model configured',
            'image generation is the nearest alternative',
            'Operate → AI infrastructure → Models & keys',
            'using-synaplan',
        );
        $facts[] = $this->fact(
            'text_to_speech',
            'Text-to-speech',
            $ttsAvailable,
            $ttsAvailable ? 'MP3' : 'no TTS model or SYNAPLAN_TTS_URL',
            'I can write the text; audio needs a TTS model or the local speech service',
            'Operate → AI infrastructure → Models & keys, or set SYNAPLAN_TTS_URL',
            'tts',
        );
        $facts[] = $this->fact(
            'speech_to_text',
            'Speech-to-text',
            $this->modelResolves('SOUND2TEXT', $userId),
            'transcribe uploads',
            'type the words, or add a transcription model',
            'Operate → AI infrastructure → Models & keys',
            'using-synaplan',
        );
        $webSearchOn = $this->webSearch->isEnabled($userId > 0 ? $userId : null);
        $facts[] = $this->fact(
            'web_search',
            'Web search',
            $webSearchOn,
            $webSearchOn ? '/search' : 'no web search provider configured',
            'paste the text you want analysed',
            'Operate → AI infrastructure → Web search',
            'using-synaplan',
        );
        $facts[] = $this->flagFact(
            'url_fetch',
            'URL fetch',
            $userId,
            MultitaskRoutingConfig::KEY_URL_FETCH_ENABLED,
            true,
            'public pages',
            'paste the page text',
            'Operate → System configuration → Routing',
            'dag-routing',
        );
        $facts[] = $this->flagFact(
            'mcp_fetch',
            'MCP data sources',
            $userId,
            MultitaskRoutingConfig::KEY_MCP_FETCH_ENABLED,
            false,
            '',
            'paste the data, or connect an MCP server',
            'Manage → Connections → MCP Servers',
            'mcp',
        );
        $facts[] = $this->flagFact(
            'mcp_action',
            'MCP write actions',
            $userId,
            MultitaskRoutingConfig::KEY_MCP_ACTION_ENABLED,
            false,
            '',
            'do the write in that system, or enable MCP write actions',
            'Manage → Connections → MCP Servers → allow write actions',
            'mcp',
        );
        $facts[] = $this->flagFact(
            'email_search',
            'Mailbox search',
            $userId,
            MultitaskRoutingConfig::KEY_EMAIL_SEARCH_ENABLED,
            false,
            '',
            'paste the mail, or connect a mailbox under Channels',
            'Manage → Channels → Email handler',
            'channels',
        );
        $pdfReady = $this->moduleConfigured('pdf_export');
        $facts[] = new CapabilityFact(
            'document_generation',
            'Documents',
            CapabilityState::Available,
            $pdfReady ? 'DOCX, XLSX, PPTX, CSV, PDF' : 'DOCX, XLSX, PPTX, CSV',
            null,
            null,
            'using-synaplan',
        );
        $facts[] = $this->fact(
            'pdf_export',
            'PDF export',
            $pdfReady,
            $pdfReady ? '' : 'office engine not configured',
            'DOCX, XLSX, PPTX, CSV',
            'office engine (OFFICE_CONVERT_URL)',
            'using-synaplan',
        );
        $facts[] = new CapabilityFact(
            'calendar_event',
            'Calendar invites (.ics)',
            CapabilityState::Available,
            'download; Outlook when connected',
            null,
            null,
            'using-synaplan',
        );
        $mailerOn = $this->mailerConfig->isConfigured();
        $facts[] = $this->fact(
            'email_me',
            'Email a result',
            $mailerOn,
            $mailerOn ? '' : 'mailer not configured',
            'download the file in chat',
            'Set MAILER_DSN',
            'channels',
        );
        $hasWebDav = $this->userHasWebDav($userId);
        $facts[] = $this->fact(
            'save_to_folder',
            'Save to a folder',
            $hasWebDav,
            $hasWebDav ? 'WebDAV / Nextcloud / OpenCloud / ownCloud destination' : 'no folder destination connected',
            'download the file in chat',
            'Manage → Connections → Connected apps',
            'using-synaplan',
        );
        $savedTasksOn = $this->savedTaskConfig->isEnabled($userId > 0 ? $userId : null);
        $facts[] = $this->fact(
            'saved_tasks',
            'Saved tasks',
            $savedTasksOn,
            $savedTasksOn ? 'pin a plan; schedules, webhooks' : 'not enabled',
            'ask me to do the steps again in chat',
            'Manage → Automations → Saved tasks',
            'using-synaplan',
        );
        $desktopOn = $this->desktopAgentConfig->isEnabled($userId > 0 ? $userId : null);
        $facts[] = $this->fact(
            'desktop_skills',
            'Desktop skills',
            $desktopOn,
            $desktopOn ? 'skills on your computer' : 'not enabled',
            'ask me to draft the steps here',
            'Manage → Developer & devices → Desktop',
            'desktop-skills',
        );
        $mcpServerOn = $this->mcpClientConfig->isClientEnabled($userId > 0 ? $userId : null);
        $facts[] = $this->fact(
            'mcp_server',
            'MCP client',
            $mcpServerOn,
            $mcpServerOn ? '' : 'not enabled',
            'paste the data from that system',
            'Manage → Connections → MCP Servers',
            'mcp',
        );
        $whatsAppOn = $this->moduleConfigured('channel_whatsapp')
            && $user instanceof User
            && $user->hasVerifiedPhone();
        $facts[] = $this->fact(
            'channel_whatsapp',
            'WhatsApp',
            $whatsAppOn,
            $whatsAppOn ? 'verified phone on this account' : 'WhatsApp is not configured for this user',
            'use this web chat, or add a verified phone number',
            'Profile → phone number; operator: WhatsApp access token',
            'channels',
        );
        $emailKeyword = $user instanceof User ? trim((string) ($user->getUserDetails()['email_keyword'] ?? '')) : '';
        $emailChannelOn = $mailerOn && '' !== $emailKeyword;
        $facts[] = $this->fact(
            'channel_email',
            'Email',
            $emailChannelOn,
            $emailChannelOn ? 'inbound email keyword set' : 'no inbound email keyword',
            'use this web chat, or set an email keyword in the profile',
            'Profile → email keyword; operator: MAILER_DSN',
            'channels',
        );

        $pluginDetail = $this->pluginDetail($userId);
        $facts[] = $this->fact(
            'plugins',
            'Plugins',
            '' !== $pluginDetail,
            '' !== $pluginDetail ? $pluginDetail : 'no chat-command plugins installed',
            'ask in this chat without a plugin command',
            'install a plugin from the plugin list',
            'plugins',
        );
        $facts[] = new CapabilityFact(
            'upload_formats',
            'Upload formats',
            CapabilityState::Available,
            $this->uploadFormatList(),
            null,
            null,
            'using-synaplan',
        );
        $customTopics = $this->customTopicNames($userId);
        $assistantsOn = $this->agentConfig->isEnabled($userId > 0 ? $userId : null);
        $topicBuilderHint = $assistantsOn ? 'Manage → Assistants → Assistants' : 'AI Instructions → new topic';
        $facts[] = $this->fact(
            'custom_topics',
            'Custom topics',
            [] !== $customTopics,
            [] !== $customTopics ? implode(', ', $customTopics) : 'no user-owned topics',
            'use the built-in topics, or create one under '.$topicBuilderHint,
            $topicBuilderHint,
            'using-synaplan',
        );
        $facts[] = $this->fact(
            'assistants',
            'Assistants',
            $assistantsOn,
            $assistantsOn ? 'build, publish and share assistants' : 'assistants are off',
            'use the built-in topics, or ask your administrator to switch assistants on',
            'Operate → System configuration → Features → AI assistants',
            'assistants',
        );
        $approvalsOn = $this->toolsConfig->isApprovalsEnabled($userId > 0 ? $userId : null);
        $facts[] = $this->fact(
            'approvals',
            'Approvals',
            $approvalsOn,
            $approvalsOn ? 'write actions wait in chat and inbox' : 'approvals are off',
            'actions run without asking once approvals are on',
            'Operate → System configuration → Features → Tools & approvals',
            'tools-and-approvals',
        );
        $customToolsOn = $this->toolsConfig->isCustomHttpEnabled($userId > 0 ? $userId : null);
        $facts[] = $this->fact(
            'custom_tools',
            'Custom HTTP tools',
            $customToolsOn,
            $customToolsOn ? 'your own APIs as chat tools' : 'custom tools are off',
            'paste the API result into chat',
            'Operate → System configuration → Features → Tools & approvals',
            'tools-and-approvals',
        );
        $sharingOn = $this->iamConfig->isSharingEnabled($userId > 0 ? $userId : null);
        $facts[] = $this->fact(
            'sharing',
            'Sharing',
            $sharingOn,
            $sharingOn ? 'share folders, chats, assistants, tasks, widgets' : 'sharing is off',
            'send a copy instead, or ask your administrator to switch sharing on',
            'Operate → System configuration → Features → People & sharing',
            'people-and-groups',
        );

        foreach (self::KNOWN_ABSENT as $row) {
            if ('code_execution' === $row['id'] && true === $this->computeConfig?->isEnabled($userId > 0 ? $userId : null)) {
                $facts[] = $this->fact(
                    $row['id'],
                    'File work',
                    true,
                    'Python/Node runs on copies of chosen files',
                    $row['alternative'],
                    'Operate → Feature status → Secure compute',
                    'modules/compute',
                );
                continue;
            }
            $alternative = $row['alternative'];
            if ('music_generation' === $row['id'] && $ttsAvailable) {
                $alternative = 'original lyrics, read aloud as MP3';
            }
            $facts[] = new CapabilityFact(
                $row['id'],
                $row['label'],
                CapabilityState::Absent,
                $row['detail'],
                $alternative,
                $row['adminHint'],
                $row['docsSlug'],
            );
        }

        return new CapabilityReport(
            $facts,
            $this->versionLabel(),
            $this->billingService->isEnabled(),
            $isAdmin,
        );
    }

    public function forget(?int $userId = null): void
    {
        // Uncached implementation — the decorator owns the cache.
    }

    private function fact(
        string $id,
        string $label,
        bool $available,
        string $detail,
        string $alternative,
        string $adminHint,
        ?string $docsSlug,
    ): CapabilityFact {
        if ($available) {
            return new CapabilityFact(
                $id,
                $label,
                CapabilityState::Available,
                $detail,
                null,
                $adminHint,
                $docsSlug,
            );
        }

        return new CapabilityFact(
            $id,
            $label,
            CapabilityState::NeedsSetup,
            $detail,
            $alternative,
            $adminHint,
            $docsSlug,
        );
    }

    private function flagFact(
        string $id,
        string $label,
        int $userId,
        string $flag,
        bool $default,
        string $availableDetail,
        string $alternative,
        string $adminHint,
        string $docsSlug,
        string $needsDetail = 'not enabled',
    ): CapabilityFact {
        $on = $this->routingConfig->isFeatureEnabled($flag, $userId > 0 ? $userId : null, $default);

        return $this->fact($id, $label, $on, $on ? $availableDetail : $needsDetail, $alternative, $adminHint, $docsSlug);
    }

    private function modelResolves(string $capability, int $userId): bool
    {
        return null !== $this->modelConfig->getDefaultModel($capability, $userId > 0 ? $userId : null);
    }

    /**
     * "Configured" as declared by the feature module that owns the capability
     * (`FeatureModuleInterface::capabilityIds()`), so this inventory and the
     * feature-status page can never disagree about a sidecar or channel.
     */
    private function moduleConfigured(string $capabilityId): bool
    {
        return $this->modules->forCapability($capabilityId)?->isConfigured() ?? false;
    }

    private function envNonEmpty(string $key): bool
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if (!is_string($value)) {
            return false;
        }

        return '' !== trim($value);
    }

    private function userHasWebDav(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        foreach ($this->connectionRepository->findByOwner($userId) as $connection) {
            if ('webdav' === $connection->getType()) {
                return true;
            }
        }

        return false;
    }

    private function pluginDetail(int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }
        $parts = [];
        foreach ($this->pluginManager->listInstalledPlugins($userId) as $plugin) {
            $commands = [];
            foreach ($plugin->chatCommands as $entry) {
                $command = ltrim($entry['command'], '/');
                if ('' !== $command) {
                    $commands[] = '/'.$command;
                }
            }
            if ([] === $commands) {
                continue;
            }
            $parts[] = $plugin->name.' ('.implode(', ', $commands).')';
        }

        return implode(', ', $parts);
    }

    private function uploadFormatList(): string
    {
        $formats = $this->capabilityService->getCapabilities()['file_formats'];
        $flat = [];
        foreach ($formats as $list) {
            foreach ($list as $ext) {
                if ('' !== $ext) {
                    $flat[] = strtoupper($ext);
                }
            }
        }
        if ([] === $flat) {
            return 'common documents, images, audio and video';
        }
        // The full list is ~200 characters; keep the head and an honest count
        // so the prompt block stays inside its budget.
        $head = [];
        foreach ($flat as $ext) {
            $candidate = [] === $head ? $ext : implode(', ', $head).', '.$ext;
            if (strlen($candidate) > self::UPLOAD_FORMAT_HEAD_CHARS) {
                break;
            }
            $head[] = $ext;
        }
        $rest = count($flat) - count($head);
        if ($rest > 0) {
            return implode(', ', $head).' +'.$rest.' more';
        }

        return implode(', ', $flat);
    }

    /**
     * @return list<string>
     */
    private function customTopicNames(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        $names = [];
        foreach ($this->promptRepository->getTopicsWithDescriptions(0, '', $userId, excludeTools: true) as $row) {
            if (($row['ownerId'] ?? 0) === $userId) {
                $names[] = (string) $row['topic'];
            }
        }

        return $names;
    }

    private function versionLabel(): string
    {
        $status = $this->updateStatus->getStatus();
        $current = $status['currentVersion'];
        $latest = $status['latestVersion'];
        if (is_string($latest) && '' !== $latest && !empty($status['updateAvailable'])) {
            return $current.' (published '.$latest.')';
        }

        return $current;
    }
}
