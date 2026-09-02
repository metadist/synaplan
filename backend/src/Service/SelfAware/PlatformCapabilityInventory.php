<?php

declare(strict_types=1);

namespace App\Service\SelfAware;

use App\AI\Credential\ChatReadinessService;
use App\Entity\User;
use App\Repository\ConnectionRepository;
use App\Repository\PromptRepository;
use App\Repository\UserRepository;
use App\Service\BillingService;
use App\Service\Capability\CapabilityService;
use App\Service\Desktop\DesktopAgentConfig;
use App\Service\MailerConfig;
use App\Service\Mcp\McpClientConfig;
use App\Service\ModelConfigService;
use App\Service\Multitask\MultitaskRoutingConfig;
use App\Service\Plugin\PluginManager;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use App\Service\SavedTask\SavedTaskConfig;
use App\Service\Search\BraveSearchService;
use App\Service\Update\UpdateStatusService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds a live capability report from sources that already gate behaviour
 * (invariant C6). There is no hand-maintained feature list — only
 * {@see self::KNOWN_ABSENT}, because an absence cannot be derived from a
 * registry.
 */
final readonly class PlatformCapabilityInventory implements CapabilityInventory
{
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
            'adminHint' => 'Channels → Desktop',
            'docsSlug' => 'desktop-skills',
        ],
        [
            'id' => 'phone_calls',
            'label' => 'Phone calls',
            'detail' => 'The assistant cannot place or receive voice calls',
            'alternative' => 'WhatsApp or email when those channels are configured',
            'adminHint' => null,
            'docsSlug' => 'channels',
        ],
        [
            'id' => 'authenticated_browsing',
            'label' => 'Browsing sites behind a login',
            'detail' => 'Web fetch only reads public pages',
            'alternative' => 'paste the text, or connect an MCP server for that system',
            'adminHint' => 'Channels → MCP Servers',
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
            'alternative' => 'widget live support is an operator-side feature',
            'adminHint' => null,
            'docsSlug' => 'architecture',
        ],
    ];

    public function __construct(
        private ChatReadinessService $chatReadiness,
        private ModelConfigService $modelConfig,
        private VectorStorageFacade $vectorStorage,
        private BraveSearchService $braveSearch,
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
        #[Autowire('%env(bool:WHATSAPP_ENABLED)%')]
        private bool $whatsappEnabled = false,
        #[Autowire('%env(WHATSAPP_ACCESS_TOKEN)%')]
        private string $whatsappAccessToken = '',
    ) {
    }

    public function build(int $userId): CapabilityReport
    {
        $user = $userId > 0 ? $this->userRepository->find($userId) : null;
        $isAdmin = $user instanceof User && $user->isAdmin();
        $chatReady = $this->chatReadiness->isChatReady(userId: $userId > 0 ? $userId : null);
        $ttsAvailable = $this->modelResolves('TEXT2SOUND', $userId) || $this->ttsUrlConfigured();

        $facts = [];
        $facts[] = $this->fact(
            'chat',
            'Chat',
            $chatReady,
            $chatReady ? 'AI chat with the workspace model' : 'no chat provider key configured',
            'ask your administrator to connect an AI provider',
            'System Config → AI Providers',
            'using-synaplan',
        );
        $facts[] = $this->fact(
            'file_analysis',
            'File analysis',
            $this->modelResolves('PIC2TEXT', $userId) && $chatReady,
            'PDF, Word, Excel, images, audio',
            'upload the file once a vision / analysis model is configured',
            'System Config → AI Models → add a PIC2TEXT model',
            'using-synaplan',
        );
        $vectorizeReady = $this->modelResolves('VECTORIZE', $userId);
        $facts[] = $this->fact(
            'knowledge_search',
            'Knowledge search over your files',
            $vectorizeReady,
            $vectorizeReady ? $this->vectorStorage->getProviderName() : 'no embedding model configured',
            'upload files after an embedding model is set',
            'System Config → AI Models → VECTORIZE',
            'using-synaplan',
        );
        $qdrantConfigured = $this->envNonEmpty('QDRANT_URL');
        $facts[] = $this->fact(
            'memories',
            'Memories',
            $qdrantConfigured,
            $qdrantConfigured ? 'Qdrant memory service' : 'Qdrant is not configured',
            'memories remember preferences across chats once Qdrant is set',
            'Set QDRANT_URL',
            'using-synaplan',
        );
        $facts[] = $this->fact(
            'image_generation',
            'Image generation (/pic)',
            $this->modelResolves('TEXT2PIC', $userId),
            'ask for an image or use /pic',
            'describe the image in words, or add an image model',
            'System Config → AI Models → add an image model',
            'using-synaplan',
        );
        $facts[] = $this->fact(
            'video_generation',
            'Video generation (/vid)',
            $this->modelResolves('TEXT2VID', $userId),
            'ask for a video or use /vid',
            'image generation is the nearest alternative',
            'System Config → AI Models → add a video model',
            'using-synaplan',
        );
        $facts[] = $this->fact(
            'text_to_speech',
            'Text-to-speech',
            $ttsAvailable,
            $ttsAvailable ? 'MP3' : 'no TTS model or SYNAPLAN_TTS_URL',
            'I can write the text; audio needs a TTS model or the local speech service',
            'System Config → AI Models → TEXT2SOUND, or set SYNAPLAN_TTS_URL',
            'tts',
        );
        $facts[] = $this->fact(
            'speech_to_text',
            'Speech-to-text',
            $this->modelResolves('SOUND2TEXT', $userId),
            'upload an audio file to transcribe',
            'type the words, or add a transcription model',
            'System Config → AI Models → SOUND2TEXT',
            'using-synaplan',
        );
        $webSearchOn = $this->braveSearch->isEnabled();
        $facts[] = $this->fact(
            'web_search',
            'Web search',
            $webSearchOn,
            $webSearchOn ? 'ask for current information or /search' : 'no Brave search key',
            'paste the text you want analysed',
            'System Config → set the Brave search API key',
            'using-synaplan',
        );
        $facts[] = $this->flagFact(
            'url_fetch',
            'URL fetch',
            $userId,
            MultitaskRoutingConfig::KEY_URL_FETCH_ENABLED,
            true,
            'read a public page the user named',
            'paste the page text',
            'System Config → Multi-task → URL fetch',
            'dag-routing',
        );
        $facts[] = $this->flagFact(
            'mcp_fetch',
            'MCP data sources',
            $userId,
            MultitaskRoutingConfig::KEY_MCP_FETCH_ENABLED,
            false,
            'read from connected MCP servers',
            'paste the data, or connect an MCP server',
            'Channels → MCP Servers',
            'mcp',
        );
        $facts[] = $this->flagFact(
            'mcp_action',
            'MCP write actions',
            $userId,
            MultitaskRoutingConfig::KEY_MCP_ACTION_ENABLED,
            false,
            'create or update items on write-enabled MCP servers',
            'do the write in that system, or enable MCP write actions',
            'Channels → MCP Servers → allow write actions',
            'mcp',
        );
        $facts[] = $this->flagFact(
            'email_search',
            'Mailbox search',
            $userId,
            MultitaskRoutingConfig::KEY_EMAIL_SEARCH_ENABLED,
            false,
            'search a connected mailbox',
            'paste the mail, or connect a mailbox under Channels',
            'Channels → Email / Microsoft 365',
            'channels',
        );
        $facts[] = new CapabilityFact(
            'document_generation',
            'Documents',
            CapabilityState::Available,
            'DOCX, XLSX, PPTX, CSV',
            null,
            null,
            'using-synaplan',
        );
        $pdfReady = $this->envNonEmpty('OFFICE_CONVERT_URL');
        $facts[] = $this->fact(
            'pdf_export',
            'PDF export',
            $pdfReady,
            $pdfReady ? 'office engine' : 'office engine not configured',
            'DOCX, XLSX, PPTX, CSV',
            'office engine (OFFICE_CONVERT_URL)',
            'using-synaplan',
        );
        $facts[] = new CapabilityFact(
            'calendar_event',
            'Calendar invites (.ics)',
            CapabilityState::Available,
            '.ics download; Outlook when connected',
            null,
            null,
            'using-synaplan',
        );
        $mailerOn = $this->mailerConfig->isConfigured();
        $facts[] = $this->fact(
            'email_me',
            'Email a result',
            $mailerOn,
            $mailerOn ? 'mailer configured' : 'mailer not configured',
            'download the file in chat',
            'Set MAILER_DSN',
            'channels',
        );
        $hasWebDav = $this->userHasWebDav($userId);
        $facts[] = $this->fact(
            'save_to_folder',
            'Save to a folder',
            $hasWebDav,
            $hasWebDav ? 'WebDAV / Nextcloud / ownCloud destination' : 'no folder destination connected',
            'download the file in chat',
            'Channels → Connections',
            'using-synaplan',
        );
        $savedTasksOn = $this->savedTaskConfig->isEnabled($userId > 0 ? $userId : null);
        $facts[] = $this->fact(
            'saved_tasks',
            'Saved tasks',
            $savedTasksOn,
            $savedTasksOn ? 'pin a plan and run it again' : 'not enabled',
            'ask me to do the steps again in chat',
            'Channels → Saved Tasks',
            'using-synaplan',
        );
        $desktopOn = $this->desktopAgentConfig->isEnabled($userId > 0 ? $userId : null);
        $facts[] = $this->fact(
            'desktop_skills',
            'Desktop skills',
            $desktopOn,
            $desktopOn ? 'run skills on the user\'s computer' : 'not enabled',
            'ask me to draft the steps here',
            'Channels → Desktop',
            'desktop-skills',
        );
        $mcpServerOn = $this->mcpClientConfig->isClientEnabled($userId > 0 ? $userId : null);
        $facts[] = $this->fact(
            'mcp_server',
            'MCP client',
            $mcpServerOn,
            $mcpServerOn ? 'connect external MCP servers' : 'not enabled',
            'paste the data from that system',
            'Channels → MCP Servers',
            'mcp',
        );
        $whatsAppOn = $this->whatsappEnabled
            && '' !== trim($this->whatsappAccessToken)
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
        $facts[] = $this->fact(
            'custom_topics',
            'Custom topics',
            [] !== $customTopics,
            [] !== $customTopics ? implode(', ', $customTopics) : 'no user-owned topics',
            'use the built-in topics, or create one under AI Instructions',
            'AI Instructions → new topic',
            'using-synaplan',
        );

        foreach (self::KNOWN_ABSENT as $row) {
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
    ): CapabilityFact {
        $on = $this->routingConfig->isFeatureEnabled($flag, $userId > 0 ? $userId : null, $default);

        return $this->fact($id, $label, $on, $availableDetail, $alternative, $adminHint, $docsSlug);
    }

    private function modelResolves(string $capability, int $userId): bool
    {
        return null !== $this->modelConfig->getDefaultModel($capability, $userId > 0 ? $userId : null);
    }

    private function ttsUrlConfigured(): bool
    {
        return $this->envNonEmpty('SYNAPLAN_TTS_URL');
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

        return [] === $flat ? 'common documents, images, audio and video' : implode(', ', $flat);
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
