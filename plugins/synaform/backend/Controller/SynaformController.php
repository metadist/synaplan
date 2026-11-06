<?php

declare(strict_types=1);

namespace Plugin\Synaform\Controller;

use App\AI\Service\AiFacade;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Repository\PluginDataRepository;
use App\Service\File\FileHelper;
use App\Service\File\FileProcessor;
use App\Service\ModelConfigService;
use App\Service\PluginDataService;
use App\Service\RateLimitService;
use OpenApi\Attributes as OA;
use PhpOffice\PhpWord\IOFactory as PhpWordIOFactory;
use PhpOffice\PhpWord\TemplateProcessor;
use Plugin\Synaform\Service\TemplateHtmlPreviewService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route('/api/v1/user/{userId}/plugins/synaform', name: 'api_plugin_synaform_')]
#[OA\Tag(name: 'Synaform Plugin')]
class SynaformController extends AbstractController
{
    private const PLUGIN_NAME = 'synaform';
    private const CONFIG_GROUP = 'P_synaform';
    private const DATA_TYPE_FORM = 'synaform_form';
    private const DATA_TYPE_CANDIDATE = 'synaform_candidate';
    private const DATA_TYPE_TEMPLATE = 'synaform_template';
    private const DATA_TYPE_VALIDATION = 'synaform_validation';
    private const DATA_TYPE_PROMPTS = 'synaform_prompts';

    /**
     * The AI prompt sections a user may customise from the "Prompts" tab.
     * Each key maps to a default text returned by defaultPromptText();
     * per-user overrides live in plugin_data (DATA_TYPE_PROMPTS, id
     * 'prompts'). Keys never leak into prompts themselves — they are only
     * the registry handle shared between backend and frontend.
     */
    private const EDITABLE_PROMPT_KEYS = [
        'extraction_rules',
        'extraction_context',
        'generation_transform',
    ];

    // `eml` / `msg` let users drop an e-mail straight in as a source — core's
    // FileProcessor routes non-image/-media files to Apache Tika, which reads
    // RFC822 (.eml) and Outlook (.msg) natively. Extension-based allowlist, so
    // a .msg arriving as application/octet-stream still passes.
    // HEIC/HEIF: iPhone camera photos; FileProcessor transcodes them for Vision.
    private const ALLOWED_UPLOAD_EXTENSIONS = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'tiff', 'tif', 'bmp', 'heic', 'heif', 'txt', 'rtf', 'odt', 'xls', 'xlsx', 'pptx', 'eml', 'msg'];

    /**
     * Hard cap for a single source-document upload. Stays under Cloudflare's
     * ~200 MB body limit and PHP's 200M upload_max_filesize so a too-large
     * iPhone scan is rejected here with a clear JSON error instead of an
     * empty $_FILES / HTML 413.
     */
    private const MAX_SOURCE_UPLOAD_BYTES = 80 * 1024 * 1024;

    /** Template .docx files are far smaller than scans. */
    private const MAX_TEMPLATE_UPLOAD_BYTES = 40 * 1024 * 1024;

    /**
     * Wall-clock budget for parse-documents. Production sits behind
     * Cloudflare, which returns HTML 524 if the origin is silent for ~100 s.
     * Returning JSON before that keeps the failure visible and lets the
     * frontend auto-continue (OCR is cached per file).
     */
    private const PARSE_REQUEST_BUDGET_SECONDS = 80.0;

    /** Don't start another Vision/Tika extract if less than this remains. */
    private const PARSE_MIN_SECONDS_FOR_OCR = 12.0;

    /** Don't start the AI fill pass if less than this remains. */
    private const PARSE_MIN_SECONDS_FOR_AI = 20.0;

    private const PARSE_TIMEOUT_FILE_MESSAGE = 'Timed out while reading this large scan. Already-read files are cached — run auto-fill again to continue.';

    /**
     * Row-group sub-fields whose string value is too rich for a single Word run
     * and must be rendered as a sequence of paragraphs (date headers, sub-titles,
     * real bullet items). Always-on defaults; any `table` field whose column
     * declares `type=list` is appended at runtime by getRichRowSubfields().
     */
    private const RICH_ROW_SUBFIELDS_DEFAULT = ['stations.details'];

    private const DEFAULT_VARIABLE_SOURCES = [
        'firstname' => ['primary' => 'form', 'fallback' => 'ai'],
        'lastname' => ['primary' => 'form', 'fallback' => 'ai'],
        'fullname' => ['primary' => 'ai', 'fallback' => 'form'],
        'address1' => ['primary' => 'ai', 'fallback' => 'form'],
        'address2' => ['primary' => 'ai', 'fallback' => 'form'],
        'zip' => ['primary' => 'ai', 'fallback' => 'form'],
        'birthdate' => ['primary' => 'ai', 'fallback' => 'form'],
        'nationality' => ['primary' => 'form'],
        'maritalstatus' => ['primary' => 'form'],
        'number' => ['primary' => 'ai', 'fallback' => 'form'],
        'email' => ['primary' => 'ai', 'fallback' => 'form'],
        'target-position' => ['primary' => 'form'],
        'currentposition' => ['primary' => 'ai', 'fallback' => 'form'],
        'relevantposlist' => ['primary' => 'form'],
        'relevantfortargetposlist' => ['primary' => 'form', 'fallback' => 'ai'],
        'education' => ['primary' => 'ai', 'fallback' => 'form'],
        'moving' => ['primary' => 'form'],
        'travelorcommute' => ['primary' => 'form'],
        'commute' => ['primary' => 'form'],
        'travel' => ['primary' => 'form'],
        'noticeperiod' => ['primary' => 'form'],
        'currentansalary' => ['primary' => 'form'],
        'expectedansalary' => ['primary' => 'form'],
        'workinghours' => ['primary' => 'form'],
        'benefits' => ['primary' => 'form'],
        'languageslist' => ['primary' => 'form', 'fallback' => 'ai'],
        'otherskillslist' => ['primary' => 'form', 'fallback' => 'ai'],
    ];

    public function __construct(
        private PluginDataService $pluginData,
        private PluginDataRepository $pluginDataRepository,
        private ConfigRepository $configRepository,
        private RateLimitService $rateLimitService,
        private ModelConfigService $modelConfigService,
        private ModelRepository $modelRepository,
        private LoggerInterface $logger,
        private AiFacade $aiFacade,
        private FileProcessor $fileProcessor,
        private TemplateHtmlPreviewService $htmlPreviewService,
        private HttpClientInterface $http,
        #[Autowire('%app.upload_dir%')] private string $uploadDir,
    ) {
    }

    // =========================================================================
    // Setup & Configuration
    // =========================================================================

    #[Route('/setup-check', name: 'setup_check', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/synaform/setup-check',
        summary: 'Check plugin setup status',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'Setup status')]
    public function setupCheck(int $userId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $candidateCount = $this->pluginData->count($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE);
        $templateCount = $this->pluginData->count($userId, self::PLUGIN_NAME, self::DATA_TYPE_TEMPLATE);
        $formCount = $this->pluginData->count($userId, self::PLUGIN_NAME, self::DATA_TYPE_FORM);
        $config = $this->getPluginConfig($userId);

        return $this->json([
            'success' => true,
            'status' => 'ready',
            'checks' => [
                'plugin_installed' => true,
                'has_forms' => $formCount > 0,
                'has_templates' => $templateCount > 0,
                'has_candidates' => $candidateCount > 0,
            ],
            'counts' => [
                'forms' => $formCount,
                'templates' => $templateCount,
                'candidates' => $candidateCount,
            ],
            'config' => $config,
        ]);
    }

    #[Route('/setup', name: 'setup', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/synaform/setup',
        summary: 'Initialize plugin with default form',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'Setup result')]
    public function setup(int $userId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $hadForms = $this->pluginData->count($userId, self::PLUGIN_NAME, self::DATA_TYPE_FORM) > 0;

        if (!$hadForms) {
            $this->seedDefaultForm($userId);
        }

        return $this->json([
            'success' => true,
            'message' => $hadForms
                ? 'Forms already exist, no changes made'
                : 'Plugin initialized with default form',
            'counts' => [
                'forms' => $this->pluginData->count($userId, self::PLUGIN_NAME, self::DATA_TYPE_FORM),
                'templates' => $this->pluginData->count($userId, self::PLUGIN_NAME, self::DATA_TYPE_TEMPLATE),
                'candidates' => $this->pluginData->count($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE),
            ],
        ]);
    }

    #[Route('/dashboard', name: 'dashboard', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/synaform/dashboard',
        summary: 'Plugin dashboard: Tika status, configured AI models, plugin counts',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'Dashboard payload')]
    public function dashboard(int $userId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        // Tika probe — direct curl against /version. Self-contained so the
        // dashboard works even if TikaClient is reconfigured later. Times
        // the round-trip so the UI can flag a slow/degraded Tika service.
        //
        // Auth: production runs Tika behind basic auth (the public host
        // http://tika.synaplan.com requires TIKA_HTTP_USER /
        // TIKA_HTTP_PASS — same env vars synaplan core's TikaClient
        // already uses). Without forwarding those creds, the probe got
        // HTTP 401 in production while real extractions worked fine.
        // Prefer TIKA_BASE_URL but fall back to TIKA_URL since some
        // deployments only set one of them in their env.
        $tikaUrl = rtrim((string) (
            $_ENV['TIKA_BASE_URL']
            ?? $_ENV['TIKA_URL']
            ?? getenv('TIKA_BASE_URL')
            ?: getenv('TIKA_URL')
            ?: 'http://tika:9998'
        ), '/');
        $tikaUser = (string) ($_ENV['TIKA_HTTP_USER'] ?? getenv('TIKA_HTTP_USER') ?: '');
        $tikaPass = (string) ($_ENV['TIKA_HTTP_PASS'] ?? getenv('TIKA_HTTP_PASS') ?: '');
        $tikaProbe = $this->probeHttpEndpoint(
            $tikaUrl.'/version',
            3,
            '' !== $tikaUser ? [$tikaUser, $tikaPass] : null,
        );
        $tika = [
            'enabled' => true,
            'url' => $tikaUrl,
            'auth' => '' !== $tikaUser ? 'basic (user='.$tikaUser.')' : 'none',
            'reachable' => $tikaProbe['ok'],
            'http_code' => $tikaProbe['http'],
            'roundtrip_ms' => $tikaProbe['ms'],
            'version' => $tikaProbe['ok'] ? trim((string) $tikaProbe['body']) : null,
            'error' => $tikaProbe['error'],
        ];

        // AI configuration. Both rows resolve the user's actual saved
        // selection: the text-analytics model reads
        // BCONFIG.DEFAULTMODEL.ANALYZE (the "Text Analytics" entry in the
        // synaplan model collection) with a CHAT fallback — exactly what
        // resolveAiModelOptions() / core's FileAnalysisHandler run. Vision
        // reads BCONFIG.DEFAULTMODEL.PIC2TEXT (the row the synaplan settings
        // UI writes when the user picks "Bilderkennung (Bild → Text)").
        // Provider is derived from the BMODELS row of the saved model id,
        // so what we display matches what synaform actually calls.
        $analyzeModelId = $this->modelConfigService->getDefaultModel('ANALYZE', $userId)
            ?? $this->modelConfigService->getDefaultModel('CHAT', $userId);
        $analyzeProvider = $analyzeModelId ? $this->modelConfigService->getProviderForModel((int) $analyzeModelId) : null;

        $picTextModelId = $this->modelConfigService->getDefaultModel('PIC2TEXT', $userId);
        $picTextProvider = $picTextModelId
            ? $this->modelConfigService->getProviderForModel((int) $picTextModelId)
            : $this->modelConfigService->getDefaultProvider($userId, 'vision');
        $picTextModelName = $picTextModelId ? $this->modelConfigService->getModelName((int) $picTextModelId) : null;

        $ai = [
            'analyze' => [
                'provider' => $analyzeProvider,
                'model_id' => $analyzeModelId,
                'model_name' => $analyzeModelId ? $this->modelConfigService->getModelName((int) $analyzeModelId) : null,
                'role' => 'information_processing',
                'description' => 'Runs the AI prompts behind "Read files & auto-fill" and the variable-resolution extraction step. Called once per dataset, after all source documents have been turned into text.',
                'recommended' => $this->recommendedModelsFor('chat', (int) ($analyzeModelId ?? 0)),
            ],
            'vision' => [
                'provider' => $picTextProvider,
                'model_id' => $picTextModelId,
                'model_name' => $picTextModelName,
                'role' => 'image_processing',
                'description' => 'Reads text out of uploaded JPG/PNG scans and out of low-quality PDF pages (fallback). Called once per image; this is the dominant cost when the dataset sources are scans.',
                'recommended' => $this->recommendedModelsFor('pic2text', (int) ($picTextModelId ?? 0)),
            ],
        ];

        // Plugin-level counts so the dashboard can also act as a quick
        // status snapshot without the user opening every tab.
        $counts = [
            'forms' => $this->pluginData->count($userId, self::PLUGIN_NAME, self::DATA_TYPE_FORM),
            'templates' => $this->pluginData->count($userId, self::PLUGIN_NAME, self::DATA_TYPE_TEMPLATE),
            'candidates' => $this->pluginData->count($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE),
        ];

        return $this->json([
            'success' => true,
            'tika' => $tika,
            'ai' => $ai,
            'counts' => $counts,
            'extraction_strategies' => [
                'native_text' => 'text/plain, text/markdown, text/csv, text/html — instant',
                'tika' => 'PDF, DOCX, XLSX, PPTX — local Tika, ~ms',
                'vision_ai' => 'JPG, PNG, GIF, WEBP — Vision AI roundtrip, ~5–60 s per image',
                'rasterize_vision' => 'PDFs whose Tika output is empty/low-quality — Ghostscript + Vision AI per page',
            ],
            'generated_at' => date('c'),
        ]);
    }

    /**
     * Curated list of models we know give good results for synaform's
     * specific workload (structured field extraction from documents and
     * OCR on scanned PDFs/images). The frontend surfaces this in the
     * System Info card so users coming back to a degraded extraction
     * have a one-click route to a known-good model.
     *
     * Each entry is matched against the synaplan model catalogue (BMODELS)
     * by `service` + `model_id` (= BPROVID). Entries that aren't in the
     * catalogue are silently dropped so we never advertise a model the
     * user can't actually pick.
     *
     * The currently configured model is excluded from the recommendation
     * list — recommending what someone is already using just adds noise.
     *
     * @return list<array{service: string, model_id: string, label: string, reason: string}>
     */
    private function recommendedModelsFor(string $capability, int $currentModelId): array
    {
        $catalog = match ($capability) {
            'chat' => [
                ['service' => 'Anthropic', 'model_id' => 'claude-sonnet-4-6', 'label' => 'Claude Sonnet 4.6', 'reason' => 'Reliable structured JSON, strong field extraction.'],
                ['service' => 'OpenAI',    'model_id' => 'gpt-5.4',           'label' => 'GPT-5.4',           'reason' => 'Best-in-class accuracy on long documents.'],
                ['service' => 'Google',    'model_id' => 'gemini-2.5-pro',    'label' => 'Gemini 2.5 Pro',    'reason' => 'Excellent for long context + multilingual sources.'],
                ['service' => 'Anthropic', 'model_id' => 'claude-haiku-4-5-20251001', 'label' => 'Claude Haiku 4.5', 'reason' => 'Fastest reliable JSON-mode option.'],
            ],
            'pic2text', 'vision' => [
                ['service' => 'Google',    'model_id' => 'gemini-2.5-pro',  'label' => 'Gemini 2.5 Pro (Vision)',    'reason' => 'Best OCR quality, handles complex layouts.'],
                ['service' => 'Anthropic', 'model_id' => 'claude-sonnet-4-6', 'label' => 'Claude Sonnet 4.6 (Vision)', 'reason' => 'Excellent on handwriting and noisy scans.'],
                ['service' => 'OpenAI',    'model_id' => 'gpt-5.4',         'label' => 'GPT-5.4 (Vision)',           'reason' => 'Strong OCR + table extraction.'],
                ['service' => 'Google',    'model_id' => 'gemini-2.5-flash', 'label' => 'Gemini 2.5 Flash (Vision)',  'reason' => 'Fast + cheap fallback for simple pages.'],
            ],
            default => [],
        };

        $tag = 'chat' === $capability ? 'chat' : 'pic2text';

        // The same provider+model id can appear twice in BMODELS — once
        // with tag=chat and once with tag=pic2text (e.g. Claude Sonnet
        // 4.6 + Claude Sonnet 4.6 (Vision)). Pick the row that matches
        // the capability we're recommending for.
        $catalogEntries = $this->modelRepository->findByTag($tag, true);
        $byKey = [];
        foreach ($catalogEntries as $row) {
            $byKey[strtolower($row->getService()).'|'.strtolower($row->getProviderId() ?? '')] = $row;
        }

        $recommendations = [];
        foreach ($catalog as $entry) {
            $key = strtolower($entry['service']).'|'.strtolower($entry['model_id']);
            $model = $byKey[$key] ?? null;
            if (!$model || $model->getId() === $currentModelId) {
                continue;
            }

            $recommendations[] = [
                'model_id' => $model->getId(),
                'service' => $model->getService(),
                'label' => $entry['label'],
                'reason' => $entry['reason'],
                'name' => $model->getProviderId() ?: $model->getName(),
            ];

            if (count($recommendations) >= 3) {
                break;
            }
        }

        return $recommendations;
    }

    /**
     * Lightweight HTTP probe used by the dashboard to surface the Tika
     * (and potentially other) service status. Returns the HTTP code,
     * round-trip time in ms, raw body and any transport error so the
     * UI can show "reachable / degraded / down" with one glance.
     *
     * @param array{0: string, 1: string}|null $basicAuth Optional [user, pass] for HTTP basic auth
     *
     * @return array{ok: bool, http: int, ms: int, body: string, error: string}
     */
    private function probeHttpEndpoint(string $url, int $timeoutSec = 3, ?array $basicAuth = null): array
    {
        $t0 = microtime(true);
        $options = [
            'timeout' => $timeoutSec,
            'max_duration' => $timeoutSec,
            'max_redirects' => 3,
            'headers' => ['User-Agent' => 'Synaform-Dashboard/1.0'],
        ];
        if (null !== $basicAuth && isset($basicAuth[0]) && '' !== $basicAuth[0]) {
            $options['auth_basic'] = [$basicAuth[0], $basicAuth[1] ?? ''];
        }
        try {
            $response = $this->http->request('GET', $url, $options);
            $http = $response->getStatusCode();
            $body = $response->getContent(false);
            $err = '';
        } catch (TransportExceptionInterface $e) {
            $http = 0;
            $body = '';
            $err = $e->getMessage();
        }
        $ms = (int) ((microtime(true) - $t0) * 1000);

        return [
            'ok' => $http >= 200 && $http < 300,
            'http' => $http,
            'ms' => $ms,
            'body' => $body,
            'error' => $err,
        ];
    }

    #[Route('/config', name: 'config_get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/synaform/config',
        summary: 'Get plugin configuration',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'Plugin config')]
    public function configGet(int $userId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json([
            'success' => true,
            'config' => $this->getPluginConfig($userId),
        ]);
    }

    #[Route('/config', name: 'config_update', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/user/{userId}/plugins/synaform/config',
        summary: 'Update plugin configuration',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'Updated config')]
    public function configUpdate(int $userId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $allowedKeys = [
            'default_language', 'company_name',
            'extraction_model', 'validation_model',
            'default_template_id',
        ];

        $updated = [];
        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $data)) {
                $this->configRepository->setValue($userId, self::CONFIG_GROUP, $key, (string) $data[$key]);
                $updated[] = $key;
            }
        }

        return $this->json([
            'success' => true,
            'updated' => $updated,
            'config' => $this->getPluginConfig($userId),
        ]);
    }

    #[Route('/prompts', name: 'prompts_get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/synaform/prompts',
        summary: 'List editable AI prompt sections with defaults and per-user overrides',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'Prompt sections')]
    public function promptsGet(int $userId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $overrides = $this->getPromptOverrides($userId);
        $prompts = [];
        foreach (self::EDITABLE_PROMPT_KEYS as $key) {
            $default = $this->defaultPromptText($key);
            $prompts[] = [
                'key' => $key,
                'default' => $default,
                'text' => $overrides[$key] ?? $default,
                'customized' => isset($overrides[$key]),
            ];
        }

        return $this->json(['success' => true, 'prompts' => $prompts]);
    }

    #[Route('/prompts', name: 'prompts_update', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/user/{userId}/plugins/synaform/prompts',
        summary: 'Save prompt overrides. Empty/null value (or text equal to the default) resets a prompt to its default.',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'Updated prompt sections')]
    public function promptsUpdate(int $userId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['success' => false, 'error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
        }

        $stored = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_PROMPTS, 'prompts') ?? [];
        foreach (self::EDITABLE_PROMPT_KEYS as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            $text = is_string($value) ? trim($value) : '';
            // Empty text or text identical to the default means "no override".
            if ('' === $text || $text === trim($this->defaultPromptText($key))) {
                unset($stored[$key]);
                continue;
            }
            // Sanity cap so a runaway paste can't blow up every extraction call.
            $stored[$key] = mb_substr($text, 0, 20000);
        }

        // Keep only known keys in storage.
        $stored = array_intersect_key($stored, array_flip(self::EDITABLE_PROMPT_KEYS));
        $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_PROMPTS, 'prompts', $stored);
        unset($this->promptOverridesCache[$userId]);

        return $this->promptsGet($userId, $user);
    }

    // =========================================================================
    // Template Management
    // =========================================================================

    #[Route('/templates', name: 'templates_list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/synaform/templates',
        summary: 'List all templates',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'List of templates')]
    public function templatesList(int $userId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $templates = $this->pluginData->list($userId, self::PLUGIN_NAME, self::DATA_TYPE_TEMPLATE);

        return $this->json([
            'success' => true,
            'templates' => array_values($templates),
        ]);
    }

    #[Route('/templates', name: 'templates_create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/synaform/templates',
        summary: 'Upload a DOCX template file',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 201, description: 'Template created')]
    public function templatesCreate(int $userId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $accepted = $this->acceptUploadedFile($request, ['docx'], self::MAX_TEMPLATE_UPLOAD_BYTES);
        if (!$accepted['ok']) {
            return $this->json(['success' => false, 'error' => $accepted['error']], $accepted['status']);
        }
        $file = $accepted['file'];

        $originalName = $file->getClientOriginalName();
        $name = $request->request->get('name', pathinfo($originalName, PATHINFO_FILENAME));
        $templateId = 'tpl_'.bin2hex(random_bytes(6));

        $dir = $this->uploadDir.'/'.$userId.'/synaform/templates/'.$templateId;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file->move($dir, 'template.docx');
        $this->finalizeUploadedFile($dir.'/template.docx');

        $placeholders = $this->extractPlaceholders($dir.'/template.docx');
        $lint = $this->lintTemplate($dir.'/template.docx');

        // Build the HTML preview skeleton once and cache it on the template record.
        // Non-fatal: if it fails (malformed docx, exotic content), generation and
        // download still work; only the live-preview panel degrades to "unavailable".
        try {
            $preview = $this->htmlPreviewService->build($dir.'/template.docx');
        } catch (\Throwable $e) {
            $this->logger->warning('Preview skeleton failed', ['template' => $templateId, 'err' => $e->getMessage()]);
            $preview = null;
        }

        // Optional language metadata for the target template. The AI extraction
        // step uses this so values returned for placeholders are written in the
        // template's intended language (e.g. an English template still gets
        // English values even when extracting from a German CV).
        $language = $this->normalizeLanguage($request->request->get('language'));

        $templateData = [
            'id' => $templateId,
            'name' => $name,
            'original_filename' => $originalName,
            'placeholders' => $placeholders,
            'placeholder_count' => count($placeholders),
            'preview' => $preview,
            'lint' => $lint,
            'language' => $language,
            'created_at' => date('c'),
            'updated_at' => date('c'),
        ];

        $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_TEMPLATE, $templateId, $templateData);

        return $this->json([
            'success' => true,
            'template' => $templateData,
        ], Response::HTTP_CREATED);
    }

    #[Route('/templates/ai-suggest-from-docx', name: 'templates_ai_suggest_from_docx', methods: ['POST'], priority: 10)]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/synaform/templates/ai-suggest-from-docx',
        summary: 'Upload a draft .docx with NO placeholders and let the AI propose variables. The endpoint inserts {{placeholders}} into a copy of the document and saves the result as a brand-new template.',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 201, description: 'Template created plus per-suggestion application status')]
    public function templatesAiSuggestFromDocx(int $userId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $accepted = $this->acceptUploadedFile($request, ['docx'], self::MAX_TEMPLATE_UPLOAD_BYTES);
        if (!$accepted['ok']) {
            return $this->json(['success' => false, 'error' => $accepted['error']], $accepted['status']);
        }
        $file = $accepted['file'];

        $originalName = $file->getClientOriginalName();
        $name = trim((string) $request->request->get('name', pathinfo($originalName, PATHINFO_FILENAME)));
        if ('' === $name) {
            $name = pathinfo($originalName, PATHINFO_FILENAME);
        }
        $language = $this->normalizeLanguage($request->request->get('language'));

        $sourceText = $this->extractTextFromDocx($file->getPathname());
        if (null === $sourceText || '' === trim($sourceText)) {
            return $this->json([
                'success' => false,
                'error' => 'Could not read any text from the uploaded .docx.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // ────────────────────────────────────────────────────────────────
        // Multi-stage AI pipeline (see analyzeDocumentProfile() /
        // proposeVariablesPass() / refineSuggestionSnippets() below).
        // Stages 1 & 2 each run their own AI call; Stage 2 can run
        // multiple top-up passes (with an exclusion list) when we get
        // back fewer good variables than expected. Stage 3 is purely
        // deterministic — it verifies / repairs every source_text
        // against the source document and drops hallucinations.
        // ────────────────────────────────────────────────────────────────
        $pipelineLog = [
            'doc_chars' => mb_strlen($sourceText),
            'language_hint' => $language,
            'stages' => [],
        ];

        $modelUsed = 'unknown';
        // Hard ceiling on the merged result so an enormous contract
        // (or a model that proposes dozens of low-value variables)
        // doesn't drown the UI. Tuned higher than the old 10-variable
        // early-exit because windowed scanning legitimately surfaces
        // more variables on multi-page docs.
        $finalCap = 40;

        try {
            // Stage 1 — document profile.
            $profile = $this->analyzeDocumentProfile($sourceText, $userId, $language);
            $modelUsed = $profile['_model'] ?? $modelUsed;
            $pipelineLog['stages'][] = [
                'stage' => 'analyze',
                'doc_type' => $profile['doc_type'],
                'primary_language' => $profile['primary_language'],
                'model' => $profile['_model'] ?? null,
                'analyzed_by_ai' => !empty($profile['_analyzed_by_ai']),
            ];

            // Stage 2 — windowed proposal scan. Walks the WHOLE document
            // (chunked into overlapping windows on paragraph boundaries)
            // instead of only the head+tail clip. Each window gets its
            // own AI call with the keys/snippets accepted so far in the
            // exclusion list, so windows don't propose duplicates.
            $windows = $this->splitDocumentIntoWindows($sourceText);
            $totalWindows = count($windows);
            $pipelineLog['windows_scanned'] = $totalWindows;

            $allSuggestions = [];
            $excludedKeys = [];
            $excludedSnippets = [];
            foreach ($windows as $i => $windowText) {
                $passResult = $this->proposeVariablesPass(
                    $sourceText,
                    $windowText,
                    $profile,
                    $excludedKeys,
                    $excludedSnippets,
                    $i + 1,
                    $totalWindows,
                    $userId,
                );
                $modelUsed = $passResult['model'] ?? $modelUsed;
                $pipelineLog['stages'][] = [
                    'stage' => sprintf('propose-window-%d-of-%d', $i + 1, $totalWindows),
                    'window_chars' => function_exists('mb_strlen') ? mb_strlen($windowText) : strlen($windowText),
                    'returned_raw' => $passResult['raw_count'],
                    'kept_after_normalize' => count($passResult['suggestions']),
                    'model' => $passResult['model'],
                    'response_len' => $passResult['response_len'],
                    'recovered_truncated' => $passResult['recovered_truncated'],
                ];
                // Empty window result is fine (boilerplate-only slice);
                // keep scanning the rest of the document.
                foreach ($passResult['suggestions'] as $s) {
                    if (isset($excludedKeys[$s['key']])) {
                        continue;
                    }
                    if (isset($excludedSnippets[mb_strtolower((string) $s['source_text'])])) {
                        continue;
                    }
                    $allSuggestions[] = $s;
                    $excludedKeys[$s['key']] = true;
                    $excludedSnippets[mb_strtolower((string) $s['source_text'])] = true;
                    if (count($allSuggestions) >= $finalCap) {
                        break 2;
                    }
                }
            }

            if (empty($allSuggestions)) {
                return $this->json([
                    'success' => false,
                    'error' => 'The AI did not propose any usable variables for this document. Try uploading a richer document, or use the manual wizard mode.',
                    'pipeline' => $pipelineLog,
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Stage 3 — verify / repair every source_text against the
            // actual document. Drops hallucinations, normalises
            // whitespace, picks the canonical casing.
            $refined = $this->refineSuggestionSnippets($allSuggestions, $sourceText);
            $pipelineLog['stages'][] = [
                'stage' => 'refine',
                'kept' => count($refined['suggestions']),
                'dropped' => $refined['dropped'],
                'repaired' => $refined['repaired'],
            ];
            $suggestions = $refined['suggestions'];

            if (empty($suggestions)) {
                return $this->json([
                    'success' => false,
                    'error' => 'The AI proposed variables but none of them could be located in the document. Try a richer document or shorten the source text.',
                    'pipeline' => $pipelineLog,
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        } catch (\Throwable $e) {
            $this->logger->error('AI suggest-from-docx pipeline failed', [
                'err' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 2000),
                'pipeline' => $pipelineLog,
            ]);

            return $this->json([
                'success' => false,
                'error' => 'AI suggestion failed: '.$e->getMessage(),
                'pipeline' => $pipelineLog,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // ────────────────────────────────────────────────────────────────
        // Stage 4 — persist the .docx, inject the {{placeholders}}, and
        // run the normal post-upload bookkeeping (placeholders extract,
        // lint, HTML preview, plugin_data write).
        // ────────────────────────────────────────────────────────────────
        $templateId = 'tpl_'.bin2hex(random_bytes(6));
        $dir = $this->uploadDir.'/'.$userId.'/synaform/templates/'.$templateId;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file->move($dir, 'template.docx');
        $docxPath = $dir.'/template.docx';

        $suggestions = $this->applyPlaceholdersToDocx($docxPath, $suggestions);

        $placeholders = $this->extractPlaceholders($docxPath);
        $lint = $this->lintTemplate($docxPath);

        try {
            $preview = $this->htmlPreviewService->build($docxPath);
        } catch (\Throwable $e) {
            $this->logger->warning('Preview skeleton failed', ['template' => $templateId, 'err' => $e->getMessage()]);
            $preview = null;
        }

        // Persist the language reported by Stage 1 onto the template
        // when the caller didn't pass one explicitly. The extraction
        // step downstream picks this up to keep generated values in
        // the same language as the template.
        $templateLanguage = '' !== $language ? $language : ($profile['primary_language'] ?? '');

        $templateData = [
            'id' => $templateId,
            'name' => $name,
            'original_filename' => $originalName,
            'placeholders' => $placeholders,
            'placeholder_count' => count($placeholders),
            'preview' => $preview,
            'lint' => $lint,
            'language' => $templateLanguage,
            'created_at' => date('c'),
            'updated_at' => date('c'),
            'origin' => 'ai_suggested_from_docx',
            'origin_profile' => [
                'doc_type' => $profile['doc_type'] ?? 'other',
                'doc_type_label' => $profile['doc_type_label'] ?? '',
                'primary_language' => $profile['primary_language'] ?? '',
                'summary' => $profile['summary'] ?? '',
                'sections' => $profile['sections'] ?? [],
            ],
        ];

        $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_TEMPLATE, $templateId, $templateData);

        $appliedCount = count(array_filter($suggestions, static fn (array $s): bool => !empty($s['applied'])));

        $this->logger->info('AI suggest-from-docx: completed', [
            'template' => $templateId,
            'suggestions' => count($suggestions),
            'applied' => $appliedCount,
            'pipeline' => $pipelineLog,
        ]);

        return $this->json([
            'success' => true,
            'template' => $templateData,
            'suggestions' => array_values($suggestions),
            'suggestion_count' => count($suggestions),
            'applied_count' => $appliedCount,
            'model' => $modelUsed,
            'profile' => [
                'doc_type' => $profile['doc_type'] ?? 'other',
                'doc_type_label' => $profile['doc_type_label'] ?? '',
                'primary_language' => $profile['primary_language'] ?? '',
                'summary' => $profile['summary'] ?? '',
            ],
        ], Response::HTTP_CREATED);
    }

    #[Route('/templates/{templateId}', name: 'templates_get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/synaform/templates/{templateId}',
        summary: 'Get template metadata with placeholders',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'Template details')]
    public function templatesGet(int $userId, string $templateId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $template = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_TEMPLATE, $templateId);
        if (!$template) {
            return $this->json(['success' => false, 'error' => 'Template not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'success' => true,
            'template' => $template,
        ]);
    }

    #[Route('/templates/{templateId}/lint', name: 'templates_lint', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/synaform/templates/{templateId}/lint',
        summary: 'Audit a template for placement issues that risk breaking generation',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'Linter findings')]
    public function templatesLint(int $userId, string $templateId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $template = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_TEMPLATE, $templateId);
        if (!$template) {
            return $this->json(['success' => false, 'error' => 'Template not found'], Response::HTTP_NOT_FOUND);
        }

        $path = $this->uploadDir.'/'.$userId.'/synaform/templates/'.$templateId.'/template.docx';
        if (!is_file($path)) {
            return $this->json(['success' => false, 'error' => 'Template file not found on disk'], Response::HTTP_NOT_FOUND);
        }

        $lint = $this->lintTemplate($path);
        // Refresh cached lint on the template record so subsequent
        // templates_get calls return the latest findings without a re-audit.
        $template['lint'] = $lint;
        $template['updated_at'] = date('c');
        $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_TEMPLATE, $templateId, $template);

        return $this->json([
            'success' => true,
            'lint' => $lint,
        ]);
    }

    #[Route('/templates/{templateId}', name: 'templates_update', methods: ['PATCH'])]
    #[OA\Patch(
        path: '/api/v1/user/{userId}/plugins/synaform/templates/{templateId}',
        summary: 'Update template metadata (currently: name, language)',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'Template updated')]
    public function templatesUpdate(int $userId, string $templateId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $template = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_TEMPLATE, $templateId);
        if (!$template) {
            return $this->json(['success' => false, 'error' => 'Template not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        if (array_key_exists('name', $data)) {
            $name = trim((string) ($data['name'] ?? ''));
            if ('' !== $name) {
                $template['name'] = $name;
            }
        }

        if (array_key_exists('language', $data)) {
            $template['language'] = $this->normalizeLanguage($data['language']);
        }

        $template['updated_at'] = date('c');
        $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_TEMPLATE, $templateId, $template);

        return $this->json(['success' => true, 'template' => $template]);
    }

    #[Route('/templates/{templateId}', name: 'templates_delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/user/{userId}/plugins/synaform/templates/{templateId}',
        summary: 'Delete a template',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'Template deleted')]
    public function templatesDelete(int $userId, string $templateId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->pluginData->exists($userId, self::PLUGIN_NAME, self::DATA_TYPE_TEMPLATE, $templateId)) {
            return $this->json(['success' => false, 'error' => 'Template not found'], Response::HTTP_NOT_FOUND);
        }

        $dir = $this->uploadDir.'/'.$userId.'/synaform/templates/'.$templateId;
        $this->removeDirectory($dir);

        $this->pluginData->delete($userId, self::PLUGIN_NAME, self::DATA_TYPE_TEMPLATE, $templateId);

        return $this->json(['success' => true, 'message' => 'Template deleted']);
    }

    #[Route('/templates/{templateId}/placeholders', name: 'templates_placeholders', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/synaform/templates/{templateId}/placeholders',
        summary: 'List detected placeholders for a template',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'Placeholder list')]
    public function templatesPlaceholders(int $userId, string $templateId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $template = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_TEMPLATE, $templateId);
        if (!$template) {
            return $this->json(['success' => false, 'error' => 'Template not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'success' => true,
            'placeholders' => $template['placeholders'] ?? [],
        ]);
    }

    #[Route('/templates/{templateId}/variable-suggestions', name: 'templates_variable_suggestions', methods: ['GET'], priority: 10)]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/synaform/templates/{templateId}/variable-suggestions',
        summary: 'Turn detected placeholders into ready-to-apply form fields (deterministic, no AI)',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'Suggested field[] array and a summary of what was detected')]
    public function templatesVariableSuggestions(int $userId, string $templateId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $template = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_TEMPLATE, $templateId);
        if (!$template) {
            return $this->json(['success' => false, 'error' => 'Template not found'], Response::HTTP_NOT_FOUND);
        }

        // Optional form_id lets us flag duplicates so the UI can pre-uncheck existing keys.
        $existingKeys = [];
        $formId = $request->query->get('form_id');
        if (is_string($formId) && '' !== $formId) {
            $form = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_FORM, $formId);
            if ($form && isset($form['fields']) && is_array($form['fields'])) {
                foreach ($form['fields'] as $f) {
                    if (!empty($f['key'])) {
                        $existingKeys[(string) $f['key']] = true;
                    }
                }
            }
        }

        $placeholders = $template['placeholders'] ?? [];
        $suggestions = $this->buildVariableSuggestions($placeholders, $existingKeys);

        return $this->json([
            'success' => true,
            'template_id' => $templateId,
            'template_name' => $template['name'] ?? '',
            'placeholder_count' => count($placeholders),
            'suggestions' => $suggestions['fields'],
            'summary' => $suggestions['summary'],
        ]);
    }

    #[Route('/templates/{templateId}/preview-html', name: 'templates_preview_html', methods: ['GET'], priority: 10)]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/synaform/templates/{templateId}/preview-html',
        summary: 'Return the cached HTML preview skeleton for a target template (used by the live preview panel)',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'HTML skeleton with placeholder spans')]
    public function templatesPreviewHtml(int $userId, string $templateId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $template = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_TEMPLATE, $templateId);
        if (!$template) {
            return $this->json(['success' => false, 'error' => 'Template not found'], Response::HTTP_NOT_FOUND);
        }

        $preview = $template['preview'] ?? null;
        $stale = !is_array($preview)
            || ($preview['schema_version'] ?? 0) !== TemplateHtmlPreviewService::SCHEMA_VERSION;

        if ($stale) {
            $filePath = $this->uploadDir.'/'.$userId.'/synaform/templates/'.$templateId.'/template.docx';
            if (is_file($filePath)) {
                try {
                    $preview = $this->htmlPreviewService->build($filePath);
                    $template['preview'] = $preview;
                    $template['updated_at'] = date('c');
                    $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_TEMPLATE, $templateId, $template);
                } catch (\Throwable $e) {
                    $this->logger->warning('Preview skeleton rebuild failed', ['template' => $templateId, 'err' => $e->getMessage()]);
                    $preview = null;
                }
            }
        }

        if (!is_array($preview)) {
            return $this->json([
                'success' => false,
                'error' => 'Preview unavailable for this template.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'success' => true,
            'template_id' => $templateId,
            'schema_version' => $preview['schema_version'] ?? 0,
            'html' => $preview['html'] ?? '',
            'placeholders' => $preview['placeholders'] ?? [],
            'row_groups' => $preview['row_groups'] ?? [],
            'generated_at' => $preview['generated_at'] ?? null,
        ]);
    }

    #[Route('/templates/{templateId}/download', name: 'templates_download', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/synaform/templates/{templateId}/download',
        summary: 'Download original template file',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'Template DOCX file')]
    public function templatesDownload(int $userId, string $templateId, #[CurrentUser] ?User $user): Response
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $template = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_TEMPLATE, $templateId);
        if (!$template) {
            return $this->json(['success' => false, 'error' => 'Template not found'], Response::HTTP_NOT_FOUND);
        }

        $filePath = $this->uploadDir.'/'.$userId.'/synaform/templates/'.$templateId.'/template.docx';
        if (!is_file($filePath)) {
            return $this->json(['success' => false, 'error' => 'Template file not found on disk'], Response::HTTP_NOT_FOUND);
        }

        $downloadName = $template['original_filename'] ?? ($template['name'].'.docx');
        $response = new BinaryFileResponse($filePath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $downloadName);

        return $response;
    }

    // =========================================================================
    // Form Management
    // =========================================================================

    #[Route('/forms', name: 'forms_list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/synaform/forms',
        summary: 'List all form definitions',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'List of forms')]
    public function formsList(int $userId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $forms = $this->pluginData->list($userId, self::PLUGIN_NAME, self::DATA_TYPE_FORM);

        return $this->json([
            'success' => true,
            'forms' => array_values($forms),
        ]);
    }

    #[Route('/forms', name: 'forms_create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/synaform/forms',
        summary: 'Create a new form definition',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 201, description: 'Form created')]
    public function formsCreate(int $userId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        if (!$data || empty($data['name'])) {
            return $this->json(['success' => false, 'error' => 'Form name is required'], Response::HTTP_BAD_REQUEST);
        }

        $formId = $data['id'] ?? ('form_'.bin2hex(random_bytes(6)));

        $formData = [
            'id' => $formId,
            'name' => $data['name'],
            'description' => $data['description'] ?? '',
            'language' => $data['language'] ?? 'de',
            'version' => $data['version'] ?? 1,
            'fields' => $this->normalizeFields($data['fields'] ?? []),
            'template_ids' => $data['template_ids'] ?? [],
            'created_at' => date('c'),
            'updated_at' => date('c'),
        ];

        $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_FORM, $formId, $formData);

        return $this->json([
            'success' => true,
            'form' => $formData,
        ], Response::HTTP_CREATED);
    }

    #[Route('/forms/{formId}', name: 'forms_get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/synaform/forms/{formId}',
        summary: 'Get a form definition',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'Form details')]
    public function formsGet(int $userId, string $formId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $form = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_FORM, $formId);
        if (!$form) {
            return $this->json(['success' => false, 'error' => 'Form not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'success' => true,
            'form' => $form,
        ]);
    }

    #[Route('/forms/{formId}', name: 'forms_update', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/user/{userId}/plugins/synaform/forms/{formId}',
        summary: 'Update a form definition',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'Form updated')]
    public function formsUpdate(int $userId, string $formId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $existing = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_FORM, $formId);
        if (!$existing) {
            return $this->json(['success' => false, 'error' => 'Form not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        $updatable = ['name', 'description', 'language', 'version', 'fields', 'template_ids'];
        foreach ($updatable as $field) {
            if (array_key_exists($field, $data)) {
                if ('fields' === $field && is_array($data[$field])) {
                    $existing[$field] = $this->normalizeFields($data[$field]);
                } else {
                    $existing[$field] = $data[$field];
                }
            }
        }
        $existing['updated_at'] = date('c');

        $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_FORM, $formId, $existing);

        return $this->json([
            'success' => true,
            'form' => $existing,
        ]);
    }

    #[Route('/forms/{formId}', name: 'forms_delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/user/{userId}/plugins/synaform/forms/{formId}',
        summary: 'Delete a form definition',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'Form deleted')]
    public function formsDelete(int $userId, string $formId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->pluginData->exists($userId, self::PLUGIN_NAME, self::DATA_TYPE_FORM, $formId)) {
            return $this->json(['success' => false, 'error' => 'Form not found'], Response::HTTP_NOT_FOUND);
        }

        $this->pluginData->delete($userId, self::PLUGIN_NAME, self::DATA_TYPE_FORM, $formId);

        return $this->json(['success' => true, 'message' => 'Form deleted']);
    }

    #[Route('/forms/{formId}/enhance-fields', name: 'forms_enhance_fields', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/synaform/forms/{formId}/enhance-fields',
        summary: 'Use AI to suggest richer description / examples / negative_hint for form fields. Returns suggestions only — caller must save via PUT /forms/{formId} to apply.',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'Per-field enhancement suggestions')]
    public function formsEnhanceFields(int $userId, string $formId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $form = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_FORM, $formId);
        if (!$form) {
            return $this->json(['success' => false, 'error' => 'Form not found'], Response::HTTP_NOT_FOUND);
        }

        $fields = $form['fields'] ?? [];
        if (empty($fields)) {
            return $this->json(['success' => false, 'error' => 'Form has no fields to enhance'], Response::HTTP_BAD_REQUEST);
        }

        $body = json_decode($request->getContent(), true) ?? [];
        $requestedKeys = is_array($body['field_keys'] ?? null)
            ? array_values(array_filter(array_map('strval', $body['field_keys']), static fn ($k) => '' !== $k))
            : [];
        $sampleCandidateId = (string) ($body['sample_candidate_id'] ?? '');

        // Resolve which fields to enhance: explicit list, or all fields.
        $targetFields = $fields;
        if (!empty($requestedKeys)) {
            $targetFields = array_values(array_filter(
                $fields,
                static fn ($f) => in_array((string) ($f['key'] ?? ''), $requestedKeys, true)
            ));
            if (empty($targetFields)) {
                return $this->json(['success' => false, 'error' => 'None of the requested field_keys exist in the form'], Response::HTTP_BAD_REQUEST);
            }
        }

        // Sample-doc grounding: pull text from the most recent candidate
        // (or the explicitly named one) so the AI's `examples` are
        // anchored in real values from the user's actual data instead
        // of generic placeholders.
        [$sampleText, $sampleMeta] = $this->loadSampleDocumentText($userId, $formId, $sampleCandidateId);

        $extractionLanguage = $this->resolveExtractionLanguage($userId, $form);
        $extractionLanguageName = $this->languageName($extractionLanguage);

        $prompt = $this->buildFieldEnhancementPrompt(
            $fields,
            $targetFields,
            $sampleText,
            $extractionLanguageName,
        );

        try {
            $messages = [
                ['role' => 'system', 'content' => 'You are a senior form-design assistant. Return ONLY valid JSON.'],
                ['role' => 'user', 'content' => $prompt],
            ];
            $aiOptions = $this->resolveAiModelOptions($userId);
            $aiOptions['max_tokens'] = max(3000, count($targetFields) * 350);
            // Native thinking-disable for Google Gemini Flash — the
            // cross-provider `reasoning_effort` map only handles
            // 'off'/'low'/'medium'/'high'/'dynamic'; sending 'minimal'
            // (which doesn't match) silently falls through to Google's
            // server-side default thinking budget and burns 5–8 s per
            // call. `thinking_budget: 0` is the native passthrough.
            // For non-Google providers it's a harmless extra option.
            $aiOptions['thinking_budget'] = 0;
            $aiOptions['reasoning_effort'] = 'low';
            $aiOptions['temperature'] = 0.2;

            $started = microtime(true);
            $result = $this->aiFacade->chat($messages, $userId, $aiOptions);
            $elapsedMs = (int) ((microtime(true) - $started) * 1000);
            $content = (string) ($result['content'] ?? '');
            $modelUsed = (string) ($result['model'] ?? 'unknown');

            $parsed = $this->parseJsonFromAiResponse($content);
            if (!is_array($parsed)) {
                $this->logger->warning('Synaform: enhance-fields AI returned unparseable response', [
                    'formId' => $formId,
                    'model' => $modelUsed,
                    'response_chars' => strlen($content),
                    'raw_preview' => mb_substr($content, 0, 400),
                ]);

                return $this->json([
                    'success' => false,
                    'error' => 'AI returned an unparseable response. Try again or switch the chat model in Settings.',
                    'debug' => [
                        'model' => $modelUsed,
                        'response_chars' => strlen($content),
                        'raw_preview' => mb_substr($content, 0, 400),
                    ],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $suggestions = $parsed['fields'] ?? null;
            if (!is_array($suggestions) && array_is_list($parsed)) {
                $suggestions = $parsed;
            }
            if (!is_array($suggestions)) {
                return $this->json([
                    'success' => false,
                    'error' => 'AI response did not contain a fields array.',
                    'debug' => ['model' => $modelUsed, 'parsed_keys' => array_keys($parsed)],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $targetKeys = array_map(static fn ($f) => (string) ($f['key'] ?? ''), $targetFields);
            $byKey = [];
            foreach ($fields as $f) {
                $k = (string) ($f['key'] ?? '');
                if ('' !== $k) {
                    $byKey[$k] = $f;
                }
            }

            $enhancements = [];
            foreach ($suggestions as $s) {
                if (!is_array($s)) {
                    continue;
                }
                $key = (string) ($s['key'] ?? '');
                if ('' === $key || !in_array($key, $targetKeys, true) || !isset($byKey[$key])) {
                    continue;
                }
                $current = $byKey[$key];
                $suggestedDescription = isset($s['description']) && is_string($s['description'])
                    ? trim($s['description']) : '';
                $suggestedNegative = isset($s['negative_hint']) && is_string($s['negative_hint'])
                    ? trim($s['negative_hint']) : '';
                $suggestedExamples = [];
                if (isset($s['examples']) && is_array($s['examples'])) {
                    foreach ($s['examples'] as $ex) {
                        if (is_string($ex) && '' !== trim($ex)) {
                            $suggestedExamples[] = trim($ex);
                        }
                    }
                    $suggestedExamples = array_values(array_slice(array_unique($suggestedExamples), 0, 4));
                }

                // Skip if AI returned nothing meaningful for this field.
                if ('' === $suggestedDescription && '' === $suggestedNegative && empty($suggestedExamples)) {
                    continue;
                }

                $enhancements[] = [
                    'key' => $key,
                    'label' => (string) ($current['label'] ?? $key),
                    'type' => (string) ($current['type'] ?? 'text'),
                    'current' => [
                        'description' => (string) ($current['description'] ?? ''),
                        'examples' => is_array($current['examples'] ?? null) ? $current['examples'] : [],
                        'negative_hint' => (string) ($current['negative_hint'] ?? ''),
                        'hint' => (string) ($current['hint'] ?? ''),
                    ],
                    'suggested' => [
                        'description' => $suggestedDescription,
                        'examples' => $suggestedExamples,
                        'negative_hint' => $suggestedNegative,
                    ],
                ];
            }

            return $this->json([
                'success' => true,
                'enhancements' => $enhancements,
                'model' => $modelUsed,
                'elapsed_ms' => $elapsedMs,
                'sample' => $sampleMeta,
                'fields_requested' => count($targetFields),
                'fields_returned' => count($enhancements),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Synaform: enhance-fields failed', [
                'formId' => $formId,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Field enhancement failed: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Pull plain text from the most recent (or named) candidate that
     * has any extractable source — CV, additional docs, URLs. Used to
     * ground enhance-fields example values in the user's real data.
     *
     * @return array{0: ?string, 1: array{candidate_id: ?string, filename: ?string, doc_chars: int}}
     */
    private function loadSampleDocumentText(int $userId, string $formId, string $explicitCandidateId = ''): array
    {
        $meta = ['candidate_id' => null, 'filename' => null, 'doc_chars' => 0];
        $candidate = null;

        if ('' !== $explicitCandidateId) {
            $candidate = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $explicitCandidateId);
            if ($candidate && ($candidate['form_id'] ?? null) !== $formId) {
                $candidate = null;
            }
        }

        // Fall back to the most-recent candidate of this form that has a CV.
        if (!$candidate) {
            $all = $this->pluginData->list($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE) ?? [];
            $matches = array_values(array_filter(
                $all,
                static fn ($c) => is_array($c)
                    && ($c['form_id'] ?? null) === $formId
                    && !empty($c['files']['cv'])
            ));
            usort($matches, static fn ($a, $b) => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')));
            $candidate = $matches[0] ?? null;
        }

        if (!$candidate) {
            return [null, $meta];
        }

        $candidateId = (string) ($candidate['id'] ?? '');
        $cv = $candidate['files']['cv'] ?? null;
        if (!is_array($cv) || empty($cv['stored_as'])) {
            $meta['candidate_id'] = $candidateId;

            return [null, $meta];
        }

        $storedAs = (string) $cv['stored_as'];
        $ext = strtolower(pathinfo($storedAs, PATHINFO_EXTENSION));
        $relativePath = $userId.'/synaform/candidates/'.$candidateId.'/'.$storedAs;

        try {
            [$text] = $this->fileProcessor->extractText($relativePath, $ext, $userId);
            $text = is_string($text) ? trim($text) : '';
            if ('' === $text) {
                $meta['candidate_id'] = $candidateId;
                $meta['filename'] = $cv['filename'] ?? null;

                return [null, $meta];
            }
            // Cap to keep enhance-fields prompt bounded — examples don't
            // need the whole document.
            $cap = 8000;
            if (function_exists('mb_strlen') && mb_strlen($text) > $cap) {
                $text = mb_substr($text, 0, $cap)."\n…[truncated]…";
            }
            $meta['candidate_id'] = $candidateId;
            $meta['filename'] = $cv['filename'] ?? null;
            $meta['doc_chars'] = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);

            return [$text, $meta];
        } catch (\Throwable $e) {
            $this->logger->warning('Synaform: loadSampleDocumentText failed', [
                'candidateId' => $candidateId,
                'error' => $e->getMessage(),
            ]);
            $meta['candidate_id'] = $candidateId;

            return [null, $meta];
        }
    }

    /**
     * Build the prompt that asks the AI to enrich the chosen fields
     * with description / examples / negative_hint. Sees the FULL form
     * context (not just target fields) so it can disambiguate sibling
     * fields ("there is both current_position AND target_position
     * here, make sure your description distinguishes them").
     */
    private function buildFieldEnhancementPrompt(
        array $allFields,
        array $targetFields,
        ?string $sampleText,
        string $extractionLanguageName,
    ): string {
        $fmtCurrent = static function (array $f): string {
            $key = (string) ($f['key'] ?? '');
            $label = (string) ($f['label'] ?? $key);
            $type = (string) ($f['type'] ?? 'text');
            $existing = [];
            if (!empty($f['description'])) {
                $existing[] = 'description="'.trim((string) $f['description']).'"';
            } elseif (!empty($f['hint'])) {
                $existing[] = 'hint="'.trim((string) $f['hint']).'"';
            }
            if (!empty($f['examples']) && is_array($f['examples'])) {
                $existing[] = 'examples='.json_encode($f['examples'], JSON_UNESCAPED_UNICODE);
            }
            if (!empty($f['negative_hint'])) {
                $existing[] = 'negative_hint="'.trim((string) $f['negative_hint']).'"';
            }
            if (!empty($f['options'])) {
                $existing[] = 'options='.json_encode($f['options'], JSON_UNESCAPED_UNICODE);
            }
            $line = sprintf('- %s (type=%s, label="%s")', $key, $type, $label);
            if (!empty($existing)) {
                $line .= "\n  current: ".implode('; ', $existing);
            }

            return $line;
        };

        $fullFormBlock = implode("\n", array_map($fmtCurrent, $allFields));
        $targetKeysBlock = implode(', ', array_map(static fn ($f) => '"'.($f['key'] ?? '').'"', $targetFields));

        $sampleBlock = '';
        if (null !== $sampleText && '' !== $sampleText) {
            $sampleBlock = "\n\n        Sample source document (for grounding examples in real data — do NOT copy values verbatim into descriptions):\n        ---\n        ".$sampleText."\n        ---\n";
        }

        return <<<PROMPT
        You are improving the variable definitions for a data-extraction form.

        For each TARGET field listed below, you will return three properties that help a downstream AI extract the field accurately from documents. The form's full field list is shown so you can disambiguate sibling fields (e.g. distinguish current_position from target_position when both exist).

        Return values:
        - description: 1–3 sentences explaining WHAT this field is, WHERE in a document to look for it, and any format/conventions. Reference the field's relationship to siblings if relevant. Write in {$extractionLanguageName}.
        - examples: 2–4 short example values demonstrating the expected format. Use realistic-looking data. If a sample document is provided below, the examples may draw lightly from it but should not copy verbatim PII (no real names, real emails, real phone numbers). For "select" fields, the examples MUST be a subset of the allowed options.
        - negative_hint: 1 short sentence (or empty string) describing what this field is NOT — common confusions or look-alikes to avoid.

        Form full context (existing field definitions):
        {$fullFormBlock}{$sampleBlock}

        TARGET field keys to enhance: [{$targetKeysBlock}]

        Return ONLY valid JSON in this exact shape (one entry per TARGET field):
        {"fields":[{"key":"<field_key>","description":"...","examples":["...","..."],"negative_hint":"..."}]}

        Rules:
        - Only return fields whose key is in the TARGET list.
        - If you have nothing useful to add for a field, omit it from the array entirely.
        - Do NOT echo or modify "key", "label", "type" — only the three new properties.
        - Do NOT invent fields that aren't in the TARGET list.
        PROMPT;
    }

    #[Route('/forms/import-parse', name: 'forms_import_parse', methods: ['POST'], priority: 10)]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/synaform/forms/import-parse',
        summary: 'Parse pasted text or DOCX into structured form fields using AI',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'Parsed form fields')]
    public function formsImportParse(int $userId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $text = null;

        $file = $request->files->get('file');
        if ($file) {
            $ext = strtolower($file->getClientOriginalExtension());
            if ('docx' !== $ext) {
                return $this->json(['success' => false, 'error' => 'Only .docx files are supported'], Response::HTTP_BAD_REQUEST);
            }
            $text = $this->extractTextFromDocx($file->getPathname());
            if (null === $text) {
                return $this->json(['success' => false, 'error' => 'Could not extract text from DOCX'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        } else {
            $body = json_decode($request->getContent(), true);
            $text = $body['text'] ?? null;
        }

        if (!$text || '' === trim($text)) {
            return $this->json(['success' => false, 'error' => 'No text provided. Paste variable definitions or upload a .docx file.'], Response::HTTP_BAD_REQUEST);
        }

        $prompt = $this->buildImportParsePrompt($text);

        try {
            $messages = [
                ['role' => 'system', 'content' => 'You are a precise document structure parser. Return only valid JSON arrays.'],
                ['role' => 'user', 'content' => $prompt],
            ];
            $aiOptions = $this->resolveAiModelOptions($userId);
            $result = $this->aiFacade->chat($messages, $userId, $aiOptions);
            $content = $result['content'] ?? '';

            $parsed = $this->parseJsonFromAiResponse($content);
            if (null === $parsed || !is_array($parsed)) {
                return $this->json(['success' => false, 'error' => 'AI returned unparseable response'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $fields = isset($parsed[0]) ? $parsed : ($parsed['fields'] ?? []);

            $validated = $this->normalizeFields($fields);

            return $this->json([
                'success' => true,
                'fields' => $validated,
                'field_count' => count($validated),
                'model' => $result['model'] ?? 'unknown',
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Import parse failed: '.$e->getMessage());

            return $this->json(['success' => false, 'error' => 'AI parsing failed: '.$e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // =========================================================================
    // Entry (Candidate) Management
    // =========================================================================

    #[Route('/candidates', name: 'candidates_list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/synaform/candidates',
        summary: 'List all entries',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'List of entries')]
    public function candidatesList(int $userId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $candidates = $this->pluginData->list($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE);
        // Surface the GDPR retention metadata on every entry so the UI can
        // flag overdue datasets without re-walking each one. We compute
        // expires_at on the fly: legacy entries created before this feature
        // existed have no `delete_after_months` field and therefore never
        // expire by default.
        $candidates = array_map(function (array $c): array {
            $c['delete_after_months'] = $this->normalizeRetentionMonths($c['delete_after_months'] ?? null);
            $c['expires_at'] = $this->computeExpiresAt(
                $c['updated_at'] ?? null,
                $c['delete_after_months']
            );

            return $c;
        }, $candidates);

        return $this->json([
            'success' => true,
            'candidates' => array_values($candidates),
        ]);
    }

    #[Route('/candidates', name: 'candidates_create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/synaform/candidates',
        summary: 'Create a new entry',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 201, description: 'Entry created')]
    public function candidatesCreate(int $userId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        $entryId = 'entry_'.bin2hex(random_bytes(6));

        $entryData = [
            'id' => $entryId,
            'name' => $data['name'] ?? '',
            'form_id' => $data['form_id'] ?? 'default',
            'template_id' => $data['template_id'] ?? null,
            'status' => $data['status'] ?? 'draft',
            'field_values' => $data['field_values'] ?? [],
            'files' => [],
            // GDPR / data-retention: when set to a positive integer the
            // dataset is flagged for deletion after that many months from
            // its last update. null = "never auto-delete" (legacy default).
            'delete_after_months' => $this->normalizeRetentionMonths($data['delete_after_months'] ?? null),
            'created_at' => date('c'),
            'updated_at' => date('c'),
        ];
        $entryData['expires_at'] = $this->computeExpiresAt($entryData['updated_at'], $entryData['delete_after_months']);

        $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $entryId, $entryData);

        return $this->json([
            'success' => true,
            'candidate' => $entryData,
        ], Response::HTTP_CREATED);
    }

    #[Route('/candidates/{candidateId}', name: 'candidates_get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/synaform/candidates/{candidateId}',
        summary: 'Get entry detail',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'Entry details')]
    public function candidatesGet(int $userId, string $candidateId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $candidate = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);
        if (!$candidate) {
            return $this->json(['success' => false, 'error' => 'Entry not found'], Response::HTTP_NOT_FOUND);
        }

        $candidate['delete_after_months'] = $this->normalizeRetentionMonths($candidate['delete_after_months'] ?? null);
        $candidate['expires_at'] = $this->computeExpiresAt(
            $candidate['updated_at'] ?? null,
            $candidate['delete_after_months']
        );

        return $this->json([
            'success' => true,
            'candidate' => $candidate,
        ]);
    }

    #[Route('/candidates/{candidateId}', name: 'candidates_update', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/user/{userId}/plugins/synaform/candidates/{candidateId}',
        summary: 'Update an entry',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'Entry updated')]
    public function candidatesUpdate(int $userId, string $candidateId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $existing = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);
        if (!$existing) {
            return $this->json(['success' => false, 'error' => 'Entry not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        $updatable = ['name', 'form_id', 'template_id', 'status', 'field_values'];
        foreach ($updatable as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            // field_values MUST be an associative map keyed by string
            // field keys. Reject lists / numeric-keyed maps so a
            // malformed AI response (top-level array) can never poison
            // the candidate's stored values — that broke template
            // generation for users in v3.7.0.
            if ('field_values' === $field) {
                $val = $data[$field];
                if (!is_array($val)) {
                    return $this->json([
                        'success' => false,
                        'error' => 'field_values must be a JSON object keyed by field key',
                    ], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                if ([] !== $val && array_is_list($val)) {
                    return $this->json([
                        'success' => false,
                        'error' => 'field_values must be a keyed object, not an array',
                    ], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $cleaned = [];
                foreach ($val as $k => $v) {
                    if (!is_string($k) || '' === $k) {
                        continue;
                    }
                    $cleaned[$k] = $v;
                }
                $existing[$field] = $cleaned;
                continue;
            }
            $existing[$field] = $data[$field];
        }
        if (array_key_exists('delete_after_months', $data)) {
            $existing['delete_after_months'] = $this->normalizeRetentionMonths($data['delete_after_months']);
        }
        $existing['updated_at'] = date('c');
        $existing['expires_at'] = $this->computeExpiresAt(
            $existing['updated_at'],
            $existing['delete_after_months'] ?? null
        );

        $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId, $existing);

        return $this->json([
            'success' => true,
            'candidate' => $existing,
        ]);
    }

    /**
     * Coerce arbitrary input into the allowed retention period (months).
     * Allowed values: 3, 6, 9, 12, 18. Anything else (including 0, "never",
     * empty string, null) collapses to null = "never auto-delete".
     */
    private function normalizeRetentionMonths(mixed $raw): ?int
    {
        if (null === $raw || '' === $raw || false === $raw || (is_string($raw) && 'never' === strtolower(trim($raw)))) {
            return null;
        }
        $n = (int) $raw;
        $allowed = [3, 6, 9, 12, 18];

        return in_array($n, $allowed, true) ? $n : null;
    }

    /**
     * Compute the GDPR-driven expiry timestamp from a base ISO date and a
     * retention window in months. Returns null when retention is "never".
     */
    private function computeExpiresAt(?string $baseIso, ?int $months): ?string
    {
        if (null === $months || $months <= 0 || !is_string($baseIso) || '' === $baseIso) {
            return null;
        }
        try {
            $dt = new \DateTimeImmutable($baseIso);
        } catch (\Throwable) {
            return null;
        }

        return $dt->modify('+'.$months.' months')->format('c');
    }

    #[Route('/candidates/{candidateId}', name: 'candidates_delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/user/{userId}/plugins/synaform/candidates/{candidateId}',
        summary: 'Delete an entry and all its files',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'Entry deleted')]
    public function candidatesDelete(int $userId, string $candidateId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->pluginData->exists($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId)) {
            return $this->json(['success' => false, 'error' => 'Entry not found'], Response::HTTP_NOT_FOUND);
        }

        $dir = $this->uploadDir.'/'.$userId.'/synaform/candidates/'.$candidateId;
        $this->removeDirectory($dir);

        $this->pluginData->delete($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);

        return $this->json(['success' => true, 'message' => 'Entry deleted']);
    }

    #[Route('/candidates/{candidateId}/upload-cv', name: 'candidates_upload_cv', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/synaform/candidates/{candidateId}/upload-cv',
        summary: 'Upload primary document',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'CV uploaded')]
    public function candidatesUploadCv(int $userId, string $candidateId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $existing = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);
        if (!$existing) {
            return $this->json(['success' => false, 'error' => 'Entry not found'], Response::HTTP_NOT_FOUND);
        }

        $accepted = $this->acceptUploadedFile($request, self::ALLOWED_UPLOAD_EXTENSIONS, self::MAX_SOURCE_UPLOAD_BYTES);
        if (!$accepted['ok']) {
            return $this->json(['success' => false, 'error' => $accepted['error']], $accepted['status']);
        }
        $file = $accepted['file'];

        $ext = strtolower($file->getClientOriginalExtension());

        $dir = $this->uploadDir.'/'.$userId.'/synaform/candidates/'.$candidateId;
        FileHelper::createDirectory($dir);

        $originalName = $file->getClientOriginalName();
        $storedName = 'cv.'.$ext;
        $file->move($dir, $storedName);
        $this->finalizeUploadedFile($dir.'/'.$storedName);

        $existing['files']['cv'] = [
            'filename' => $originalName,
            'stored_as' => $storedName,
            'mime_type' => $file->getClientMimeType() ?? mime_content_type($dir.'/'.$storedName) ?? 'application/octet-stream',
            'size' => filesize($dir.'/'.$storedName),
            'uploaded_at' => date('c'),
        ];
        $existing['updated_at'] = date('c');

        $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId, $existing);

        return $this->json([
            'success' => true,
            'file' => $existing['files']['cv'],
        ]);
    }

    #[Route('/candidates/{candidateId}/upload-doc', name: 'candidates_upload_doc', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/synaform/candidates/{candidateId}/upload-doc',
        summary: 'Upload additional document',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'Document uploaded')]
    public function candidatesUploadDoc(int $userId, string $candidateId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $existing = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);
        if (!$existing) {
            return $this->json(['success' => false, 'error' => 'Entry not found'], Response::HTTP_NOT_FOUND);
        }

        $accepted = $this->acceptUploadedFile($request, self::ALLOWED_UPLOAD_EXTENSIONS, self::MAX_SOURCE_UPLOAD_BYTES);
        if (!$accepted['ok']) {
            return $this->json(['success' => false, 'error' => $accepted['error']], $accepted['status']);
        }
        $file = $accepted['file'];

        $dir = $this->uploadDir.'/'.$userId.'/synaform/candidates/'.$candidateId;
        FileHelper::createDirectory($dir);

        $originalName = $file->getClientOriginalName();
        $safeFilename = bin2hex(random_bytes(4)).'-'.preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
        $file->move($dir, $safeFilename);
        $this->finalizeUploadedFile($dir.'/'.$safeFilename);

        $docEntry = [
            'filename' => $originalName,
            'stored_as' => $safeFilename,
            'mime_type' => $file->getClientMimeType() ?? 'application/octet-stream',
            'size' => filesize($dir.'/'.$safeFilename),
            'uploaded_at' => date('c'),
        ];

        if (!isset($existing['files'])) {
            $existing['files'] = [];
        }
        if (!isset($existing['files']['additional'])) {
            $existing['files']['additional'] = [];
        }
        $existing['files']['additional'][] = $docEntry;
        $existing['updated_at'] = date('c');

        $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId, $existing);

        return $this->json([
            'success' => true,
            'file' => $docEntry,
        ]);
    }

    #[Route('/candidates/{candidateId}/files/{slot}/{fileIndex}', name: 'candidates_file_delete', methods: ['DELETE'], requirements: ['fileIndex' => '\d+'])]
    #[OA\Delete(
        path: '/api/v1/user/{userId}/plugins/synaform/candidates/{candidateId}/files/{slot}/{fileIndex}',
        summary: 'Delete a source file (CV or additional document) from an entry',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'File deleted')]
    public function candidatesFileDelete(int $userId, string $candidateId, string $slot, int $fileIndex, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $entry = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);
        if (!$entry) {
            return $this->json(['success' => false, 'error' => 'Entry not found'], Response::HTTP_NOT_FOUND);
        }

        $dir = $this->uploadDir.'/'.$userId.'/synaform/candidates/'.$candidateId;

        if ('cv' === $slot) {
            $cvFile = $entry['files']['cv'] ?? null;
            if (!$cvFile) {
                return $this->json(['success' => false, 'error' => 'No CV file found'], Response::HTTP_NOT_FOUND);
            }
            $storedAs = $cvFile['stored_as'] ?? '';
            if ($storedAs && is_file($dir.'/'.$storedAs)) {
                unlink($dir.'/'.$storedAs);
            }
            unset($entry['files']['cv']);
        } elseif ('additional' === $slot) {
            $additionalDocs = $entry['files']['additional'] ?? [];
            if (!isset($additionalDocs[$fileIndex])) {
                return $this->json(['success' => false, 'error' => 'File not found at index'], Response::HTTP_NOT_FOUND);
            }
            $storedAs = $additionalDocs[$fileIndex]['stored_as'] ?? '';
            if ($storedAs && is_file($dir.'/'.$storedAs)) {
                unlink($dir.'/'.$storedAs);
            }
            array_splice($entry['files']['additional'], $fileIndex, 1);
        } elseif ('urls' === $slot) {
            $urls = $entry['files']['urls'] ?? [];
            if (!isset($urls[$fileIndex])) {
                return $this->json(['success' => false, 'error' => 'URL not found at index'], Response::HTTP_NOT_FOUND);
            }
            array_splice($entry['files']['urls'], $fileIndex, 1);
        } else {
            return $this->json(['success' => false, 'error' => 'Invalid slot. Use "cv", "additional" or "urls"'], Response::HTTP_BAD_REQUEST);
        }

        $entry['updated_at'] = date('c');
        $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId, $entry);

        return $this->json(['success' => true, 'candidate' => $entry]);
    }

    #[Route('/candidates/{candidateId}/files/{slot}/{fileIndex}/download', name: 'candidates_file_download', methods: ['GET'], requirements: ['fileIndex' => '\d+'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/synaform/candidates/{candidateId}/files/{slot}/{fileIndex}/download',
        summary: 'Download an uploaded source file (CV or additional document). PDFs and images are served inline so the browser can preview them; everything else is served as an attachment.',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'The uploaded source file')]
    #[OA\Response(response: 404, description: 'File not found')]
    public function candidatesFileDownload(int $userId, string $candidateId, string $slot, int $fileIndex, #[CurrentUser] ?User $user): Response
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $entry = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);
        if (!$entry) {
            return $this->json(['success' => false, 'error' => 'Entry not found'], Response::HTTP_NOT_FOUND);
        }

        if ('cv' === $slot) {
            $fileMeta = $entry['files']['cv'] ?? null;
        } elseif ('additional' === $slot) {
            $fileMeta = $entry['files']['additional'][$fileIndex] ?? null;
        } else {
            return $this->json(['success' => false, 'error' => 'Invalid slot. Use "cv" or "additional"'], Response::HTTP_BAD_REQUEST);
        }
        if (!$fileMeta) {
            return $this->json(['success' => false, 'error' => 'File not found'], Response::HTTP_NOT_FOUND);
        }

        // stored_as is server-generated, but never trust a stored value that
        // ends up in a filesystem path.
        $storedAs = basename((string) ($fileMeta['stored_as'] ?? ''));
        $filePath = $this->uploadDir.'/'.$userId.'/synaform/candidates/'.$candidateId.'/'.$storedAs;
        if ('' === $storedAs || !$this->waitForUploadFile($filePath)) {
            return $this->json(['success' => false, 'error' => 'File not found on disk'], Response::HTTP_NOT_FOUND);
        }

        $mime = (string) ($fileMeta['mime_type'] ?? 'application/octet-stream');
        $downloadName = preg_replace('/[^a-zA-Z0-9._ -]/', '_', (string) ($fileMeta['filename'] ?? $storedAs)) ?: $storedAs;

        // Inline for previewable types (browser opens PDFs/images in a tab),
        // attachment for everything else (docx, e-mails, ...).
        $inline = 'application/pdf' === $mime || str_starts_with($mime, 'image/');

        $response = new BinaryFileResponse($filePath, 200, ['Content-Type' => $mime]);
        $response->setContentDisposition(
            $inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $downloadName
        );

        return $response;
    }

    // =========================================================================
    // URL Sources (LinkedIn profiles, company pages, public web documents)
    // =========================================================================

    #[Route('/candidates/{candidateId}/urls', name: 'candidates_urls_add', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/synaform/candidates/{candidateId}/urls',
        summary: 'Attach a web URL (e.g. LinkedIn profile) as an AI-readable source',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'URL attached')]
    public function candidatesUrlsAdd(int $userId, string $candidateId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $entry = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);
        if (!$entry) {
            return $this->json(['success' => false, 'error' => 'Entry not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $url = trim((string) ($data['url'] ?? ''));
        $label = trim((string) ($data['label'] ?? ''));

        if ('' === $url) {
            return $this->json(['success' => false, 'error' => 'URL is required'], Response::HTTP_BAD_REQUEST);
        }

        $parsed = parse_url($url);
        if (!$parsed || empty($parsed['scheme']) || !in_array(strtolower($parsed['scheme']), ['http', 'https'], true) || empty($parsed['host'])) {
            return $this->json(['success' => false, 'error' => 'Only http:// and https:// URLs are supported'], Response::HTTP_BAD_REQUEST);
        }

        [$snippet, $fetchError] = $this->fetchUrlText($url);

        $host = $parsed['host'] ?? '';
        $kind = 'web';
        if (str_contains($host, 'linkedin.com')) {
            $kind = 'linkedin';
        } elseif (str_contains($host, 'xing.com')) {
            $kind = 'xing';
        } elseif (str_contains($host, 'github.com')) {
            $kind = 'github';
        }

        $urlEntry = [
            'id' => 'url_'.bin2hex(random_bytes(5)),
            'url' => $url,
            'label' => '' !== $label ? $label : $host,
            'host' => $host,
            'kind' => $kind,
            'text_snippet' => $snippet,
            'fetch_error' => $fetchError,
            'fetched_at' => null !== $snippet ? date('c') : null,
            'added_at' => date('c'),
        ];

        if (!isset($entry['files']) || !is_array($entry['files'])) {
            $entry['files'] = [];
        }
        if (!isset($entry['files']['urls']) || !is_array($entry['files']['urls'])) {
            $entry['files']['urls'] = [];
        }
        $entry['files']['urls'][] = $urlEntry;
        $entry['updated_at'] = date('c');
        $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId, $entry);

        return $this->json([
            'success' => true,
            'url' => $urlEntry,
            'candidate' => $entry,
        ]);
    }

    #[Route('/candidates/{candidateId}/urls/{urlIndex}', name: 'candidates_urls_delete', methods: ['DELETE'], requirements: ['urlIndex' => '\d+'])]
    #[OA\Delete(
        path: '/api/v1/user/{userId}/plugins/synaform/candidates/{candidateId}/urls/{urlIndex}',
        summary: 'Remove a previously added URL source',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'URL removed')]
    public function candidatesUrlsDelete(int $userId, string $candidateId, int $urlIndex, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $entry = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);
        if (!$entry) {
            return $this->json(['success' => false, 'error' => 'Entry not found'], Response::HTTP_NOT_FOUND);
        }

        $urls = $entry['files']['urls'] ?? [];
        if (!isset($urls[$urlIndex])) {
            return $this->json(['success' => false, 'error' => 'URL not found at index'], Response::HTTP_NOT_FOUND);
        }
        array_splice($entry['files']['urls'], $urlIndex, 1);
        $entry['updated_at'] = date('c');
        $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId, $entry);

        return $this->json(['success' => true, 'candidate' => $entry]);
    }

    #[Route('/candidates/{candidateId}/urls/{urlIndex}/refresh', name: 'candidates_urls_refresh', methods: ['POST'], requirements: ['urlIndex' => '\d+'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/synaform/candidates/{candidateId}/urls/{urlIndex}/refresh',
        summary: 'Re-fetch a URL source (useful when a previous fetch failed or content changed)',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'URL re-fetched')]
    public function candidatesUrlsRefresh(int $userId, string $candidateId, int $urlIndex, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $entry = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);
        if (!$entry) {
            return $this->json(['success' => false, 'error' => 'Entry not found'], Response::HTTP_NOT_FOUND);
        }

        $urls = $entry['files']['urls'] ?? [];
        if (!isset($urls[$urlIndex])) {
            return $this->json(['success' => false, 'error' => 'URL not found at index'], Response::HTTP_NOT_FOUND);
        }

        $existing = $urls[$urlIndex];
        [$snippet, $fetchError] = $this->fetchUrlText($existing['url']);
        $existing['text_snippet'] = $snippet;
        $existing['fetch_error'] = $fetchError;
        $existing['fetched_at'] = null !== $snippet ? date('c') : null;
        $entry['files']['urls'][$urlIndex] = $existing;
        $entry['updated_at'] = date('c');
        $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId, $entry);

        return $this->json(['success' => true, 'url' => $existing]);
    }

    // =========================================================================
    // AI Extraction & Variable Resolution
    // =========================================================================

    #[Route('/candidates/{candidateId}/extract', name: 'candidates_extract', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/synaform/candidates/{candidateId}/extract',
        summary: 'Extract structured data from uploaded CV using AI',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'Extraction result')]
    public function candidatesExtract(int $userId, string $candidateId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $entry = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);
        if (!$entry) {
            return $this->json(['success' => false, 'error' => 'Entry not found'], Response::HTTP_NOT_FOUND);
        }

        $hasCv = !empty($entry['files']['cv']);
        $hasUrls = !empty($entry['files']['urls']);
        if (!$hasCv && !$hasUrls) {
            return $this->json(['success' => false, 'error' => 'No CV uploaded and no URL source attached. Upload a CV or add a URL (e.g. LinkedIn) first.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $rawText = '';
            if ($hasCv) {
                $storedAs = $entry['files']['cv']['stored_as'] ?? 'cv.pdf';
                $ext = strtolower(pathinfo($storedAs, PATHINFO_EXTENSION));
                $relativePath = $userId.'/synaform/candidates/'.$candidateId.'/'.$storedAs;
                [$cvText, $extractMeta] = $this->fileProcessor->extractText($relativePath, $ext, $userId);
                $cvText = $cvText ?? '';
                if ('' !== trim($cvText)) {
                    $rawText .= "=== Primary Document ===\n".$cvText."\n\n";
                }
            }
            foreach ($entry['files']['urls'] ?? [] as $urlEntry) {
                $snippet = $urlEntry['text_snippet'] ?? null;
                if (is_string($snippet) && '' !== trim($snippet)) {
                    $host = $urlEntry['host'] ?? 'url';
                    $kind = $urlEntry['kind'] ?? 'web';
                    $rawText .= "=== URL Source ({$kind} · {$host} · ".($urlEntry['url'] ?? '').") ===\n".$snippet."\n\n";
                }
            }

            if ('' === trim($rawText)) {
                return $this->json(['success' => false, 'error' => 'Could not extract text from CV or URL sources'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $form = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_FORM, $entry['form_id'] ?? 'default');
            $formFields = $form['fields'] ?? [];
            $extractionLanguage = $this->resolveExtractionLanguage($userId, $form);

            $prompt = $this->buildExtractionPrompt($userId, $rawText, $formFields, $extractionLanguage);
            $messages = [
                ['role' => 'system', 'content' => 'You are a precise CV data extraction assistant. Return only valid JSON.'],
                ['role' => 'user', 'content' => $prompt],
            ];
            $aiOptions = $this->resolveAiModelOptions($userId);
            // Career histories with many positions (one row per role/period)
            // can produce long JSON. The provider default (4096) truncates
            // the response mid-object, which then fails to parse. Give the
            // extraction generous headroom.
            $aiOptions['max_tokens'] = 16000;
            $result = $this->aiFacade->chat($messages, $userId, $aiOptions);
            $content = $result['content'] ?? '';

            $extracted = $this->parseJsonFromAiResponse($content);
            if (null === $extracted) {
                $this->logger->warning('Synaform extraction: AI response did not parse as JSON', [
                    'candidateId' => $candidateId,
                    'content_chars' => strlen($content),
                    'tail' => mb_substr($content, -160),
                ]);

                return $this->json(['success' => false, 'error' => 'Failed to parse AI extraction result'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Post-process the AI output so common artefacts (duplicate
            // outer time/position parroted back as the first line of
            // `details`, missing blank lines before sub-period date
            // headers) don't leak into the generated Word document.
            // Currently only `stations` needs cleanup; the helper is a
            // no-op if the key is missing or malformed.
            if (isset($extracted['stations']) && is_array($extracted['stations'])) {
                $extracted['stations'] = $this->normalizeStationsRows($extracted['stations']);
            }

            $entry['ai_extracted'] = $extracted;
            $entry['status'] = 'extracted';
            $entry['updated_at'] = date('c');
            $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId, $entry);

            return $this->json([
                'success' => true,
                'extracted' => $extracted,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('CV extraction failed', [
                'error' => $e->getMessage(),
                'candidateId' => $candidateId,
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Extraction failed: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/candidates/{candidateId}/parse-documents', name: 'candidates_parse_documents', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/synaform/candidates/{candidateId}/parse-documents',
        summary: 'Parse uploaded documents with AI to auto-fill form fields',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'Parsed field suggestions')]
    public function candidatesParseDocuments(int $userId, string $candidateId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $entry = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);
        if (!$entry) {
            return $this->json(['success' => false, 'error' => 'Entry not found'], Response::HTTP_NOT_FOUND);
        }

        $form = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_FORM, $entry['form_id'] ?? 'default');
        if (!$form) {
            return $this->json(['success' => false, 'error' => 'Form definition not found'], Response::HTTP_NOT_FOUND);
        }

        // Cloudflare returns HTML 524 if the origin is silent ~100 s. Keep
        // working after a dropped proxy so OCR cache still lands, and return
        // JSON ourselves before that wall so the UI can auto-continue.
        ignore_user_abort(true);
        $requestStarted = microtime(true);
        $ocrIncomplete = false;

        $candidateDir = $this->uploadDir.'/'.$userId.'/synaform/candidates/'.$candidateId;
        $allTexts = [];
        // OCR cache stats reported back in the response. The cache
        // lives on each file's metadata (`files.cv.ocr_cache` or
        // `files.additional[i].ocr_cache`) keyed by SHA-1 of the
        // file bytes — so re-uploading the same file (different
        // bytes → different hash) auto-invalidates, and a re-run
        // of parse-documents on unchanged files skips Vision/Tika
        // entirely. Image OCR is the dominant cost on phone-scan
        // workflows; caching turns 5–15s vision calls into 0ms.
        $cacheStats = ['hits' => 0, 'misses' => 0];
        $candidateDirty = false;

        // v4.0: per-file extraction telemetry. Every source file gets a
        // report {filename, slot, kind, chars, ok, strategy} that is
        // persisted onto the file metadata (`extraction`) and returned to
        // the frontend so the Sources list can show a per-file readable /
        // "not readable" status. Files that yield no text are collected in
        // $unreadable so the UI can name them instead of silently dropping
        // them (customer feedback item 5).
        $fileReports = [];
        $unreadable = [];

        if (!empty($entry['files']['cv'])) {
            $storedAs = $entry['files']['cv']['stored_as'] ?? 'cv.pdf';
            $ext = strtolower(pathinfo($storedAs, PATHINFO_EXTENSION));
            $relativePath = $userId.'/synaform/candidates/'.$candidateId.'/'.$storedAs;
            $cvName = $entry['files']['cv']['filename'] ?? $storedAs;
            $cvKind = $this->classifyDocKind($cvName, 'cv');
            $cvAbsolute = $candidateDir.'/'.$storedAs;
            // Cluster (web1/2/3): metadata is in Galera, bytes are on NFS.
            // Cloudflare round-robins with no sticky sessions, so parse can
            // land on a different node than the upload. Wait through NFS
            // attribute-cache lag instead of treating the file as missing.
            if ($this->isOverParseBudget($requestStarted, self::PARSE_MIN_SECONDS_FOR_OCR)) {
                $this->logger->warning('Parse-documents: skipping CV OCR — request budget nearly spent', [
                    'candidateId' => $candidateId,
                    'elapsed_ms' => $this->parseElapsedMs($requestStarted),
                ]);
                $report = $this->failedFileExtraction(
                    $entry['files']['cv'],
                    $cvName,
                    'cv',
                    $cvKind,
                    self::PARSE_TIMEOUT_FILE_MESSAGE,
                );
                $fileReports[] = $report;
                $unreadable[] = $report;
                $candidateDirty = true;
                $ocrIncomplete = true;
            } elseif (!$this->waitForUploadFile($cvAbsolute)) {
                $this->logger->warning('Parse-documents: CV file not visible on this node', [
                    'candidateId' => $candidateId,
                    'path' => $cvAbsolute,
                    'hostname' => gethostname() ?: 'unknown',
                ]);
                $report = $this->failedFileExtraction(
                    $entry['files']['cv'],
                    $cvName,
                    'cv',
                    $cvKind,
                    'Source file not yet visible on this server (cluster NFS). Please retry auto-fill.',
                );
                $fileReports[] = $report;
                $unreadable[] = $report;
                $candidateDirty = true;
            } else {
                try {
                    [$text, $cMeta] = $this->extractTextWithFileCache(
                        $entry['files']['cv'],
                        $candidateDir,
                        $relativePath,
                        $ext,
                        $userId,
                        $cacheStats,
                    );
                    $report = $this->recordFileExtraction($entry['files']['cv'], $cvName, 'cv', $cvKind, (string) $text, $cMeta);
                    $fileReports[] = $report;
                    $candidateDirty = true;
                    if ($report['ok']) {
                        $allTexts[] = '=== '.$report['kind_label'].': '.$cvName.' ==='."\n".$text;
                    } else {
                        $unreadable[] = $report;
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('Parse-documents: failed to extract CV text', ['error' => $e->getMessage()]);
                    $report = $this->failedFileExtraction($entry['files']['cv'], $cvName, 'cv', $cvKind, $e->getMessage());
                    $fileReports[] = $report;
                    $unreadable[] = $report;
                    $candidateDirty = true;
                }
                $this->persistCandidateNow($userId, $candidateId, $entry);
                $candidateDirty = false;
            }
        }

        $additional = $entry['files']['additional'] ?? [];
        foreach ($additional as $idx => $doc) {
            $storedAs = $doc['stored_as'] ?? '';
            $docName = $doc['filename'] ?? $storedAs;
            $docKind = $this->classifyDocKind((string) $docName, 'other');
            if ('' === $storedAs) {
                continue;
            }
            $absolutePath = $candidateDir.'/'.$storedAs;
            // Was a silent `continue` on !is_file() — on the cluster that
            // dropped freshly uploaded docs when parse hit another web node,
            // so the first auto-fill only saw a subset of sources and users
            // had to click 2–3 times as NFS cache caught up.
            if ($this->isOverParseBudget($requestStarted, self::PARSE_MIN_SECONDS_FOR_OCR)) {
                $this->logger->warning('Parse-documents: skipping additional OCR — request budget nearly spent', [
                    'candidateId' => $candidateId,
                    'file' => $storedAs,
                    'elapsed_ms' => $this->parseElapsedMs($requestStarted),
                ]);
                $report = $this->failedFileExtraction(
                    $entry['files']['additional'][$idx],
                    (string) $docName,
                    'additional',
                    $docKind,
                    self::PARSE_TIMEOUT_FILE_MESSAGE,
                );
                $fileReports[] = $report;
                $unreadable[] = $report;
                $candidateDirty = true;
                $ocrIncomplete = true;
                continue;
            }
            if (!$this->waitForUploadFile($absolutePath)) {
                $this->logger->warning('Parse-documents: additional file not visible on this node', [
                    'candidateId' => $candidateId,
                    'path' => $absolutePath,
                    'hostname' => gethostname() ?: 'unknown',
                ]);
                $report = $this->failedFileExtraction(
                    $entry['files']['additional'][$idx],
                    (string) $docName,
                    'additional',
                    $docKind,
                    'Source file not yet visible on this server (cluster NFS). Please retry auto-fill.',
                );
                $fileReports[] = $report;
                $unreadable[] = $report;
                $candidateDirty = true;
                continue;
            }
            $ext = strtolower(pathinfo($storedAs, PATHINFO_EXTENSION));
            $relativePath = $userId.'/synaform/candidates/'.$candidateId.'/'.$storedAs;
            try {
                [$text, $cMeta] = $this->extractTextWithFileCache(
                    $entry['files']['additional'][$idx],
                    $candidateDir,
                    $relativePath,
                    $ext,
                    $userId,
                    $cacheStats,
                );
                $report = $this->recordFileExtraction($entry['files']['additional'][$idx], (string) $docName, 'additional', $docKind, (string) $text, $cMeta);
                $fileReports[] = $report;
                $candidateDirty = true;
                if ($report['ok']) {
                    $allTexts[] = '=== '.$report['kind_label'].': '.$docName.' ==='."\n".$text;
                } else {
                    $unreadable[] = $report;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Parse-documents: failed to extract doc text', ['error' => $e->getMessage(), 'file' => $storedAs]);
                $report = $this->failedFileExtraction($entry['files']['additional'][$idx], (string) $docName, 'additional', $docKind, $e->getMessage());
                $fileReports[] = $report;
                $unreadable[] = $report;
                $candidateDirty = true;
            }
            $this->persistCandidateNow($userId, $candidateId, $entry);
            $candidateDirty = false;
        }

        // Persist any remaining metadata (timeout skips, NFS misses) that
        // did not go through the per-file persist above.
        if ($candidateDirty) {
            $this->persistCandidateNow($userId, $candidateId, $entry);
        }

        foreach ($entry['files']['urls'] ?? [] as $urlEntry) {
            $snippet = $urlEntry['text_snippet'] ?? null;
            if (is_string($snippet) && '' !== trim($snippet)) {
                $host = $urlEntry['host'] ?? 'url';
                $kind = $urlEntry['kind'] ?? 'web';
                $allTexts[] = "=== URL Source ({$kind} · {$host} · ".($urlEntry['url'] ?? '').") ===\n".$snippet;
            }
        }

        if (empty($allTexts) && !$ocrIncomplete) {
            $summary = $this->persistLastParseSummary(
                $userId,
                $candidateId,
                [
                    'outcome' => 'failed',
                    'model' => null,
                    'strategy' => 'no_text',
                    'elapsed_ms' => 0,
                    'sources_total' => count($fileReports),
                    'sources_ok' => 0,
                    'sources_unreadable' => count($unreadable),
                    'unreadable_files' => $this->summarizeUnreadableFiles($unreadable),
                    'fields_filled' => 0,
                    'fields_total' => 0,
                    'deadline_hit' => false,
                    'error' => 'No documents or URLs uploaded, or text could not be extracted from any source',
                    'files' => $fileReports,
                ],
            );

            return $this->json([
                'success' => false,
                'outcome' => 'failed',
                'error' => 'No documents or URLs uploaded, or text could not be extracted from any source',
                'files' => $fileReports,
                'unreadable' => $unreadable,
                'last_parse' => $summary,
            ], Response::HTTP_BAD_REQUEST);
        }

        $combinedText = implode("\n\n", $allTexts);

        // Hoisted from below — needed by the single-call adaptive
        // path so the gate decision and per-group `onlyMissing` skip
        // share the same flag.
        $onlyMissing = $request->query->getBoolean('onlyMissing', false);

        $fields = $form['fields'] ?? [];
        // Image-typed fields are excluded from the text-extraction AI: it
        // cannot produce a photo, and a stray string suggestion would
        // overwrite the stored image metadata. Photos are handled by
        // autoExtractProfileImages() below instead.
        $allowedKeys = array_values(array_filter(
            array_map(
                static fn ($f) => ($f['type'] ?? '') === 'image' ? '' : (string) ($f['key'] ?? ''),
                $fields
            ),
            static fn ($k) => '' !== $k
        ));
        $fieldsByKey = [];
        foreach ($fields as $f) {
            $key = (string) ($f['key'] ?? '');
            if ('' !== $key) {
                $fieldsByKey[$key] = $f;
            }
        }

        $deferAi = $ocrIncomplete || $this->isOverParseBudget($requestStarted, self::PARSE_MIN_SECONDS_FOR_AI);
        if ($deferAi) {
            $errMsg = $ocrIncomplete
                ? 'Large scans are still being read. Already-processed files are cached — continuing will pick up where we left off.'
                : 'Documents were read. Filling fields was deferred to stay within the time limit — run auto-fill again (this will be faster).';
            $summary = $this->buildAndPersistParseSummary(
                $userId,
                $candidateId,
                $fileReports,
                $unreadable,
                [],
                $allowedKeys,
                'deferred',
                $ocrIncomplete ? 'ocr_budget' : 'ai_deferred',
                $this->parseElapsedMs($requestStarted),
                true,
                true,
                $errMsg,
            );

            return $this->json([
                'success' => true,
                'outcome' => $summary['outcome'],
                'deadline_hit' => true,
                'ocr_incomplete' => $ocrIncomplete,
                'error' => $errMsg,
                'suggestions' => [],
                'documents_parsed' => count($allTexts),
                'files' => $fileReports,
                'unreadable' => $unreadable,
                'ocr_cache' => $cacheStats,
                'last_parse' => $summary,
            ]);
        }

        // Auto-fill empty image-typed variables with a portrait photo found
        // in the uploaded PDFs (profile photo embedded in a CV). Runs before
        // the AI text extraction so both strategy paths benefit; failures
        // are non-fatal — text extraction proceeds regardless.
        try {
            if ($this->autoExtractProfileImages($userId, $candidateId, $entry, $fields)) {
                $entry['updated_at'] = date('c');
                $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId, $entry);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Synaform: profile photo auto-extraction failed', [
                'candidateId' => $candidateId,
                'error' => $e->getMessage(),
            ]);
        }

        $extractionLanguage = $this->resolveExtractionLanguage($userId, $form);
        $extractionLanguageName = $this->languageName($extractionLanguage);

        // ────────────────────────────────────────────────────────────────
        // Adaptive extraction strategy (v3.7.3+).
        //
        // Stage 1 — fast single-call attempt.
        //   One AI call asks for the full field list at once. With
        //   modern fast models (Gemini 3.5 Flash, GPT-5.4) this fits
        //   comfortably below maxOutputTokens and finishes in 20–30s
        //   vs the 60–90s of 8 sequential grouped calls.
        //
        // Stage 2 — grouped fallback (v3.7.1+ pipeline).
        //   If the single call truncates, returns malformed JSON, or
        //   returns far fewer keys than the form has, we fall back to
        //   the per-group pipeline below — which has per-group failure
        //   isolation, type-aware max_tokens, and per-group telemetry.
        //
        // Skipped for ?onlyMissing=1 — that flow needs per-group skip
        // decisions, which are clearer in the grouped path.
        // ────────────────────────────────────────────────────────────────
        $singleStarted = microtime(true);
        $singleCallSuggestions = null;
        $singleCallTelemetry = null;
        $singleCallReason = '';

        if (!$onlyMissing && !empty($allowedKeys)) {
            $allFieldsSubset = array_values(array_filter(
                $fields,
                static fn ($f) => in_array((string) ($f['key'] ?? ''), $allowedKeys, true)
            ));

            $prompt = $this->buildGroupedExtractionPrompt(
                $userId,
                $allFieldsSubset,
                $combinedText,
                $extractionLanguageName,
                null,
            );

            try {
                $messages = [
                    ['role' => 'system', 'content' => 'You are a precise document parsing assistant. Return only valid JSON.'],
                    ['role' => 'user', 'content' => $prompt],
                ];
                $aiOptions = $this->resolveAiModelOptions($userId);
                $aiOptions['max_tokens'] = 16000;
                $aiOptions['thinking_budget'] = 0;
                $aiOptions['reasoning_effort'] = 'low';
                $aiOptions['temperature'] = 0;

                $result = $this->extractionChat($messages, $userId, $aiOptions, 'single_call', $candidateId);
                $singleElapsedMs = (int) ((microtime(true) - $singleStarted) * 1000);
                $content = (string) ($result['content'] ?? '');
                $modelUsed = (string) ($result['model'] ?? 'unknown');
                $parsed = $this->parseJsonFromAiResponse($content);

                // Acceptance criteria: valid JSON object (not array,
                // not null) AND at least 50 % of the form's field
                // keys are present in the response. The 50 % gate
                // catches mid-stream truncation — a healthy single
                // call on this CV returns 25/26 fields, a truncated
                // one cuts off after the first few. Below the gate,
                // we fall through to grouped which is robust.
                $threshold = max(5, (int) ceil(count($allowedKeys) * 0.5));
                if (
                    is_array($parsed)
                    && !array_is_list($parsed)
                    && count($parsed) >= $threshold
                ) {
                    $clean = [];
                    foreach ($parsed as $k => $v) {
                        if (is_string($k) && '' !== $k && in_array($k, $allowedKeys, true)) {
                            $clean[$k] = $v;
                        }
                    }

                    // v4.0 guaranteed-completeness second pass. The single
                    // call is accepted, but any field it left empty (or
                    // never returned) gets a focused retry in THIS SAME
                    // request. Customers reported having to press
                    // "Read & auto-fill" twice — the first call left gaps,
                    // the second filled them. This closes that gap in one
                    // click. Quality over tokens (explicit requirement).
                    $beforeMissing = 0;
                    foreach ($allowedKeys as $k) {
                        if (!$this->fieldValueIsFilled($clean[$k] ?? null)) {
                            ++$beforeMissing;
                        }
                    }
                    $clean = $this->fillMissingFieldsSecondPass(
                        $userId,
                        $fields,
                        $allowedKeys,
                        $clean,
                        $combinedText,
                        $extractionLanguageName,
                        $candidateId,
                    );
                    $filledCount = 0;
                    $afterMissing = 0;
                    foreach ($allowedKeys as $k) {
                        if ($this->fieldValueIsFilled($clean[$k] ?? null)) {
                            ++$filledCount;
                        } else {
                            ++$afterMissing;
                        }
                    }

                    $singleCallSuggestions = $clean;
                    $singleCallTelemetry = [
                        'strategy' => 'single_call',
                        'model' => $modelUsed,
                        'elapsed_ms' => (int) ((microtime(true) - $singleStarted) * 1000),
                        'fields_returned' => $filledCount,
                        'fields_in_form' => count($allowedKeys),
                        'response_chars' => strlen($content),
                        'second_pass_recovered' => max(0, $beforeMissing - $afterMissing),
                    ];
                } else {
                    $singleCallReason = null === $parsed
                        ? 'unparseable_response'
                        : (is_array($parsed) && array_is_list($parsed)
                            ? 'top_level_array'
                            : 'below_threshold ('.(is_array($parsed) ? count($parsed) : 0).'/'.$threshold.')');
                    $this->logger->info('Synaform: single-call extraction below threshold, falling back to grouped', [
                        'candidateId' => $candidateId,
                        'model' => $modelUsed,
                        'response_chars' => strlen($content),
                        'parsed_kind' => null === $parsed ? 'null' : (is_array($parsed) && array_is_list($parsed) ? 'list' : (is_array($parsed) ? count($parsed).'_keys' : 'unknown')),
                        'threshold' => $threshold,
                    ]);
                }
            } catch (\Throwable $e) {
                $singleCallReason = 'exception: '.$e->getMessage();
                $this->logger->warning('Synaform: single-call extraction threw, falling back to grouped', [
                    'candidateId' => $candidateId,
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            $singleCallReason = $onlyMissing ? 'only_missing_mode' : 'no_fields';
        }

        // Single-call won → return early without running the grouped
        // pipeline at all. Massive wall-time savings on the happy path.
        if (null !== $singleCallSuggestions) {
            // Only mark "extracted" when every declared source was readable.
            // Otherwise a cluster NFS miss on one of 3 uploads would look like
            // a finished scan and force the user into a second/third click.
            $modelName = (string) ($singleCallTelemetry['model'] ?? $modelUsed ?? 'unknown');
            if ([] === $unreadable) {
                $this->markCandidateExtracted($userId, $candidateId, $modelName);
            }

            $summary = $this->buildAndPersistParseSummary(
                $userId,
                $candidateId,
                $fileReports,
                $unreadable,
                $singleCallSuggestions,
                $allowedKeys,
                $modelName,
                'single_call',
                (int) ($singleCallTelemetry['elapsed_ms'] ?? 0),
                false,
                true,
            );

            return $this->json([
                'success' => true,
                'outcome' => $summary['outcome'],
                'suggestions' => $singleCallSuggestions,
                'documents_parsed' => count($allTexts),
                'model' => $modelName,
                'doc_chars' => mb_strlen($combinedText),
                'fields_returned' => count($singleCallSuggestions),
                'fields_filled' => $summary['fields_filled'],
                'fields_total' => $summary['fields_total'],
                'strategy' => 'single_call',
                'single_call' => $singleCallTelemetry,
                'total_elapsed_ms' => $singleCallTelemetry['elapsed_ms'] ?? 0,
                'ocr_cache' => $cacheStats,
                'files' => $fileReports,
                'unreadable' => $unreadable,
                'last_parse' => $summary,
            ]);
        }

        // ────────────────────────────────────────────────────────────────
        // Stage 2 — grouped extraction (fallback path).
        // ────────────────────────────────────────────────────────────────
        $groups = $this->getOrBuildExtractionGroups($userId, $form);
        if (empty($groups)) {
            $groups = [[
                'key' => 'all',
                'label' => 'All fields',
                'field_keys' => $allowedKeys,
            ]];
        }

        // ($onlyMissing already resolved above, before single-call gate.)
        $existingValues = $entry['field_values'] ?? [];
        if (!is_array($existingValues) || (function_exists('array_is_list') && array_is_list($existingValues))) {
            $existingValues = [];
        }

        $allMerged = [];
        $groupTelemetry = [];
        $modelUsed = null;
        $startedAll = microtime(true);
        // Hard wall-clock budget for the grouped fallback. Each provider
        // call is already bounded (Symfony HttpClient timeout per provider),
        // but the loop runs them sequentially, so a slow model over many
        // groups could still stack into minutes. Once the budget is spent
        // we stop starting new group calls and return what we have with a
        // clear "timed out" marker, so the request always ends promptly and
        // the reason is investigable in the telemetry.
        $groupedBudgetSeconds = min(180.0, max(15.0, $this->secondsLeftOnParse($requestStarted) - 5.0));
        $deadlineHit = false;

        foreach ($groups as $group) {
            $groupKey = (string) ($group['key'] ?? '');
            $groupLabel = (string) ($group['label'] ?? $groupKey);
            $rawFieldKeys = array_map('strval', (array) ($group['field_keys'] ?? []));
            $groupFieldKeys = array_values(array_intersect($rawFieldKeys, $allowedKeys));
            if (empty($groupFieldKeys)) {
                continue;
            }

            // Stop starting new group calls once the wall-clock budget is
            // spent. Remaining groups are recorded as skipped so the user
            // (and the logs) can see extraction was cut short by time.
            if ((microtime(true) - $startedAll) > $groupedBudgetSeconds) {
                $deadlineHit = true;
                $this->logger->warning('Synaform extraction grouped budget exceeded', [
                    'event' => 'synaform.ai.deadline',
                    'candidateId' => $candidateId,
                    'userId' => $userId,
                    'group' => $groupKey,
                    'elapsed_ms' => (int) ((microtime(true) - $startedAll) * 1000),
                    'budget_ms' => (int) ($groupedBudgetSeconds * 1000),
                ]);
                $groupTelemetry[] = [
                    'key' => $groupKey,
                    'label' => $groupLabel,
                    'fields_in_group' => count($groupFieldKeys),
                    'fields_returned' => 0,
                    'skipped' => true,
                    'reason' => 'time_budget_exceeded',
                    'elapsed_ms' => 0,
                ];
                continue;
            }

            // Skip groups whose fields are all already populated when the
            // caller asks for incremental extraction. "Filled" means
            // non-null, non-empty-string, and (for arrays) non-empty.
            if ($onlyMissing) {
                $allFilled = true;
                foreach ($groupFieldKeys as $k) {
                    if (!$this->fieldValueIsFilled($existingValues[$k] ?? null)) {
                        $allFilled = false;
                        break;
                    }
                }
                if ($allFilled) {
                    $groupTelemetry[] = [
                        'key' => $groupKey,
                        'label' => $groupLabel,
                        'fields_in_group' => count($groupFieldKeys),
                        'fields_returned' => 0,
                        'skipped' => true,
                        'reason' => 'already_filled',
                        'elapsed_ms' => 0,
                    ];
                    continue;
                }
            }

            $subset = array_values(array_filter(
                $fields,
                static fn ($f) => in_array((string) ($f['key'] ?? ''), $groupFieldKeys, true)
            ));
            $prompt = $this->buildGroupedExtractionPrompt(
                $userId,
                $subset,
                $combinedText,
                $extractionLanguageName,
                $groupLabel,
            );

            // Per-group max_tokens, type-aware. Token consumption
            // varies by field type so a uniform per-field budget
            // either truncates or wastes tokens:
            //   - text/select/date/number/checkbox: ~200 tokens each
            //   - list (e.g. relevant_positions, other_skills):
            //       ~1500 tokens — array of 5–10 sentence-length items
            //   - table (e.g. stations):
            //       ~16000 tokens flat — multi-row, multi-column,
            //       multi-line details. Always uses the full budget.
            // We sum the per-field allowance with a 2000-token base
            // for prompt overhead. Higher caps cost nothing on success
            // and prevent mid-stream truncation under Gemini's
            // thinking budget.
            $perFieldBudget = 200;
            $hasTable = false;
            $maxTokens = 2000;
            foreach ($subset as $sf) {
                $t = $sf['type'] ?? 'text';
                if ('table' === $t) {
                    $hasTable = true;
                    continue;
                }
                $maxTokens += ('list' === $t) ? 1500 : $perFieldBudget;
            }
            if ($hasTable) {
                // A table dominates the output budget regardless of
                // sibling fields in the same group. Capping table
                // groups separately keeps the prompt-control logic
                // cleaner downstream.
                $maxTokens = 16000;
            } else {
                $maxTokens = min($maxTokens, 12000);
            }

            $started = microtime(true);
            try {
                $messages = [
                    ['role' => 'system', 'content' => 'You are a precise document parsing assistant. Return only valid JSON.'],
                    ['role' => 'user', 'content' => $prompt],
                ];
                // Incremental re-runs ("fill missing fields") may use the
                // dedicated second-opinion model when one is configured.
                $aiOptions = $onlyMissing
                    ? $this->resolveSecondPassModelOptions($userId)
                    : $this->resolveAiModelOptions($userId);
                $aiOptions['max_tokens'] = $maxTokens;
                // Disable Gemini Flash's hidden thinking budget (saves
                // 5–8 s per call). See note on the form-enhancement
                // call above for why both keys are set.
                $aiOptions['thinking_budget'] = 0;
                $aiOptions['reasoning_effort'] = 'low';
                $aiOptions['temperature'] = 0;

                $result = $this->extractionChat($messages, $userId, $aiOptions, 'group:'.$groupKey, $candidateId);
                $content = (string) ($result['content'] ?? '');
                $modelUsed = $result['model'] ?? $modelUsed;
                $parsed = $this->parseJsonFromAiResponse($content);
                $elapsedMs = (int) ((microtime(true) - $started) * 1000);

                if (null === $parsed || !is_array($parsed) || array_is_list($parsed)) {
                    $rawPreview = mb_substr($content, 0, 500);
                    $this->logger->warning('Synaform: group extraction returned unusable shape', [
                        'candidateId' => $candidateId,
                        'group' => $groupKey,
                        'model' => $modelUsed,
                        'response_chars' => strlen($content),
                        'parsed_kind' => null === $parsed ? 'null' : (array_is_list($parsed) ? 'list' : 'unknown'),
                        'raw_preview' => $rawPreview,
                    ]);
                    $groupTelemetry[] = [
                        'key' => $groupKey,
                        'label' => $groupLabel,
                        'fields_in_group' => count($groupFieldKeys),
                        'fields_returned' => 0,
                        'succeeded' => false,
                        'error' => '' === $content ? 'empty AI response' : 'unparseable AI response',
                        'response_chars' => strlen($content),
                        'elapsed_ms' => $elapsedMs,
                        'raw_preview' => mb_substr($content, 0, 400),
                        'max_tokens' => $maxTokens,
                    ];
                    continue;
                }

                $groupClean = [];
                foreach ($parsed as $k => $v) {
                    if (!is_string($k) || !in_array($k, $groupFieldKeys, true)) {
                        continue;
                    }
                    $groupClean[$k] = $v;
                }
                foreach ($groupClean as $k => $v) {
                    $allMerged[$k] = $v;
                }
                $groupTelemetry[] = [
                    'key' => $groupKey,
                    'label' => $groupLabel,
                    'fields_in_group' => count($groupFieldKeys),
                    'fields_returned' => count($groupClean),
                    'succeeded' => true,
                    'elapsed_ms' => $elapsedMs,
                ];
            } catch (\Throwable $e) {
                $elapsedMs = (int) ((microtime(true) - $started) * 1000);
                $this->logger->warning('Synaform: group extraction failed', [
                    'candidateId' => $candidateId,
                    'group' => $groupKey,
                    'error' => $e->getMessage(),
                ]);
                $groupTelemetry[] = [
                    'key' => $groupKey,
                    'label' => $groupLabel,
                    'fields_in_group' => count($groupFieldKeys),
                    'fields_returned' => 0,
                    'succeeded' => false,
                    'error' => $e->getMessage(),
                    'elapsed_ms' => $elapsedMs,
                ];
            }
        }

        $totalElapsedMs = (int) ((microtime(true) - $startedAll) * 1000);
        $groupsRun = array_values(array_filter($groupTelemetry, static fn ($g) => empty($g['skipped'])));
        $groupsSucceeded = array_values(array_filter($groupsRun, static fn ($g) => !empty($g['succeeded'])));
        $groupsSkipped = array_values(array_filter($groupTelemetry, static fn ($g) => !empty($g['skipped'])));

        // Overall failure: nothing ran (no groups had work to do, all
        // skipped) OR all run-groups failed AND we have no merged fields.
        if (count($groupsRun) > 0 && 0 === count($groupsSucceeded) && empty($allMerged)) {
            $worst = $groupsRun[0] ?? [];
            $errMsg = (string) ($worst['error'] ?? 'Document parsing failed for every field group.');
            $summary = $this->buildAndPersistParseSummary(
                $userId,
                $candidateId,
                $fileReports,
                $unreadable,
                [],
                $allowedKeys,
                (string) ($modelUsed ?? 'unknown'),
                $onlyMissing ? 'grouped_only_missing' : 'grouped_fallback',
                $totalElapsedMs,
                $deadlineHit,
                false,
                $errMsg,
            );

            return $this->json([
                'success' => false,
                'outcome' => 'failed',
                'error' => $errMsg,
                'documents_parsed' => count($allTexts),
                'model' => $modelUsed ?? 'unknown',
                'doc_chars' => mb_strlen($combinedText),
                'groups' => $groupTelemetry,
                'groups_total' => count($groupTelemetry),
                'groups_succeeded' => 0,
                'groups_skipped' => count($groupsSkipped),
                'total_elapsed_ms' => $totalElapsedMs,
                'files' => $fileReports,
                'unreadable' => $unreadable,
                'last_parse' => $summary,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Incremental mode fills gaps only: a re-extracted group can contain
        // fields the user already filled or corrected by hand — those
        // suggestions must never overwrite existing values.
        if ($onlyMissing) {
            $allMerged = array_filter(
                $allMerged,
                fn ($v, $k) => !$this->fieldValueIsFilled($existingValues[$k] ?? null),
                ARRAY_FILTER_USE_BOTH
            );
        }

        if ([] === $unreadable) {
            $this->markCandidateExtracted($userId, $candidateId, $modelUsed ?? 'unknown');
        }

        $summary = $this->buildAndPersistParseSummary(
            $userId,
            $candidateId,
            $fileReports,
            $unreadable,
            $allMerged,
            $allowedKeys,
            (string) ($modelUsed ?? 'unknown'),
            $onlyMissing ? 'grouped_only_missing' : 'grouped_fallback',
            $totalElapsedMs,
            $deadlineHit,
            true,
        );

        return $this->json([
            'success' => true,
            'outcome' => $summary['outcome'],
            'suggestions' => $allMerged,
            'documents_parsed' => count($allTexts),
            'model' => $modelUsed ?? 'unknown',
            'doc_chars' => mb_strlen($combinedText),
            'fields_returned' => count($allMerged),
            'fields_filled' => $summary['fields_filled'],
            'fields_total' => $summary['fields_total'],
            'groups' => $groupTelemetry,
            'groups_total' => count($groupTelemetry),
            'groups_succeeded' => count($groupsSucceeded),
            'groups_skipped' => count($groupsSkipped),
            'total_elapsed_ms' => $totalElapsedMs,
            'only_missing' => $onlyMissing,
            'ocr_cache' => $cacheStats,
            'strategy' => $onlyMissing ? 'grouped_only_missing' : 'grouped_fallback',
            'single_call_skipped_reason' => $singleCallReason ?: null,
            'files' => $fileReports,
            'unreadable' => $unreadable,
            'deadline_hit' => $deadlineHit,
            'last_parse' => $summary,
        ]);
    }

    /**
     * Mark a candidate as extracted after a successful parse-documents run.
     *
     * The dataset detail UI only shows the "extraction done / go to review"
     * card when the candidate status is extracted/reviewed/generated. The
     * legacy /extract endpoint set that status, but the live parse-documents
     * path never did — so after a successful scan the dataset stayed in
     * `draft` and the done card never appeared (customer feedback: "erfasst"
     * button missing after processing).
     *
     * Only upgrades from draft/incomplete — a dataset already reviewed or
     * generated must not be downgraded by a re-scan. Reloads the entry to
     * avoid clobbering file metadata persisted earlier in the same request.
     */
    private function markCandidateExtracted(int $userId, string $candidateId, string $modelUsed): void
    {
        $entry = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);
        if (!is_array($entry)) {
            return;
        }

        $status = (string) ($entry['status'] ?? 'draft');
        if (in_array($status, ['', 'draft', 'incomplete'], true)) {
            $entry['status'] = 'extracted';
        }
        $entry['extraction_info'] = [
            'model_used' => $modelUsed,
            'finished_at' => date('c'),
        ];
        $entry['updated_at'] = date('c');
        $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId, $entry);
    }

    /**
     * Build a user-facing parse summary, persist it on the candidate as
     * `last_parse`, and return it so the response + UI stay in sync.
     *
     * Outcome vocabulary (stable contract for the frontend):
     *   - complete — every source readable and enough fields filled
     *   - partial  — some sources unread, deadline hit, or many fields empty
     *   - failed   — no usable result
     *
     * @param list<array<string, mixed>> $fileReports
     * @param list<array<string, mixed>> $unreadable
     * @param array<string, mixed>       $suggestions
     * @param list<string>               $allowedKeys
     *
     * @return array<string, mixed>
     */
    private function buildAndPersistParseSummary(
        int $userId,
        string $candidateId,
        array $fileReports,
        array $unreadable,
        array $suggestions,
        array $allowedKeys,
        string $model,
        string $strategy,
        int $elapsedMs,
        bool $deadlineHit,
        bool $success,
        ?string $error = null,
    ): array {
        $sourcesOk = 0;
        foreach ($fileReports as $report) {
            if (!empty($report['ok'])) {
                ++$sourcesOk;
            }
        }
        $fieldsFilled = 0;
        foreach ($allowedKeys as $key) {
            if ($this->fieldValueIsFilled($suggestions[$key] ?? null)) {
                ++$fieldsFilled;
            }
        }
        $fieldsTotal = count($allowedKeys);
        $sourcesTotal = count($fileReports);
        $sourcesUnreadable = count($unreadable);

        if (!$success || ($sourcesTotal > 0 && 0 === $sourcesOk && 0 === $fieldsFilled)) {
            $outcome = 'failed';
        } elseif ($sourcesUnreadable > 0 || $deadlineHit || ($fieldsTotal > 0 && $fieldsFilled < (int) ceil($fieldsTotal * 0.5))) {
            $outcome = 'partial';
        } else {
            $outcome = 'complete';
        }

        return $this->persistLastParseSummary($userId, $candidateId, [
            'outcome' => $outcome,
            'model' => $model,
            'strategy' => $strategy,
            'elapsed_ms' => $elapsedMs,
            'sources_total' => $sourcesTotal,
            'sources_ok' => $sourcesOk,
            'sources_unreadable' => $sourcesUnreadable,
            'unreadable_files' => $this->summarizeUnreadableFiles($unreadable),
            'fields_filled' => $fieldsFilled,
            'fields_total' => $fieldsTotal,
            'deadline_hit' => $deadlineHit,
            'error' => $error,
            'files' => $fileReports,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $unreadable
     *
     * @return list<array{filename: string, error: string}>
     */
    private function summarizeUnreadableFiles(array $unreadable): array
    {
        $out = [];
        foreach ($unreadable as $row) {
            $out[] = [
                'filename' => (string) ($row['filename'] ?? ''),
                'error' => (string) ($row['error'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $summary
     *
     * @return array<string, mixed>
     */
    private function persistLastParseSummary(int $userId, string $candidateId, array $summary): array
    {
        $summary['finished_at'] = date('c');
        $entry = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);
        if (is_array($entry)) {
            $entry['last_parse'] = $summary;
            $entry['updated_at'] = date('c');
            $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId, $entry);
        }

        return $summary;
    }

    /**
     * After Symfony's UploadedFile::move(), flush PHP's stat cache and set
     * NFS-friendly permissions so sibling web nodes can see the file promptly.
     */
    private function finalizeUploadedFile(string $absolutePath): void
    {
        clearstatcache(true, $absolutePath);
        if (is_file($absolutePath)) {
            FileHelper::setFilePermissions($absolutePath);
        }
        // Also clear the parent dir listing — NFS caches that separately and
        // is_file() on another node often fails until the dir cache expires.
        clearstatcache(true, dirname($absolutePath));
    }

    /**
     * Wait until an upload is visible on this node (NFS attribute-cache aware).
     *
     * Production (synaplan-platform): Cloudflare round-robins web1/web2/web3
     * with no sticky sessions. Uploads land on NFS (`up/` → s1). A file
     * written on web1 may still fail a plain is_file() on web2 for up to
     * ~actimeo seconds. Core FileHelper::fileExistsNfs() forces a refresh;
     * we additionally retry briefly to cover the documented 1s actimeo.
     */
    private function waitForUploadFile(string $absolutePath, int $attempts = 8, int $sleepMs = 200): bool
    {
        for ($i = 0; $i < $attempts; ++$i) {
            if (FileHelper::fileExistsNfs($absolutePath)) {
                if ($i > 0) {
                    $this->logger->info('Synaform: upload became visible after NFS wait', [
                        'path' => $absolutePath,
                        'attempt' => $i + 1,
                        'hostname' => gethostname() ?: 'unknown',
                    ]);
                }

                return true;
            }
            if ($i + 1 < $attempts) {
                usleep($sleepMs * 1000);
            }
        }

        return false;
    }

    private function secondsLeftOnParse(float $startedAt): float
    {
        return self::PARSE_REQUEST_BUDGET_SECONDS - (microtime(true) - $startedAt);
    }

    private function isOverParseBudget(float $startedAt, float $needSeconds = 0.0): bool
    {
        return $this->secondsLeftOnParse($startedAt) < $needSeconds;
    }

    private function parseElapsedMs(float $startedAt): int
    {
        return (int) ((microtime(true) - $startedAt) * 1000);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function persistCandidateNow(int $userId, string $candidateId, array &$entry): void
    {
        $entry['updated_at'] = date('c');
        $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId, $entry);
    }

    /**
     * Accept a multipart upload with explicit errors for size, PHP ini
     * limits, empty files, and unsupported types. Never return a silent
     * "No file uploaded" when the real cause is a too-large scan.
     *
     * @param list<string> $allowedExtensions
     *
     * @return array{ok: true, file: UploadedFile}|array{ok: false, error: string, status: int}
     */
    private function acceptUploadedFile(Request $request, array $allowedExtensions, int $maxBytes, string $field = 'file'): array
    {
        $file = $request->files->get($field);
        if (!$file instanceof UploadedFile) {
            $contentLength = (int) $request->headers->get('Content-Length', '0');
            $postMax = $this->phpIniBytes((string) ini_get('post_max_size'));
            $uploadMax = $this->phpIniBytes((string) ini_get('upload_max_filesize'));
            $limit = min($maxBytes, $postMax > 0 ? $postMax : $maxBytes, $uploadMax > 0 ? $uploadMax : $maxBytes);
            if ($contentLength > 0 && $limit > 0 && $contentLength > $limit) {
                return [
                    'ok' => false,
                    'error' => sprintf(
                        'File is too large (%d MB). Maximum size is %d MB. Compress the scan or split it into fewer pages.',
                        (int) ceil($contentLength / 1048576),
                        (int) floor($limit / 1048576)
                    ),
                    'status' => Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
                ];
            }

            return [
                'ok' => false,
                'error' => 'No file was received. If this was a large iPhone scan, the upload may have been blocked by size or a dropped connection — try again, or save as a smaller PDF.',
                'status' => Response::HTTP_BAD_REQUEST,
            ];
        }

        $phpError = $file->getError();
        if (\UPLOAD_ERR_OK !== $phpError) {
            $status = in_array($phpError, [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)
                ? Response::HTTP_REQUEST_ENTITY_TOO_LARGE
                : Response::HTTP_BAD_REQUEST;

            return [
                'ok' => false,
                'error' => $this->phpUploadErrorMessage($phpError, $maxBytes),
                'status' => $status,
            ];
        }

        $size = (int) $file->getSize();
        if ($size <= 0) {
            return [
                'ok' => false,
                'error' => 'The uploaded file is empty (0 bytes). Re-export the scan and try again.',
                'status' => Response::HTTP_BAD_REQUEST,
            ];
        }
        if ($size > $maxBytes) {
            return [
                'ok' => false,
                'error' => sprintf(
                    'File is too large (%s). Maximum size is %d MB.',
                    $this->formatMb($size),
                    (int) floor($maxBytes / 1048576)
                ),
                'status' => Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            ];
        }

        $ext = strtolower($file->getClientOriginalExtension());
        if ('' === $ext || !in_array($ext, $allowedExtensions, true)) {
            return [
                'ok' => false,
                'error' => sprintf(
                    'Unsupported file type%s. Allowed: %s.',
                    '' !== $ext ? ': '.$ext : '',
                    implode(', ', $allowedExtensions)
                ),
                'status' => Response::HTTP_BAD_REQUEST,
            ];
        }

        return ['ok' => true, 'file' => $file];
    }

    private function phpUploadErrorMessage(int $errorCode, int $maxBytes): string
    {
        $maxMb = (int) floor($maxBytes / 1048576);

        return match ($errorCode) {
            \UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE => sprintf(
                'File exceeds the server upload limit (max %d MB). Compress the scan or split it into fewer pages.',
                $maxMb
            ),
            \UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded — the connection dropped. Please try again.',
            \UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            \UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temporary folder for uploads. Please contact support.',
            \UPLOAD_ERR_CANT_WRITE => 'Server could not write the uploaded file to disk. Please try again.',
            \UPLOAD_ERR_EXTENSION => 'A server extension stopped the upload. Please try a different file format (PDF).',
            default => 'File upload failed (error code '.$errorCode.'). Please try again.',
        };
    }

    private function phpIniBytes(string $value): int
    {
        $value = trim($value);
        if ('' === $value || '0' === $value) {
            return 0;
        }
        $unit = strtolower(substr($value, -1));
        $num = (float) $value;

        return (int) match ($unit) {
            'g' => $num * 1073741824,
            'm' => $num * 1048576,
            'k' => $num * 1024,
            default => (int) $value,
        };
    }

    private function formatMb(int $bytes): string
    {
        if ($bytes < 1048576) {
            return max(1, (int) round($bytes / 1024)).' KB';
        }

        return number_format($bytes / 1048576, 1).' MB';
    }

    /**
     * Cached wrapper around FileProcessor::extractText().
     *
     * Each candidate file (CV or additional) carries an `ocr_cache`
     * dict on its metadata: `{hash: sha1, text: <extracted>, cached_at,
     * strategy, bytes}`. On every parse-documents (or extract) call we
     * sha1 the file bytes; if the hash matches the cache we skip the
     * extraction entirely (saves ~5–15s for image OCR, ~1–3s for Tika).
     * If the file gets re-uploaded, its bytes change and the hash
     * mismatches → cache invalidates automatically.
     *
     * The cache is bounded at 200k chars per file to keep plugin_data
     * rows reasonable; if extraction yields more than that we don't
     * cache (rare, only for very long PDFs) — extraction still works,
     * just not cached.
     *
     * @param array<string, mixed> $fileMeta   Reference to the file's metadata entry
     * @param array<string, int>   $cacheStats Reference to ['hits' => int, 'misses' => int]
     *
     * @return array{0: string, 1: array<string, mixed>} [extractedText, meta-with-cache_hit/cache_updated]
     */
    private function extractTextWithFileCache(
        array &$fileMeta,
        string $candidateDir,
        string $relativePath,
        string $ext,
        int $userId,
        array &$cacheStats,
    ): array {
        $storedAs = (string) ($fileMeta['stored_as'] ?? '');
        $absolutePath = $candidateDir.'/'.$storedAs;
        if ('' === $storedAs || !$this->waitForUploadFile($absolutePath)) {
            // No file on disk (or NFS still stale after wait) — fall through
            // so FileProcessor's existing error handling kicks in. Don't bump
            // cache counters; this isn't a cache decision.
            [$text, $meta] = $this->fileProcessor->extractText($relativePath, $ext, $userId);

            return [is_string($text) ? $text : '', is_array($meta) ? $meta : []];
        }

        $hash = @sha1_file($absolutePath);
        if (false === $hash || '' === $hash) {
            // Hash failure — fall through, no cache state change.
            [$text, $meta] = $this->fileProcessor->extractText($relativePath, $ext, $userId);

            return [is_string($text) ? $text : '', is_array($meta) ? $meta : []];
        }

        $cache = $fileMeta['ocr_cache'] ?? null;
        if (
            is_array($cache)
            && ($cache['hash'] ?? '') === $hash
            && isset($cache['text'])
            && is_string($cache['text'])
        ) {
            ++$cacheStats['hits'];

            return [
                $cache['text'],
                ['strategy' => 'ocr_cache', 'cache_hit' => true, 'cache_updated' => false],
            ];
        }

        ++$cacheStats['misses'];
        [$text, $meta] = $this->fileProcessor->extractText($relativePath, $ext, $userId);
        $textStr = is_string($text) ? $text : '';
        $metaArr = is_array($meta) ? $meta : [];

        $cacheUpdated = false;
        // Only cache substantive results — empty/failed extractions
        // shouldn't be sticky, otherwise a transient Vision blip would
        // poison the cache and stay null forever.
        if ('' !== trim($textStr) && mb_strlen($textStr) <= 200000) {
            $fileMeta['ocr_cache'] = [
                'hash' => $hash,
                'text' => $textStr,
                'cached_at' => date('c'),
                'strategy' => $metaArr['strategy'] ?? null,
                'bytes' => strlen($textStr),
            ];
            $cacheUpdated = true;
        }

        return [
            $textStr,
            $metaArr + ['cache_hit' => false, 'cache_updated' => $cacheUpdated],
        ];
    }

    /**
     * Classify a source file by its filename so the AI can attribute
     * content correctly (a CV vs. an Arbeitszeugnis/certificate) and so
     * the combined text carries a meaningful section label. Cheap
     * keyword heuristic — the AI still reads the actual content.
     */
    private function classifyDocKind(string $filename, string $default = 'other'): string
    {
        $n = mb_strtolower($filename);
        foreach (['zeugnis', 'arbeitszeugnis', 'certificate', 'zertifikat', 'diploma', 'diplom', 'urkunde', 'reference', 'referenz'] as $needle) {
            if (str_contains($n, $needle)) {
                return 'certificate';
            }
        }
        foreach (['cv', 'lebenslauf', 'resume', 'resumé', 'vita', 'curriculum'] as $needle) {
            if (str_contains($n, $needle)) {
                return 'cv';
            }
        }

        return $default;
    }

    /**
     * Human-readable section label for a document kind, used both as the
     * "=== … ===" header in the combined extraction text and as the badge
     * shown per file in the Sources list.
     */
    private function docKindLabel(string $kind): string
    {
        return match ($kind) {
            'certificate' => 'Certificate/Zeugnis',
            'cv' => 'CV',
            default => 'Document',
        };
    }

    /**
     * Build a per-file extraction report, persist it onto the file's
     * metadata (`extraction`) so the frontend can render a readable /
     * "not readable" status, and return it for the response payload.
     *
     * @param array<string, mixed> $fileMeta reference to the file's metadata entry
     * @param array<string, mixed> $meta     extraction meta returned by the cache wrapper
     *
     * @return array<string, mixed>
     */
    private function recordFileExtraction(array &$fileMeta, string $name, string $slot, string $kind, string $text, array $meta): array
    {
        $chars = mb_strlen(trim($text));
        $ok = $chars > 0;
        $strategy = (string) ($meta['strategy'] ?? '');
        if ('' === $strategy) {
            $strategy = $ok ? 'extracted' : 'empty';
        }
        $report = [
            'filename' => $name,
            'slot' => $slot,
            'kind' => $kind,
            'kind_label' => $this->docKindLabel($kind),
            'chars' => $chars,
            'ok' => $ok,
            'strategy' => $strategy,
            'checked_at' => date('c'),
        ];
        $fileMeta['extraction'] = $report;

        return $report;
    }

    /**
     * Build (and persist) a failure report for a file whose extraction
     * threw, so it is still surfaced to the user instead of vanishing.
     *
     * @param array<string, mixed> $fileMeta reference to the file's metadata entry
     *
     * @return array<string, mixed>
     */
    private function failedFileExtraction(array &$fileMeta, string $name, string $slot, string $kind, string $error): array
    {
        $report = [
            'filename' => $name,
            'slot' => $slot,
            'kind' => $kind,
            'kind_label' => $this->docKindLabel($kind),
            'chars' => 0,
            'ok' => false,
            'strategy' => 'error',
            'error' => $error,
            'checked_at' => date('c'),
        ];
        $fileMeta['extraction'] = $report;

        return $report;
    }

    /**
     * v4.0 guaranteed-completeness second pass. Given the accepted
     * single-call result, run ONE focused AI call restricted to the
     * fields that are still empty and merge any recovered values back in.
     * This means a single "Read files & auto-fill" click returns a
     * complete result instead of the user having to press it twice.
     *
     * No-op when nothing is missing, or when EVERYTHING is missing (the
     * single call effectively produced nothing — the grouped fallback is
     * the better tool for that case, so we let the caller drop into it).
     *
     * @param list<array<string, mixed>> $fields
     * @param list<string>               $allowedKeys
     * @param array<string, mixed>       $current
     *
     * @return array<string, mixed>
     */
    private function fillMissingFieldsSecondPass(
        int $userId,
        array $fields,
        array $allowedKeys,
        array $current,
        string $combinedText,
        string $languageName,
        string $candidateId,
    ): array {
        $missing = [];
        foreach ($allowedKeys as $k) {
            if (!$this->fieldValueIsFilled($current[$k] ?? null)) {
                $missing[] = $k;
            }
        }
        $missingCount = count($missing);
        $total = count($allowedKeys);
        // Only spend a second (potentially slow) model round-trip when the
        // first pass left a SUBSTANTIAL gap. Skip when:
        //   - nothing is missing (already complete),
        //   - everything is missing (the single call effectively failed →
        //     the grouped fallback is the better tool, let the caller drop
        //     into it),
        //   - only a handful of fields are missing (not worth doubling the
        //     wall-clock time on a slow reasoning model; the user can tweak
        //     a stray field by hand).
        // This keeps the common "one call fills most fields" path a SINGLE
        // call while still recovering when a model under-delivers badly.
        $secondPassGate = max(3, (int) ceil($total * 0.25));
        if (0 === $missingCount || $missingCount === $total || $missingCount < $secondPassGate) {
            return $current;
        }

        $subset = array_values(array_filter(
            $fields,
            static fn ($f) => in_array((string) ($f['key'] ?? ''), $missing, true)
        ));
        if (empty($subset)) {
            return $current;
        }

        $hasTable = false;
        foreach ($subset as $sf) {
            if (($sf['type'] ?? '') === 'table') {
                $hasTable = true;
                break;
            }
        }

        try {
            $prompt = $this->buildGroupedExtractionPrompt($userId, $subset, $combinedText, $languageName, null);
            $messages = [
                ['role' => 'system', 'content' => 'You are a precise document parsing assistant. Return only valid JSON.'],
                ['role' => 'user', 'content' => $prompt],
            ];
            $aiOptions = $this->resolveSecondPassModelOptions($userId);
            $aiOptions['max_tokens'] = $hasTable ? 16000 : 8000;
            $aiOptions['thinking_budget'] = 0;
            $aiOptions['reasoning_effort'] = 'low';
            $aiOptions['temperature'] = 0;

            $result = $this->extractionChat($messages, $userId, $aiOptions, 'second_pass', $candidateId);
            $parsed = $this->parseJsonFromAiResponse((string) ($result['content'] ?? ''));
            if (is_array($parsed) && !array_is_list($parsed)) {
                foreach ($parsed as $k => $v) {
                    if (is_string($k) && in_array($k, $missing, true) && $this->fieldValueIsFilled($v)) {
                        $current[$k] = $v;
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->info('Synaform: second-pass extraction failed (non-fatal)', [
                'candidateId' => $candidateId,
                'error' => $e->getMessage(),
            ]);
        }

        return $current;
    }

    /**
     * Build the field-extraction prompt for a focused subset of fields.
     * The rules block is constant, only the fields block changes per
     * group. Pulled out of candidatesParseDocuments() so each group
     * can render its own prompt with just its own fields, keeping
     * the AI output small enough to fit in one response.
     *
     * Distinct from the legacy buildExtractionPrompt() helper used by
     * the chat-message extraction path (different signature/purpose).
     */
    private function buildGroupedExtractionPrompt(
        int $userId,
        array $fieldsSubset,
        string $combinedText,
        string $extractionLanguageName,
        ?string $groupContext = null,
    ): string {
        $fieldDescriptions = [];
        foreach ($fieldsSubset as $field) {
            $type = $field['type'] ?? 'text';
            $key = (string) ($field['key'] ?? '');
            $label = (string) ($field['label'] ?? $key);
            // Header line: `key (type): label`
            $lines = [$key.' ('.$type.'): '.$label];

            // Rich semantic context (v3.7.2+). `description` is the
            // primary disambiguator. `examples` anchor expected format.
            // `negative_hint` rules out common confusions.
            //
            // The user-visible "Help text / AI hint" (`hint`) is emitted as
            // an "Instructions:" line — the extraction_rules prompt section
            // declares those BINDING, so a hint like "Formattiere die Nummer
            // in der Form 0049 (0) 175 234 567 89" actually changes the
            // returned value instead of being treated as a loose description
            // (customer feedback: field annotations were ignored).
            if (!empty($field['description'])) {
                $lines[] = '  Description: '.trim((string) $field['description']);
            }
            if (!empty($field['hint'])) {
                $lines[] = '  Instructions: '.trim((string) $field['hint']);
            }
            if (!empty($field['examples']) && is_array($field['examples'])) {
                $exQuoted = array_map(static fn ($e) => '"'.trim((string) $e).'"', $field['examples']);
                $lines[] = '  Examples: '.implode(', ', $exQuoted);
            }
            if (!empty($field['negative_hint'])) {
                $lines[] = '  NOT: '.trim((string) $field['negative_hint']);
            }
            if (!empty($field['options'])) {
                $lines[] = '  Allowed values (return ONE of these or null): '.implode(', ', $field['options']);
            }
            if ('list' === $type && empty($field['options'])) {
                $lines[] = '  Format: JSON array of strings, one entry per item.';
            }

            if ('table' === $type) {
                $columns = $field['columns'] ?? [];
                $colDescs = array_map(function ($c) {
                    $ck = (string) ($c['key'] ?? '');
                    $cl = (string) ($c['label'] ?? $ck);
                    $colType = (string) ($c['type'] ?? 'text');

                    return $ck.' ('.$cl.', type='.$colType.')';
                }, $columns);
                $flatListCols = [];
                $structuredListCols = [];
                foreach ($columns as $c) {
                    if (!is_array($c) || ($c['type'] ?? '') !== 'list') {
                        continue;
                    }
                    if ($this->isStructuredListColumn($c)) {
                        $structuredListCols[] = (string) ($c['key'] ?? '');
                    } else {
                        $flatListCols[] = (string) ($c['key'] ?? '');
                    }
                }
                $lines[] = '  Columns: '.implode(', ', $colDescs)
                    .'. Return JSON array of objects with these column keys.';
                if (!empty($flatListCols)) {
                    $lines[] = '  These columns must be JSON arrays of short strings, one bullet per item, no markdown, no dashes, no numbering: '
                        .implode(', ', $flatListCols);
                }
                if (!empty($structuredListCols)) {
                    $lines[] = '  STRUCTURED HR-profile list columns: '.implode(', ', $structuredListCols)
                        .'. Each entry follows: (1) optional date range header (e.g. "08/2024 – 07/2025"), '
                        .'(2) position title as a single string with no bullet prefix, '
                        .'(3) one task per element afterwards. Use empty string "" to separate two positions at the same employer. '
                        .'For a single-position station you may omit the date header. '
                        .'Do NOT include "-", "•", "*" or numbering inside the strings; the renderer adds bullets automatically.';
                }
            }
            $fieldDescriptions[] = implode("\n", $lines);
        }

        $fieldsBlock = implode("\n", array_map(fn ($d) => '- '.$d, $fieldDescriptions));
        $contextLine = null !== $groupContext && '' !== $groupContext
            ? "Field group: {$groupContext}\n\n"
            : '';

        // Editable prompt sections (Prompts tab). `extraction_rules` carries
        // the extraction + anti-duplication rule set; `extraction_context`
        // injects "today" so recording-context fields (e.g. "Monat der
        // Erfassung") default to the current date instead of a date scraped
        // from the documents. Tokens: {today}, {month}, {year}, {language}.
        $tokens = $this->promptTokens($extractionLanguageName);
        $rulesBlock = strtr($this->getPromptText($userId, 'extraction_rules'), $tokens);
        $contextBlock = strtr($this->getPromptText($userId, 'extraction_context'), $tokens);

        return <<<PROMPT
        You are an assistant that extracts form field values from documents.
        Below are the form fields that need to be filled, followed by the document text.

        {$contextLine}IMPORTANT - Output language: All free-text string values you return MUST be
        written in {$extractionLanguageName}. If the source documents are in another
        language, translate descriptive text (job titles, summaries, list items,
        achievements, education descriptions, etc.) into {$extractionLanguageName}.
        Proper nouns (people, company names, cities), email addresses, phone numbers,
        URLs, and dates stay verbatim.

        {$rulesBlock}

        {$contextBlock}

        Form fields:
        {$fieldsBlock}

        Documents:
        ---
        {$combinedText}
        ---

        Return ONLY a valid JSON object where keys are the field keys (from the list above) and values are the extracted data. Do NOT include keys that are not in the list.
        STRICT output format:
        - Output a single JSON object — nothing before or after it. No prose, no commentary.
        - No markdown: no triple-backtick fences, no asterisk bullets, no leading "- ", no headings.
        - For "list" fields, the value MUST be a JSON array of strings (e.g. ["item one","item two"]). NEVER emit "* item" or "- item" markdown — only JSON arrays.
        - All strings use double quotes; escape internal double quotes with \\".
        PROMPT;
    }

    /**
     * "Filled" check used by the onlyMissing query param so we can skip
     * groups that already have user-supplied values. Treats null,
     * empty string, and empty arrays as unfilled. Booleans and
     * numbers count as filled (a saved `false` checkbox is a real
     * answer).
     */
    private function fieldValueIsFilled(mixed $v): bool
    {
        if (null === $v || '' === $v) {
            return false;
        }
        if (is_array($v)) {
            return !empty($v);
        }

        return true;
    }

    /**
     * Lazily compute and cache an AI-driven clustering of the form's
     * fields into 3-7 logical extraction groups. Cached on the form
     * itself, keyed by a hash of the field shape so the cache
     * invalidates cleanly whenever the admin edits fields/types.
     *
     * On AI failure we fall back to a single all-fields group; the
     * caller still gets a working extraction, just without per-group
     * isolation benefits.
     *
     * @return array<int, array{key: string, label: string, field_keys: list<string>}>
     */
    private function getOrBuildExtractionGroups(int $userId, array &$form): array
    {
        $fields = $form['fields'] ?? [];
        if (empty($fields)) {
            return [];
        }
        $signature = $this->extractionGroupsSignature($fields);
        $cached = $form['extraction_groups'] ?? null;
        $cachedSig = $form['extraction_groups_signature'] ?? null;
        if (is_array($cached) && !empty($cached) && $cachedSig === $signature) {
            return $cached;
        }

        $groups = $this->askAiToClusterFields($fields, $userId);
        if (empty($groups)) {
            // Fallback: single all-fields group. Still passes through
            // the new pipeline so per-group telemetry is consistent.
            $groups = [[
                'key' => 'all',
                'label' => 'All fields',
                'field_keys' => array_values(array_filter(
                    array_map(static fn ($f) => (string) ($f['key'] ?? ''), $fields),
                    static fn ($k) => '' !== $k
                )),
            ]];
        }

        // Persist the cache. We swallow set() errors silently — failing
        // to cache is just a performance hit on the next call, not a
        // correctness issue.
        try {
            $form['extraction_groups'] = $groups;
            $form['extraction_groups_signature'] = $signature;
            $form['extraction_groups_built_at'] = date('c');
            $formId = (string) ($form['id'] ?? '');
            if ('' !== $formId) {
                $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_FORM, $formId, $form);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Synaform: failed to persist extraction_groups cache', [
                'error' => $e->getMessage(),
            ]);
        }

        return $groups;
    }

    /**
     * Hash the relevant shape of fields[] so we can detect when the
     * admin has edited the form (added/removed/retyped a field) and
     * needs the AI re-grouping refreshed.
     */
    private function extractionGroupsSignature(array $fields): string
    {
        $keys = [];
        foreach ($fields as $f) {
            $keys[] = ($f['key'] ?? '').':'.($f['type'] ?? 'text');
        }
        sort($keys);

        return sha1(implode('|', $keys));
    }

    /**
     * Ask the AI to cluster the form's fields into 3-7 logical groups
     * for parallel/incremental extraction. Returns
     * `[{key, label, field_keys: [...]}, ...]`. Empty array on any
     * failure mode (caller falls back to a single group).
     *
     * @return list<array{key: string, label: string, field_keys: list<string>}>
     */
    private function askAiToClusterFields(array $fields, int $userId): array
    {
        $list = [];
        foreach ($fields as $f) {
            $key = (string) ($f['key'] ?? '');
            if ('' === $key) {
                continue;
            }
            $type = (string) ($f['type'] ?? 'text');
            $label = (string) ($f['label'] ?? $key);
            $entry = "{$key} (type={$type}, label=\"{$label}\")";
            if ('table' === $type) {
                $entry .= ' [TABLE — likely its own group due to output volume]';
            }
            $list[] = $entry;
        }
        if (empty($list)) {
            return [];
        }

        $fieldsBlock = implode("\n", array_map(fn ($s) => '- '.$s, $list));
        $prompt = <<<PROMPT
        You are organising a form definition into logical extraction groups.

        We will run separate document-extraction AI calls per group, so each
        group should be small enough that the JSON output for that group's
        fields fits comfortably in a few thousand tokens, and each group
        should contain semantically related fields that a person would fill
        out together.

        Guidelines:
        - Aim for 3 to 7 groups.
        - Group related fields together (personal info, contact, current job, target job, education, skills, mobility, history-table, etc.).
        - Put each TABLE field in its own group — tables produce far more JSON output than scalar fields.
        - Use lowercase snake_case keys (e.g. "personal_info", "contact", "work_history").
        - Use short human labels (e.g. "Personal info", "Contact", "Work history").
        - Every field key must appear in exactly ONE group.
        - Do not invent field keys that are not in the list.

        Form fields:
        {$fieldsBlock}

        Return ONLY valid JSON in this exact shape:
        {"groups":[{"key":"...","label":"...","field_keys":["...","..."]}]}
        PROMPT;

        try {
            $messages = [
                ['role' => 'system', 'content' => 'You are an expert form designer. Return only valid JSON.'],
                ['role' => 'user', 'content' => $prompt],
            ];
            $aiOptions = $this->resolveAiModelOptions($userId);
            // Clustering output is tiny (just keys + labels) — disable
            // thinking entirely so this one-time call returns in 2–3 s
            // instead of 8–10 s (Gemini Flash silently burns thinking
            // tokens when no thinkingConfig is sent).
            $aiOptions['max_tokens'] = 4000;
            $aiOptions['thinking_budget'] = 0;
            $aiOptions['reasoning_effort'] = 'low';
            $aiOptions['temperature'] = 0;

            $result = $this->aiFacade->chat($messages, $userId, $aiOptions);
            $content = (string) ($result['content'] ?? '');
            $parsed = $this->parseJsonFromAiResponse($content);
            if (!is_array($parsed)) {
                $this->logger->warning('Synaform: clustering AI returned unparseable response', [
                    'model' => $result['model'] ?? 'unknown',
                    'response_chars' => strlen($content),
                    'raw_preview' => mb_substr($content, 0, 400),
                ]);

                return [];
            }

            $rawGroups = $parsed['groups'] ?? null;
            if (!is_array($rawGroups)) {
                // Some models drop the wrapper and return the array
                // directly. Accept that shape too.
                $rawGroups = (array_is_list($parsed) ? $parsed : null);
            }
            if (!is_array($rawGroups) || empty($rawGroups)) {
                $this->logger->warning('Synaform: clustering AI returned no groups', [
                    'model' => $result['model'] ?? 'unknown',
                    'parsed_keys' => is_array($parsed) ? array_keys($parsed) : null,
                    'raw_preview' => mb_substr($content, 0, 400),
                ]);

                return [];
            }

            $allowedKeys = array_values(array_filter(
                array_map(static fn ($f) => (string) ($f['key'] ?? ''), $fields),
                static fn ($k) => '' !== $k
            ));
            $seen = [];
            $clean = [];
            foreach ($rawGroups as $g) {
                if (!is_array($g)) {
                    continue;
                }
                $gkey = (string) ($g['key'] ?? '');
                $glabel = (string) ($g['label'] ?? $gkey);
                $gfieldKeys = array_values(array_filter(
                    array_map('strval', (array) ($g['field_keys'] ?? [])),
                    static fn ($k) => '' !== $k
                ));
                $gfieldKeys = array_values(array_intersect($gfieldKeys, $allowedKeys));
                $gfieldKeys = array_values(array_filter(
                    $gfieldKeys,
                    static function ($k) use (&$seen) {
                        if (isset($seen[$k])) {
                            return false;
                        }
                        $seen[$k] = true;

                        return true;
                    }
                ));
                if (empty($gfieldKeys)) {
                    continue;
                }
                if ('' === $gkey) {
                    $gkey = 'group_'.(count($clean) + 1);
                }
                $clean[] = [
                    'key' => $gkey,
                    'label' => '' !== $glabel ? $glabel : $gkey,
                    'field_keys' => $gfieldKeys,
                ];
            }

            // If the AI dropped any keys, sweep the remainder into a
            // catch-all group so they still get extracted. Better
            // than silently losing fields.
            $missing = array_values(array_diff($allowedKeys, array_keys($seen)));
            if (!empty($missing)) {
                $clean[] = [
                    'key' => 'other',
                    'label' => 'Other fields',
                    'field_keys' => $missing,
                ];
            }

            return $clean;
        } catch (\Throwable $e) {
            $this->logger->warning('Synaform: askAiToClusterFields failed', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    #[Route('/candidates/{candidateId}/variables', name: 'candidates_variables_get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/synaform/candidates/{candidateId}/variables',
        summary: 'Get resolved variables for an entry',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'Resolved variables')]
    public function candidatesVariablesGet(int $userId, string $candidateId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $entry = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);
        if (!$entry) {
            return $this->json(['success' => false, 'error' => 'Entry not found'], Response::HTTP_NOT_FOUND);
        }

        $form = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_FORM, $entry['form_id'] ?? 'default');
        $formFields = $form['fields'] ?? [];
        $resolved = $this->resolveVariables($entry, $formFields);

        $tableFieldMeta = $this->getTableFieldMeta($formFields);

        return $this->json([
            'success' => true,
            'variables' => $resolved['variables'],
            'table_fields' => $tableFieldMeta,
            'sources' => $this->getVariableSources($formFields),
        ]);
    }

    #[Route('/candidates/{candidateId}/variables', name: 'candidates_variables_update', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/user/{userId}/plugins/synaform/candidates/{candidateId}/variables',
        summary: 'Update variable overrides for an entry',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'Updated variables')]
    public function candidatesVariablesUpdate(int $userId, string $candidateId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $entry = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);
        if (!$entry) {
            return $this->json(['success' => false, 'error' => 'Entry not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $entry['variable_overrides'] = $data['overrides'] ?? $data;
        $entry['updated_at'] = date('c');
        $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId, $entry);

        $form = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_FORM, $entry['form_id'] ?? 'default');
        $formFields = $form['fields'] ?? [];
        $resolved = $this->resolveVariables($entry, $formFields);

        return $this->json([
            'success' => true,
            'variables' => $resolved['variables'],
            'table_fields' => $this->getTableFieldMeta($formFields),
        ]);
    }

    // =========================================================================
    // Document Generation
    // =========================================================================

    #[Route('/candidates/{candidateId}/generate/{templateId}', name: 'candidates_generate', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/synaform/candidates/{candidateId}/generate/{templateId}',
        summary: 'Generate a DOCX document from template and resolved variables. Optional JSON body {"instruction": "..."} runs an AI transform over the resolved values first (e.g. "translate everything into Italian").',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'Generated document metadata')]
    public function candidatesGenerate(int $userId, string $candidateId, string $templateId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        // Optional free-text generation instruction ("Mach das auf
        // Italienisch", "Formatiere die Zahlen englisch", …). Applied as an
        // AI transform over the resolved variables just before the template
        // is filled — the stored dataset values are NOT modified.
        $instruction = '';
        $body = json_decode($request->getContent(), true);
        if (is_array($body) && is_string($body['instruction'] ?? null)) {
            $instruction = mb_substr(trim($body['instruction']), 0, 1000);
        }

        $entry = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);
        if (!$entry) {
            return $this->json(['success' => false, 'error' => 'Entry not found'], Response::HTTP_NOT_FOUND);
        }

        $template = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_TEMPLATE, $templateId);
        if (!$template) {
            return $this->json(['success' => false, 'error' => 'Template not found'], Response::HTTP_NOT_FOUND);
        }

        $templatePath = $this->uploadDir.'/'.$userId.'/synaform/templates/'.$templateId.'/template.docx';
        if (!is_file($templatePath)) {
            return $this->json(['success' => false, 'error' => 'Template file not found on disk'], Response::HTTP_NOT_FOUND);
        }

        try {
            $form = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_FORM, $entry['form_id'] ?? 'default');
            $formFields = $form['fields'] ?? [];
            $resolved = $this->resolveVariables($entry, $formFields);
            $variables = $resolved['variables'];

            // Image-typed variables are handled separately by processImages(); strip
            // their meta dicts from the generic $variables map so the
            // placeholder classifier doesn't see them as lists (the meta dict is
            // an associative array which would otherwise get treated as a list
            // value and cloned into multiple paragraphs by expandListParagraphs).
            $imageKeys = [];
            foreach ($formFields as $field) {
                if (($field['type'] ?? '') === 'image' && !empty($field['key'])) {
                    $imageKeys[(string) $field['key']] = true;
                    unset($variables[(string) $field['key']]);
                }
            }

            // Checkbox-typed fields: if the template uses the *plain*
            // `{{moving}}` form (not the `{{checkb.moving.yes/no}}` pair),
            // `processScalars` would cast PHP bool → "1" / "" which looks
            // like garbage ("Umzugsbereitschaft 11" when both are true).
            // Normalize bool values to "Ja" / "Nein" — or whatever the
            // designer configured (`yes_label` / `no_label`) — BEFORE the
            // pipeline runs, so the plain form renders as readable text and
            // the paired `checkb.*.yes/no` form still goes through
            // processCheckboxes() and gets its ☒ / ☐ glyphs.
            foreach ($formFields as $field) {
                if (($field['type'] ?? '') !== 'checkbox' || empty($field['key'])) {
                    continue;
                }
                $k = (string) $field['key'];
                if (!array_key_exists($k, $variables)) {
                    continue;
                }
                $v = $variables[$k];
                if (!is_bool($v)) {
                    // Accept "true"/"false"/"1"/"0"/"ja"/"nein"/"yes"/"no" strings.
                    if (is_string($v)) {
                        $vNorm = strtolower(trim($v));
                        if (in_array($vNorm, ['1', 'true', 'yes', 'ja', 'y', 'on'], true)) {
                            $v = true;
                        } elseif (in_array($vNorm, ['0', 'false', 'no', 'nein', 'n', 'off', ''], true)) {
                            $v = false;
                        } else {
                            continue; // leave free text alone
                        }
                    } elseif (is_int($v)) {
                        $v = (bool) $v;
                    } else {
                        continue;
                    }
                }
                $designer = $field['designer'] ?? [];
                $yesLabel = is_string($designer['yes_label'] ?? null) && '' !== $designer['yes_label']
                    ? $designer['yes_label']
                    : 'Ja';
                $noLabel = is_string($designer['no_label'] ?? null) && '' !== $designer['no_label']
                    ? $designer['no_label']
                    : 'Nein';
                $variables[$k] = $v ? $yesLabel : $noLabel;
            }

            $arrays = $this->collectArrayData($entry, $formFields);

            // Optional AI instruction pass: rewrite the resolved values
            // according to the user's free-text command. Throws on failure
            // so the user gets a clear error instead of a silently
            // untransformed document.
            if ('' !== $instruction) {
                [$variables, $arrays] = $this->applyGenerationInstruction($userId, $instruction, $variables, $arrays);
            }

            $designerMap = $this->getDesignerConfigMap($formFields);
            $richSubfields = $this->getRichRowSubfields($formFields);

            $cleanedPath = $this->cleanTemplateMacros($templatePath);

            // Phase T pre-pass: for any `table`-typed variable with declared
            // columns, expand a single `{{varname}}` placeholder inside a
            // <w:tbl> row into per-row cells. This lets templates carry just
            // one placeholder instead of N×columns `{{varname.col.N}}` tokens.
            $expandedTableKeys = $this->expandTableBlocks(
                $cleanedPath,
                $formFields,
                $arrays,
                $richSubfields
            );

            // Phase A pre-pass: expand list-type placeholders into proper per-item
            // Word paragraphs (preserving numPr bullet style, indentation, pPr). This
            // must run on the raw DOCX before PhpWord's TemplateProcessor parses it,
            // because TemplateProcessor works per-placeholder and cannot split one
            // paragraph into many.
            $preClassified = $this->classifyTemplatePlaceholders(
                array_column($this->extractPlaceholders($cleanedPath), 'key'),
                $variables,
                $arrays
            );
            $expandedListKeys = $this->expandListParagraphs(
                $cleanedPath,
                $preClassified['lists'],
                $variables,
                $arrays,
                $designerMap
            );

            // Phase C pre-pass: for each row-group whose placeholders do NOT live
            // inside a <w:tr> (paragraph-based templates such as v2_de), clone the
            // contiguous paragraph range once per data row and fill simple sub-fields
            // inline. This is the non-table equivalent of PhpWord's cloneRow.
            // Rich sub-fields (`$richSubfields`, e.g. stations.details and any
            // column declared type=list) are left as {{…#N}} placeholders so
            // Phase B's expandRichRowColumns renderer handles them.
            $preClonedGroups = $this->cloneParagraphGroupsPrepass(
                $cleanedPath,
                $preClassified['rowGroups'] ?? [],
                $arrays,
                $richSubfields
            );

            // PhpWord TemplateProcessor holds `$macroOpeningChars` and
            // `$macroClosingChars` in STATIC properties. Its constructor runs
            // `fixBrokenMacros()` over the whole document.xml using whatever is
            // currently in those statics. On the first generate() in a fresh
            // PHP process that's `'${'` / `'}'` (library defaults) — safe. On
            // every *subsequent* generate() in the same persistent FrankenPHP
            // / PHP-FPM worker the statics are `'{{'` / `'}}'` (left there by
            // our own setMacroOpeningChars() call below), and fixBrokenMacros'
            // regex then greedy-matches drawing-URI GUIDs like
            //   `<a:ext uri="{28A0092B-…}">…<w:t>{{placeholder}`
            // as one span, strip_tags() eats the drawing XML, and the
            // document is left with dangling `</w:t></w:r></w:p>` after the
            // GUID — Word/LibreOffice then refuse to render it.
            //
            // Fix: reset the statics to PhpWord's library defaults *before*
            // constructing the TemplateProcessor, then re-set them to our
            // '{{'/'}}' for our own placeholder syntax after construction.
            (new \ReflectionProperty(TemplateProcessor::class, 'macroOpeningChars'))->setValue(null, '${');
            (new \ReflectionProperty(TemplateProcessor::class, 'macroClosingChars'))->setValue(null, '}');

            $tp = new TemplateProcessor($cleanedPath);
            $tp->setMacroOpeningChars('{{');
            $tp->setMacroClosingChars('}}');

            $templatePlaceholders = $tp->getVariables();
            $classified = $this->classifyTemplatePlaceholders($templatePlaceholders, $variables, $arrays);

            // Any list keys already expanded by the pre-pass are gone from the XML;
            // any that the pre-pass could not cleanly locate (e.g. inline inside a
            // non-list paragraph) fall through to the <w:br/> fallback in processLists.
            $classified['lists'] = array_values(array_diff($classified['lists'], $expandedListKeys));

            // Table-block expansion already consumed these placeholders too. Drop
            // them from every classification bucket so later passes don't try to
            // setValue('stations', '') and wipe the freshly-inserted rows.
            if (!empty($expandedTableKeys)) {
                $tableKeys = array_keys($expandedTableKeys);
                $classified['lists'] = array_values(array_diff($classified['lists'], $tableKeys));
                $classified['scalars'] = array_values(array_diff($classified['scalars'], $tableKeys));
                foreach ($tableKeys as $tk) {
                    unset($classified['rowGroups'][$tk]);
                }
            }

            // Row groups handled by the paragraph-group pre-pass must not go through
            // cloneRow: they are already cloned in the XML and their simple fields
            // are already filled. Any leftover {{…#N}} placeholders for rich
            // sub-fields are handled by Phase B after saveAs().
            foreach (array_keys($preClonedGroups) as $handledGroup) {
                unset($classified['rowGroups'][$handledGroup]);
            }

            $this->processRowGroups($tp, $classified['rowGroups'], $arrays, $designerMap, $richSubfields);
            $this->processBlockGroups($tp, $classified['blockGroups'], $arrays);
            $this->processCheckboxes($tp, $classified['checkboxes'], $variables, $designerMap);
            $this->processLists($tp, $classified['lists'], $variables, $designerMap);
            $floatingImages = $this->processImages($tp, $formFields, $entry);
            $this->processScalars($tp, $classified['scalars'], $variables);

            $docId = 'doc_'.bin2hex(random_bytes(6));
            $genDir = $this->uploadDir.'/'.$userId.'/synaform/candidates/'.$candidateId.'/generated';
            if (!is_dir($genDir)) {
                mkdir($genDir, 0755, true);
            }
            $outputPath = $genDir.'/'.$docId.'.docx';
            $tp->saveAs($outputPath);

            // Phase B post-pass: expand any rich-column placeholders left behind
            // by processRowGroups/expandTableBlocks into real Word paragraphs
            // with proper bullets. Arrays become one bullet per item; legacy
            // multi-line strings are parsed with date-header + bullet heuristics.
            $this->expandRichRowColumns($outputPath, $richSubfields, $arrays, $formFields);

            // Phase D post-pass: apply layout helpers (repeat header / cantSplit)
            // driven either by the original template XML or by per-variable
            // designer config. Runs on the final DOCX so row clones emitted by
            // cloneRow / cloneParagraphGroupsPrepass are also reached.
            $this->applyTableLayoutHelpers($outputPath, $arrays, $designerMap);

            // Phase E post-pass: convert [[SYNCB|…]] markers emitted by
            // processCheckboxes() into real Word content-control checkboxes
            // (`<w:sdt>` with `<w14:checkbox>`). Lets the customer click and
            // toggle the checkboxes in the generated DOCX while still keeping
            // the pre-resolved state visible.
            $this->convertCheckboxMarkersToContentControls($outputPath);

            // Phase F post-pass: replace [[SYNIMG|…]] markers with floating
            // (absolutely positioned, text-wrapping) picture shapes for image
            // variables whose designer config uses placement=float.
            $this->embedFloatingImages($outputPath, $floatingImages);

            if (is_file($cleanedPath)) {
                unlink($cleanedPath);
            }

            $docMeta = [
                'id' => $docId,
                'template_id' => $templateId,
                'template_name' => $template['name'] ?? $templateId,
                'filename' => $docId.'.docx',
                'generated_at' => date('c'),
                'variable_snapshot' => $variables,
            ];
            if ('' !== $instruction) {
                $docMeta['instruction'] = $instruction;
            }

            if (!isset($entry['documents'])) {
                $entry['documents'] = [];
            }
            $entry['documents'][$docId] = $docMeta;
            $entry['status'] = 'generated';
            $entry['updated_at'] = date('c');
            $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId, $entry);

            return $this->json([
                'success' => true,
                'document' => $docMeta,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Document generation failed', [
                'error' => $e->getMessage(),
                'candidateId' => $candidateId,
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Generation failed: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Apply a free-text generation instruction ("translate everything into
     * Italian", "format numbers the English way", "be funny") to the fully
     * resolved variable set via one AI round-trip. Returns the transformed
     * [$variables, $arrays] pair; the stored dataset is never touched.
     *
     * Fails loudly (RuntimeException) when the AI response is unusable —
     * silently generating an untransformed document would look like the
     * instruction was honoured when it wasn't.
     *
     * @param array<string, mixed>                            $variables
     * @param array<string, array<int, array<string, mixed>>> $arrays
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function applyGenerationInstruction(int $userId, string $instruction, array $variables, array $arrays): array
    {
        $payload = json_encode(
            ['variables' => $variables, 'tables' => $arrays],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
        if (false === $payload) {
            throw new \RuntimeException('Could not encode variables for the generation instruction');
        }

        $prompt = $this->getPromptText($userId, 'generation_transform')
            ."\n\nUser instruction:\n".$instruction
            ."\n\nJSON to transform:\n".$payload;

        $messages = [
            ['role' => 'system', 'content' => 'You are a precise JSON transformation assistant. Return only valid JSON.'],
            ['role' => 'user', 'content' => $prompt],
        ];
        $aiOptions = $this->resolveAiModelOptions($userId);
        $aiOptions['max_tokens'] = 16000;
        $aiOptions['thinking_budget'] = 0;
        $aiOptions['reasoning_effort'] = 'low';
        $aiOptions['temperature'] = 0;

        $started = microtime(true);
        $result = $this->aiFacade->chat($messages, $userId, $aiOptions);
        $parsed = $this->parseJsonFromAiResponse((string) ($result['content'] ?? ''));
        if (!is_array($parsed) || array_is_list($parsed)) {
            throw new \RuntimeException('The AI could not apply the instruction — please rephrase it or try again');
        }

        $newVars = is_array($parsed['variables'] ?? null) ? $parsed['variables'] : [];
        $newTables = is_array($parsed['tables'] ?? null) ? $parsed['tables'] : [];

        // Merge conservatively: only keys that already exist may change, and
        // value kinds must stay compatible (bool stays bool, arrays stay
        // arrays). Anything the AI dropped keeps its original value.
        foreach ($variables as $k => $orig) {
            if (!array_key_exists($k, $newVars)) {
                continue;
            }
            $nv = $newVars[$k];
            if (is_bool($orig)) {
                if (is_bool($nv)) {
                    $variables[$k] = $nv;
                }
            } elseif (is_array($orig)) {
                if (is_array($nv)) {
                    $variables[$k] = $nv;
                }
            } elseif (null === $nv || is_scalar($nv)) {
                $variables[$k] = $nv;
            }
        }
        foreach ($arrays as $k => $rows) {
            if (isset($newTables[$k]) && is_array($newTables[$k])) {
                $arrays[$k] = array_values($newTables[$k]);
            }
        }

        $this->logger->info('Synaform: generation instruction applied', [
            'instruction' => $instruction,
            'model' => (string) ($result['model'] ?? 'unknown'),
            'elapsed_ms' => (int) ((microtime(true) - $started) * 1000),
        ]);

        return [$variables, $arrays];
    }

    #[Route('/candidates/{candidateId}/documents', name: 'candidates_documents_list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/synaform/candidates/{candidateId}/documents',
        summary: 'List generated documents for an entry',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'List of generated documents')]
    public function candidatesDocumentsList(int $userId, string $candidateId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $entry = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);
        if (!$entry) {
            return $this->json(['success' => false, 'error' => 'Entry not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'success' => true,
            'documents' => array_values($entry['documents'] ?? []),
        ]);
    }

    #[Route('/candidates/{candidateId}/documents/{documentId}/download', name: 'candidates_documents_download', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/synaform/candidates/{candidateId}/documents/{documentId}/download',
        summary: 'Download a generated document',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'Generated DOCX file')]
    public function candidatesDocumentDownload(int $userId, string $candidateId, string $documentId, #[CurrentUser] ?User $user): Response
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $entry = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);
        if (!$entry) {
            return $this->json(['success' => false, 'error' => 'Entry not found'], Response::HTTP_NOT_FOUND);
        }

        $docMeta = $entry['documents'][$documentId] ?? null;
        if (!$docMeta) {
            return $this->json(['success' => false, 'error' => 'Document not found'], Response::HTTP_NOT_FOUND);
        }

        $filePath = $this->uploadDir.'/'.$userId.'/synaform/candidates/'.$candidateId.'/generated/'.$docMeta['filename'];
        if (!is_file($filePath)) {
            return $this->json(['success' => false, 'error' => 'Document file not found on disk'], Response::HTTP_NOT_FOUND);
        }

        $downloadName = ($entry['name'] ?? 'document').'_'.($docMeta['template_name'] ?? 'template').'.docx';
        $downloadName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $downloadName);
        $response = new BinaryFileResponse($filePath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $downloadName);

        return $response;
    }

    #[Route('/candidates/{candidateId}/documents/{documentId}/pdf', name: 'candidates_documents_pdf', methods: ['GET'], priority: 10)]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/synaform/candidates/{candidateId}/documents/{documentId}/pdf',
        summary: 'Stream a PDF rendering of a generated document (true-preview path). Requires libreoffice on the backend host; returns 501 otherwise.',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'PDF file')]
    #[OA\Response(response: 501, description: 'LibreOffice not installed on the backend')]
    public function candidatesDocumentPdf(int $userId, string $candidateId, string $documentId, #[CurrentUser] ?User $user): Response
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $entry = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);
        if (!$entry) {
            return $this->json(['success' => false, 'error' => 'Entry not found'], Response::HTTP_NOT_FOUND);
        }

        $docMeta = $entry['documents'][$documentId] ?? null;
        if (!$docMeta) {
            return $this->json(['success' => false, 'error' => 'Document not found'], Response::HTTP_NOT_FOUND);
        }

        $docxPath = $this->uploadDir.'/'.$userId.'/synaform/candidates/'.$candidateId.'/generated/'.$docMeta['filename'];
        if (!is_file($docxPath)) {
            return $this->json(['success' => false, 'error' => 'Document file not found on disk'], Response::HTTP_NOT_FOUND);
        }

        $pdfPath = $this->convertDocxToPdf($docxPath);
        if (null === $pdfPath) {
            return $this->json([
                'success' => false,
                'error' => 'libreoffice is not installed on the backend. True-preview PDF is unavailable; the HTML live preview still works. Install libreoffice in the backend image or run a gotenberg sidecar to enable this feature.',
            ], Response::HTTP_NOT_IMPLEMENTED);
        }

        return new BinaryFileResponse($pdfPath, 200, [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, max-age=60',
        ]);
    }

    #[Route('/candidates/{candidateId}/documents/{documentId}', name: 'candidates_documents_delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/user/{userId}/plugins/synaform/candidates/{candidateId}/documents/{documentId}',
        summary: 'Delete a generated document',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'Document deleted')]
    public function candidatesDocumentDelete(int $userId, string $candidateId, string $documentId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $entry = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);
        if (!$entry) {
            return $this->json(['success' => false, 'error' => 'Entry not found'], Response::HTTP_NOT_FOUND);
        }

        $docMeta = $entry['documents'][$documentId] ?? null;
        if (!$docMeta) {
            return $this->json(['success' => false, 'error' => 'Document not found'], Response::HTTP_NOT_FOUND);
        }

        $filePath = $this->uploadDir.'/'.$userId.'/synaform/candidates/'.$candidateId.'/generated/'.$docMeta['filename'];
        if (is_file($filePath)) {
            unlink($filePath);
        }

        unset($entry['documents'][$documentId]);
        $entry['updated_at'] = date('c');
        $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId, $entry);

        return $this->json(['success' => true, 'message' => 'Document deleted']);
    }

    // =========================================================================
    // Image variables (candidate photos, logos, signatures)
    // =========================================================================

    #[Route('/candidates/{candidateId}/image/{key}', name: 'candidates_image_upload', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/synaform/candidates/{candidateId}/image/{key}',
        summary: 'Upload an image for an image-typed variable (stored per candidate, embedded at generation time)',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'Image stored')]
    public function candidatesImageUpload(int $userId, string $candidateId, string $key, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
            return $this->json(['success' => false, 'error' => 'Invalid variable key'], Response::HTTP_BAD_REQUEST);
        }

        $entry = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);
        if (!$entry) {
            return $this->json(['success' => false, 'error' => 'Entry not found'], Response::HTTP_NOT_FOUND);
        }

        $accepted = $this->acceptUploadedFile($request, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif'], 8 * 1024 * 1024);
        if (!$accepted['ok']) {
            return $this->json(['success' => false, 'error' => $accepted['error']], $accepted['status']);
        }
        $file = $accepted['file'];

        $mime = $file->getMimeType() ?? 'application/octet-stream';
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/heic', 'image/heif'];
        if (!in_array($mime, $allowedMimes, true) && !str_starts_with($mime, 'image/')) {
            return $this->json(['success' => false, 'error' => 'Unsupported image format: '.$mime], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $ext = $this->mimeToExtension($mime);
        $dir = $this->uploadDir.'/'.$userId.'/synaform/candidates/'.$candidateId.'/images';
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        // Wipe any previous file for this key (different extension possible).
        foreach (glob($dir.'/'.$key.'.*') ?: [] as $old) {
            @unlink($old);
        }

        $filename = $key.'.'.$ext;
        $file->move($dir, $filename);
        $this->finalizeUploadedFile($dir.'/'.$filename);

        $storedPath = $dir.'/'.$filename;
        $meta = [
            'path' => $storedPath,
            'mime' => $mime,
            'original_name' => $file->getClientOriginalName(),
            'size' => filesize($storedPath) ?: 0,
            'uploaded_at' => date('c'),
        ];

        if (!isset($entry['field_values']) || !is_array($entry['field_values'])) {
            $entry['field_values'] = [];
        }
        $entry['field_values'][$key] = $meta;
        $entry['updated_at'] = date('c');
        $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId, $entry);

        return $this->json([
            'success' => true,
            'key' => $key,
            'meta' => $this->publicImageMeta($meta),
        ]);
    }

    #[Route('/candidates/{candidateId}/image/{key}', name: 'candidates_image_get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/synaform/candidates/{candidateId}/image/{key}',
        summary: 'Stream a stored image variable (used by the UI thumbnail and the HTML live preview)',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'Image file')]
    public function candidatesImageGet(int $userId, string $candidateId, string $key, #[CurrentUser] ?User $user): Response
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
            return $this->json(['success' => false, 'error' => 'Invalid variable key'], Response::HTTP_BAD_REQUEST);
        }
        $entry = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);
        if (!$entry) {
            return $this->json(['success' => false, 'error' => 'Entry not found'], Response::HTTP_NOT_FOUND);
        }
        $meta = $entry['field_values'][$key] ?? null;
        if (!is_array($meta) || empty($meta['path']) || !is_file($meta['path'])) {
            return $this->json(['success' => false, 'error' => 'Image not found'], Response::HTTP_NOT_FOUND);
        }

        return new BinaryFileResponse($meta['path'], 200, [
            'Content-Type' => $meta['mime'] ?? 'application/octet-stream',
            'Cache-Control' => 'private, max-age=60',
        ]);
    }

    #[Route('/candidates/{candidateId}/image/{key}', name: 'candidates_image_delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/user/{userId}/plugins/synaform/candidates/{candidateId}/image/{key}',
        summary: 'Remove a stored image variable',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'Image removed')]
    public function candidatesImageDelete(int $userId, string $candidateId, string $key, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
            return $this->json(['success' => false, 'error' => 'Invalid variable key'], Response::HTTP_BAD_REQUEST);
        }
        $entry = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);
        if (!$entry) {
            return $this->json(['success' => false, 'error' => 'Entry not found'], Response::HTTP_NOT_FOUND);
        }
        $meta = $entry['field_values'][$key] ?? null;
        if (is_array($meta) && !empty($meta['path']) && is_file($meta['path'])) {
            @unlink($meta['path']);
        }
        if (isset($entry['field_values'][$key])) {
            unset($entry['field_values'][$key]);
            $entry['updated_at'] = date('c');
            $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId, $entry);
        }

        return $this->json(['success' => true]);
    }

    /**
     * @return array{mime: ?string, original_name: ?string, size: int, uploaded_at: ?string}
     */
    private function publicImageMeta(array $meta): array
    {
        return [
            'mime' => $meta['mime'] ?? null,
            'original_name' => $meta['original_name'] ?? null,
            'size' => (int) ($meta['size'] ?? 0),
            'uploaded_at' => $meta['uploaded_at'] ?? null,
        ];
    }

    private function mimeToExtension(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/bmp' => 'bmp',
            'image/heic', 'image/heif' => 'heic',
            default => 'bin',
        };
    }

    /**
     * Auto-fill empty image-typed variables with a portrait photo embedded
     * in one of the uploaded PDF source documents — the classic "profile
     * photo in the CV" case. Only fields WITHOUT a stored image are touched,
     * so a manual upload always wins and a re-run only fills what is still
     * empty. Returns true when the candidate entry was modified.
     *
     * @param array<int, array<string, mixed>> $fields the form's field definitions
     */
    private function autoExtractProfileImages(int $userId, string $candidateId, array &$entry, array $fields): bool
    {
        $emptyImageFields = [];
        foreach ($fields as $field) {
            if (($field['type'] ?? '') !== 'image' || empty($field['key'])) {
                continue;
            }
            $meta = $entry['field_values'][(string) $field['key']] ?? null;
            if (is_array($meta) && !empty($meta['path']) && is_file($meta['path'])) {
                continue;
            }
            $emptyImageFields[] = $field;
        }
        if ([] === $emptyImageFields) {
            return false;
        }

        $candidateDir = $this->uploadDir.'/'.$userId.'/synaform/candidates/'.$candidateId;
        // Collect every source file we can pull an embedded portrait from:
        // PDFs (via pdfimages) and Office .docx (via word/media/*). The CV is
        // tried first, then any additional documents.
        $sourceFiles = [];
        $cvStored = $entry['files']['cv']['stored_as'] ?? null;
        if (is_string($cvStored) && $this->waitForUploadFile($candidateDir.'/'.$cvStored)) {
            $sourceFiles[] = $candidateDir.'/'.$cvStored;
        }
        foreach ($entry['files']['additional'] ?? [] as $doc) {
            $storedAs = $doc['stored_as'] ?? null;
            if (is_string($storedAs) && $this->waitForUploadFile($candidateDir.'/'.$storedAs)) {
                $sourceFiles[] = $candidateDir.'/'.$storedAs;
            }
        }
        if ([] === $sourceFiles) {
            return false;
        }

        $photoPath = null;
        $sourceFile = null;
        foreach ($sourceFiles as $src) {
            $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
            if ('pdf' === $ext) {
                $photoPath = $this->findProfilePhotoInPdf($src);
            } elseif ('docx' === $ext) {
                $photoPath = $this->findProfilePhotoInDocx($src);
            } else {
                continue;
            }
            if (null !== $photoPath) {
                $sourceFile = $src;
                break;
            }
        }
        if (null === $photoPath) {
            return false;
        }

        // Prefer the field whose key/label sounds like a photo; otherwise
        // the first empty image field gets the portrait.
        $target = null;
        foreach ($emptyImageFields as $field) {
            $haystack = strtolower(($field['key'] ?? '').' '.($field['label'] ?? ''));
            if (1 === preg_match('/photo|foto|portrait|portr\x{00e4}t|bild|picture|profil|headshot/u', $haystack)) {
                $target = $field;
                break;
            }
        }
        $target ??= $emptyImageFields[0];
        $key = (string) $target['key'];

        $dir = $candidateDir.'/images';
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
        foreach (glob($dir.'/'.$key.'.*') ?: [] as $old) {
            @unlink($old);
        }
        $storedPath = $dir.'/'.$key.'.png';
        if (!@rename($photoPath, $storedPath)) {
            if (!@copy($photoPath, $storedPath)) {
                @unlink($photoPath);

                return false;
            }
            @unlink($photoPath);
        }

        if (!isset($entry['field_values']) || !is_array($entry['field_values'])) {
            $entry['field_values'] = [];
        }
        $entry['field_values'][$key] = [
            'path' => $storedPath,
            'mime' => 'image/png',
            'original_name' => basename((string) $sourceFile).' (auto-extracted)',
            'size' => filesize($storedPath) ?: 0,
            'uploaded_at' => date('c'),
            'auto_extracted' => true,
        ];
        $this->logger->info('Synaform: auto-extracted profile photo from CV', [
            'candidateId' => $candidateId,
            'field' => $key,
            'source' => basename((string) $sourceFile),
        ]);

        return true;
    }

    /**
     * Locate the most likely profile photo embedded in a PDF using Poppler's
     * pdfimages. Heuristic: a real `image` object on one of the first two
     * pages, at least 100x100 px, portrait-to-square aspect ratio (width /
     * height between 0.5 and 1.15). CV layouts embed the portrait as its own
     * image object; full-page scans and decorative banners fall outside the
     * ratio/size window. Returns the path of an extracted PNG in a temp
     * location (caller moves/deletes it), or null when nothing matches or
     * pdfimages is unavailable.
     */
    private function findProfilePhotoInPdf(string $pdfPath): ?string
    {
        $bin = trim((string) @shell_exec('command -v pdfimages 2>/dev/null'));
        if ('' === $bin) {
            return null;
        }

        $lines = [];
        exec(sprintf('%s -list %s 2>/dev/null', escapeshellarg($bin), escapeshellarg($pdfPath)), $lines, $code);
        if (0 !== $code) {
            return null;
        }

        $best = null;
        foreach ($lines as $line) {
            if (!preg_match('/^\s*(\d+)\s+(\d+)\s+(\S+)\s+(\d+)\s+(\d+)/', $line, $m)) {
                continue;
            }
            $page = (int) $m[1];
            $type = $m[3];
            $w = (int) $m[4];
            $h = (int) $m[5];
            if ('image' !== $type || $page > 2) {
                continue;
            }
            if ($w < 100 || $h < 100 || $w > 2500 || $h > 2500) {
                continue;
            }
            $ratio = $w / $h;
            if ($ratio < 0.5 || $ratio > 1.15) {
                continue;
            }
            if (null === $best || $w * $h > $best['w'] * $best['h']) {
                $best = ['page' => $page, 'w' => $w, 'h' => $h];
            }
        }
        if (null === $best) {
            return null;
        }

        $tmpDir = sys_get_temp_dir().'/synaform-img-'.bin2hex(random_bytes(4));
        if (!@mkdir($tmpDir, 0o700, true)) {
            return null;
        }
        exec(sprintf(
            '%s -png -f %d -l %d %s %s 2>/dev/null',
            escapeshellarg($bin),
            $best['page'],
            $best['page'],
            escapeshellarg($pdfPath),
            escapeshellarg($tmpDir.'/img')
        ), $out, $code);

        $match = null;
        foreach (glob($tmpDir.'/img-*.png') ?: [] as $png) {
            $size = @getimagesize($png);
            if (is_array($size) && (int) $size[0] === $best['w'] && (int) $size[1] === $best['h']) {
                $match = $png;
                break;
            }
        }
        if (null === $match) {
            $this->removeDirectory($tmpDir);

            return null;
        }

        // Move the hit out of the temp dir so the cleanup below can't kill it.
        $finalTmp = sys_get_temp_dir().'/synaform-photo-'.bin2hex(random_bytes(4)).'.png';
        if (!@rename($match, $finalTmp)) {
            $this->removeDirectory($tmpDir);

            return null;
        }
        $this->removeDirectory($tmpDir);

        return $finalTmp;
    }

    /**
     * Find a candidate portrait photo embedded in a .docx CV by scanning
     * `word/media/*`. Picks the largest portrait-ratio image (same heuristic
     * as the PDF path) and normalises it to a PNG temp file so the caller's
     * stored `image/png` mime stays consistent.
     *
     * Returns the temp PNG path or null when no suitable image is found.
     */
    private function findProfilePhotoInDocx(string $docxPath): ?string
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($docxPath)) {
            return null;
        }

        $best = null;
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            if (!is_string($name) || 1 !== preg_match('#^word/media/.+\.(png|jpe?g)$#i', $name)) {
                continue;
            }
            $bytes = $zip->getFromIndex($i);
            if (false === $bytes || '' === $bytes) {
                continue;
            }
            $info = @getimagesizefromstring($bytes);
            if (!is_array($info)) {
                continue;
            }
            $w = (int) $info[0];
            $h = (int) $info[1];
            if ($w < 100 || $h < 100 || $w > 3000 || $h > 3000) {
                continue;
            }
            $ratio = $h > 0 ? $w / $h : 0;
            // Portrait-ish only — filters out landscape logos/banners.
            if ($ratio < 0.5 || $ratio > 1.15) {
                continue;
            }
            if (null === $best || $w * $h > $best['w'] * $best['h']) {
                $best = ['bytes' => $bytes, 'w' => $w, 'h' => $h];
            }
        }
        $zip->close();

        if (null === $best) {
            return null;
        }

        $finalTmp = sys_get_temp_dir().'/synaform-photo-'.bin2hex(random_bytes(4)).'.png';

        // Normalise to PNG so the stored file and its 'image/png' mime match,
        // even when the embedded image was a JPEG.
        if (function_exists('imagecreatefromstring')) {
            $img = @imagecreatefromstring($best['bytes']);
            if (false !== $img) {
                $ok = @imagepng($img, $finalTmp);
                imagedestroy($img);
                if ($ok) {
                    return $finalTmp;
                }
            }
        }

        // Fallback: write the raw bytes if GD is unavailable.
        if (false !== @file_put_contents($finalTmp, $best['bytes'])) {
            return $finalTmp;
        }

        return null;
    }

    // =========================================================================
    // Assets (frontend)
    // =========================================================================

    #[Route('/assets/{path}', name: 'assets', methods: ['GET'], requirements: ['path' => '.+'])]
    public function assets(string $path): Response
    {
        $pluginDir = dirname(__DIR__, 2);
        $file = $pluginDir.'/frontend/'.$path;

        if (!is_file($file) || !str_starts_with(realpath($file), realpath($pluginDir.'/frontend'))) {
            return new Response('Not found', Response::HTTP_NOT_FOUND);
        }

        $ext = pathinfo($file, PATHINFO_EXTENSION);
        $mimeTypes = [
            'js' => 'application/javascript',
            'css' => 'text/css',
            'html' => 'text/html',
            'json' => 'application/json',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
        ];

        return new Response(
            file_get_contents($file),
            Response::HTTP_OK,
            ['Content-Type' => $mimeTypes[$ext] ?? 'application/octet-stream']
        );
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function canAccessPlugin(?User $user, int $userId): bool
    {
        if (null === $user) {
            return false;
        }

        if ($user->getId() !== $userId) {
            return false;
        }

        return '1' === $this->configRepository->getValue($userId, self::CONFIG_GROUP, 'enabled');
    }

    /** @return array<string, string> */
    private function getPluginConfig(int $userId): array
    {
        $defaults = [
            'default_language' => 'de',
            'company_name' => '',
            'extraction_model' => 'default',
            'validation_model' => 'default',
            'default_template_id' => '',
        ];

        $config = [];
        foreach ($defaults as $key => $default) {
            $config[$key] = $this->configRepository->getValue($userId, self::CONFIG_GROUP, $key) ?? $default;
        }

        return $config;
    }

    /** @var array<int, array<string, string>> per-request cache of prompt overrides */
    private array $promptOverridesCache = [];

    /**
     * Per-user prompt overrides (only keys from EDITABLE_PROMPT_KEYS, only
     * non-empty strings). Cached per request — extraction reads these once
     * per AI call.
     *
     * @return array<string, string>
     */
    private function getPromptOverrides(int $userId): array
    {
        if (!isset($this->promptOverridesCache[$userId])) {
            $stored = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_PROMPTS, 'prompts') ?? [];
            $overrides = [];
            foreach (self::EDITABLE_PROMPT_KEYS as $key) {
                if (!empty($stored[$key]) && is_string($stored[$key])) {
                    $overrides[$key] = $stored[$key];
                }
            }
            $this->promptOverridesCache[$userId] = $overrides;
        }

        return $this->promptOverridesCache[$userId];
    }

    /**
     * Resolve a prompt section: the user's override if present, else the
     * shipped default. Supported placeholder tokens ({today}, {month},
     * {year}, {language}) are substituted by the caller via promptTokens().
     */
    private function getPromptText(int $userId, string $key): string
    {
        $overrides = $this->getPromptOverrides($userId);

        return $overrides[$key] ?? $this->defaultPromptText($key);
    }

    /**
     * Token map for prompt sections. `{language}` is the human-readable
     * extraction/output language name (e.g. "German"), the date tokens
     * reflect the server's "now" — the moment the dataset is processed.
     *
     * @return array<string, string>
     */
    private function promptTokens(string $languageName): array
    {
        return [
            '{today}' => date('Y-m-d'),
            '{month}' => date('m'),
            '{year}' => date('Y'),
            '{language}' => $languageName,
        ];
    }

    private function defaultPromptText(string $key): string
    {
        switch ($key) {
            case 'extraction_rules':
                return <<<'TXT'
                For each field, extract the most appropriate value from the documents. Rules:
                - For "select" fields, ONLY return one of the allowed values listed in brackets, or null if not found.
                - For "list" fields, return a JSON array of strings (one entry per item).
                - For "table" fields, return a JSON array of objects where each object has the column keys listed in the field description. Most recent entries first.
                - Table columns with type=list return a JSON array of short strings inside the row (one bullet per achievement/item). Do NOT include dashes, bullets, or numbering characters in the strings; the template generator adds proper bullets automatically. Do NOT embed multiple bullets in a single string separated by newlines.
                - For "text" fields, return a plain string value.
                - For "checkbox" fields, return true or false.
                - For "date" fields, return in YYYY-MM-DD format.
                - Return null for any field where no matching information is found. Do NOT guess or invent data.
                - If a document is an interview transcript, extract answers to questions that match the field descriptions.

                Per-field instructions are BINDING:
                - Every "Instructions:" line under a field in the field list below was written by the form designer and is a mandatory rule, not a suggestion. It may dictate an exact output format (e.g. a phone number pattern like "0049 (0) 175 234 567 89"), a transformation, a default, or something to exclude.
                - ALWAYS apply these instructions to the value you return. A formatting instruction takes precedence over how the value is written in the source documents (reformat the extracted value accordingly).

                Anti-duplication rules (applied to every field):
                - If the source uses "Label: Value" patterns (e.g. "Position: …", "Tätigkeit: …", "Rolle: …", "Aufgaben: …", "Branche: …", "Zeitraum: …", "Firma: …", "Arbeitgeber: …"), return ONLY the value. NEVER include the label itself in the field value.
                - Each fact belongs to exactly ONE field. When two fields could plausibly hold the same value (e.g. a position name appears in both `current_position` and inside a `stations` row), use the field whose description fits best and leave the other shorter / abstract / empty rather than echoing the same string verbatim.
                - For row-table fields like `stations`, the bullet entries (column with type=list) must be ACTIVITY descriptions. NEVER repeat the row's `position`, `employer`, or `time` value as a bullet; those are already in their own columns. In particular, the FIRST bullet must NOT be the position title or start with the position title (even if the source CV's job line "Interim-CTO at Vicoland - 5 team" reads naturally as a bullet — split that line: extract "Interim-CTO" into the `position` column, "Vicoland" into `employer`, "5 team" or any concrete metric into a separate bullet, and drop everything that's redundant). Each bullet stands on its own as a verb-led achievement or responsibility, e.g. "Aufbau einer cloudbasierten Sicherheits-Lösung", not "CTO bei Vicoland — Aufbau einer Sicherheits-Lösung".
                - For `stations` specifically: create ONE row per POSITION / PERIOD, NOT per employer. If one employer had several roles or sub-periods over time, output a SEPARATE row for EACH (repeat the `employer`; set `time`, `position` and `details` for that role only). Never merge an employer's multiple roles into one row or pack several periods into a single row.
                - `stations` is the full chronological Werdegang. INCLUDE, besides regular jobs: completed vocational training (Berufsausbildung / Lehre) AND professionally relevant internships (substantial, field-related Praktika) as their own rows — set `employer` to the training company / institution, `position` to e.g. "Ausbildung zur/zum …" or "Praktikum – …", and fill `time` and `details` for that period. EXCLUDE only general schooling (Schule, Abitur / Fachabitur), academic degree programmes (Studium / university degrees) and short school internships (Schülerpraktikum) — those stay in the `education` field. A Berufsausbildung or relevant internship MAY appear in BOTH `education` (as a summary) and `stations` (as a chronological entry); this is the only allowed cross-field duplication.
                - Blend the CV with any certificates/references (Arbeitszeugnisse, Zeugnisse, "Certificate/Zeugnis"-labelled documents) when filling the `details` bullets of a `stations` row: match the certificate to the same employer / period, then combine the CV's factual activities with the responsibilities, tasks and achievements described in the Zeugnis for that role. Prefer the CV for hard facts (titles, dates, employer names) and the certificate for richer wording of duties/achievements. Produce a MIXTURE of both sources — do NOT ignore the certificates and do NOT copy an entire Zeugnis sentence verbatim; distil each into a concise, verb-led bullet. Never invent responsibilities that appear in neither source.
                - An education-style field (e.g. `education`, "Ausbildung/Studium") must contain EVERY education entry found in the documents — schooling (Schule), university / degree programmes (Studium), vocational training, professional certifications. Return one entry per line (separate entries with a newline character, most recent first), each with its period and institution when the documents provide them. NEVER silently drop an education entry — completeness matters more than brevity here. This applies NO MATTER WHERE the entry appears in the documents: a school or degree mentioned only briefly, deep in a document, on a later page, or inside a certificate MUST still be captured. In particular, Studium (university) and Schule (school) belong in this field even when they are not part of the main career section.
                - For the `time` column of every `stations` row use the format MM/YYYY – MM/YYYY (two-digit month, four-digit year, an en dash "–" with one space on each side). Use MM/YYYY – heute for an ongoing role. If only the year is known, YYYY – YYYY is acceptable; keep the format consistent across all rows.
                - OPTIONAL grouping columns — populate ONLY when the `stations` table declares them: set `time_total` on an employer's FIRST row to that employer's overall span when they had several consecutive roles (e.g. "01/2014 – 03/2024"); set `sub_of_previous` to true for a role whose period lies inside another role's period (e.g. a side job during a degree). Never invent them — leave empty/false when unknown.
                - If a `current_position` field exists, fill it with the candidate's present role as "Job title – Employer, Location" — include the current employer and its city/location when the documents provide them, not just the bare job title.
                TXT;

            case 'extraction_context':
                return <<<'TXT'
                Processing context and default values:
                - Today's date is {today} (month {month} of year {year}). The dataset is being recorded right now.
                - If a field asks for the date, month, or year OF THE RECORDING itself (e.g. "Monat der Erfassung", "generated_month", "generated_year", "Erstellungsdatum", "date of entry"), return the value derived from today's date — NOT a date found in the documents. Write month names as words in {language} (e.g. month 07 → "Juli" in German).
                - If a field asks for the working / output language and the documents don't state one, default to {language}.
                - Apply the same reasoning to other processing-context defaults the field description implies (e.g. a fixed office location named in the field's instructions). Content fields always win from the documents; only recording-context fields use these defaults.
                TXT;

            case 'generation_transform':
                return <<<'TXT'
                You are post-processing the final variable values of a dataset just before a Word document is generated from them.
                You receive (1) a free-text instruction from the user and (2) a JSON object with all variable values ("variables" for single values, "tables" for row arrays).
                Apply the instruction to the values — e.g. translate them into another language, reformat numbers or dates, adjust the tone — while obeying these rules:
                - Return the SAME JSON structure: identical keys, identical nesting, identical array lengths (unless the instruction explicitly requires otherwise).
                - Do not invent new facts and do not drop values. Only transform what is there.
                - Proper nouns, e-mail addresses, phone numbers and URLs stay verbatim unless the instruction says otherwise.
                - Booleans stay booleans, null stays null, arrays stay arrays.
                - Return ONLY the transformed JSON object — no prose, no explanations, no markdown fences.
                TXT;
        }

        return '';
    }

    /**
     * Extract {{...}} placeholders from a DOCX file.
     *
     * Word often splits placeholder text across multiple XML runs (<w:r>),
     * so we concatenate all run text within each paragraph before matching.
     *
     * @return list<array{key: string, type: string}>
     */
    private function extractPlaceholders(string $docxPath): array
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($docxPath)) {
            $this->logger->warning('Failed to open DOCX for placeholder extraction', ['path' => $docxPath]);

            return [];
        }

        // Walk every part that can carry placeholder text — main body, every
        // header part, every footer part — so a `{{fullname}}` that lives in
        // a Word header is detected by "Detect placeholders" and surfaces in
        // the Variables tab, the same way body placeholders do.
        $found = [];
        foreach ($this->collectDocumentPartNames($zip) as $partName) {
            $xml = $zip->getFromName($partName);
            if (false === $xml) {
                continue;
            }

            // Strip namespace prefixes from tags so DOMDocument can find elements by local name
            $xml = preg_replace('/<(\/?)(?:w|mc|r|wp|wps|a|v|o):/', '<$1', $xml);
            $xml = preg_replace('/\s+xmlns[^=]*="[^"]*"/', '', $xml);

            $doc = new \DOMDocument();
            @$doc->loadXML($xml);

            $paragraphs = $doc->getElementsByTagName('p');
            foreach ($paragraphs as $paragraph) {
                $text = '';
                $runs = $paragraph->getElementsByTagName('r');
                foreach ($runs as $run) {
                    $tNodes = $run->getElementsByTagName('t');
                    foreach ($tNodes as $t) {
                        $text .= $t->textContent;
                    }
                }

                if (preg_match_all('/\{\{([^}]+)\}\}/', $text, $matches)) {
                    foreach ($matches[1] as $key) {
                        $key = trim($key);
                        $found[$key] = true;
                    }
                }
            }
        }
        $zip->close();

        $placeholders = [];
        foreach (array_keys($found) as $key) {
            $placeholders[] = [
                'key' => $key,
                'type' => $this->classifyPlaceholder($key),
            ];
        }

        return $placeholders;
    }

    private function classifyPlaceholder(string $key): string
    {
        if (str_starts_with($key, '#') || str_starts_with($key, '/')) {
            return 'block_marker';
        }
        if (str_contains($key, '.') && !str_starts_with($key, 'checkb') && !str_starts_with($key, 'optional.')) {
            return 'row_field';
        }
        if (str_starts_with($key, 'checkb.')) {
            return 'checkbox';
        }
        if (str_ends_with($key, 'list')) {
            return 'list';
        }

        return 'text';
    }

    /**
     * Static template audit. Runs at upload time and on demand. Surfaces
     * structural risks the customer can fix in Word *before* the first
     * generation breaks the layout.
     *
     * Each finding has:
     *   - code     stable identifier ("LIST_INLINE_TEXT", …)
     *   - severity 'error' | 'warning' | 'info'
     *   - message  human-readable explanation
     *   - hint     concrete fix the customer can apply in Word
     *   - context  the placeholder(s) that triggered the finding
     *
     * Severity policy:
     *   - 'error'   the engine WILL produce broken output. Block release.
     *   - 'warning' the engine MAY produce sub-optimal layout. Releasable
     *               but the customer should redesign the template.
     *   - 'info'    the engine compensates automatically (e.g. via the
     *               cleanTemplateMacros pre-pass), but flagging the source
     *               template still helps the customer keep the .docx
     *               clean.
     *
     * @return array{
     *   ok: bool,
     *   summary: array{
     *     errors: int,
     *     warnings: int,
     *     infos: int,
     *     placeholders: int,
     *     paragraphs_with_placeholder: int,
     *     bare_rows_promoted: int
     *   },
     *   findings: list<array{
     *     code: string,
     *     severity: string,
     *     message: string,
     *     hint: string,
     *     context: array<string, mixed>
     *   }>
     * }
     */
    private function lintTemplate(string $docxPath): array
    {
        $findings = [];
        $summary = [
            'errors' => 0,
            'warnings' => 0,
            'infos' => 0,
            'placeholders' => 0,
            'paragraphs_with_placeholder' => 0,
            'bare_rows_promoted' => 0,
        ];

        $zip = new \ZipArchive();
        if (true !== $zip->open($docxPath)) {
            return [
                'ok' => false,
                'summary' => $summary,
                'findings' => [[
                    'code' => 'OPEN_FAILED',
                    'severity' => 'error',
                    'message' => 'Could not open the .docx — the upload may be corrupt.',
                    'hint' => 'Re-save the template in Word and try again.',
                    'context' => [],
                ]],
            ];
        }

        $partNames = $this->collectDocumentPartNames($zip);
        $bodyXml = $zip->getFromName('word/document.xml') ?: '';
        $headerFooterXml = '';
        foreach ($partNames as $name) {
            if ('word/document.xml' === $name) {
                continue;
            }
            $headerFooterXml .= ($zip->getFromName($name) ?: '')."\n";
        }
        $zip->close();

        // Defragment {{...}} so structural matchers see clean placeholder
        // tokens — exactly what cleanTemplateMacros does at generation time.
        $defrag = function (string $xml): string {
            $xml = preg_replace('/\{(<[^>]*>)*\{/', '{{', $xml) ?? $xml;
            $xml = preg_replace('/\}(<[^>]*>)*\}/', '}}', $xml) ?? $xml;
            $xml = preg_replace_callback('/\{\{(.*?)\}\}/s', function ($m) {
                $inner = strip_tags($m[1]);
                $inner = preg_replace('/\s+/', '', $inner) ?? '';

                return '{{'.trim($inner).'}}';
            }, $xml) ?? $xml;

            return $xml;
        };
        $bodyXml = $defrag($bodyXml);
        $headerFooterXml = $defrag($headerFooterXml);

        // Quick aggregate counts for the summary card.
        $allMacroCount = preg_match_all('/\{\{[^}]+\}\}/', $bodyXml."\n".$headerFooterXml);
        $summary['placeholders'] = $allMacroCount ?: 0;

        // Walk every <w:p> in the body, collect the placeholders it hosts and
        // the inline tokens (text / tab / break) between them, then run the
        // per-paragraph rules. Multi-placeholder, list-inline-text, and
        // table-cell-collision findings all live here.
        $paragraphs = [];
        if (preg_match_all('#<w:p\b[^>]*>(?:(?!</w:p>).)*?</w:p>#s', $bodyXml, $pm)) {
            $paragraphs = $pm[0];
        }

        foreach ($paragraphs as $paraXml) {
            if (false === strpos($paraXml, '{{')) {
                continue;
            }
            ++$summary['paragraphs_with_placeholder'];

            // Build a flat token sequence: text-fragments + tab/break markers,
            // in document order. Tabs / breaks are separators that "rescue" a
            // shared paragraph from looking like a collision.
            $tokens = [];
            preg_match_all('#<w:t[^>]*>([^<]*)</w:t>|<w:tab/>|<w:br/>#', $paraXml, $tm, PREG_SET_ORDER);
            foreach ($tm as $t) {
                if (str_starts_with($t[0], '<w:tab')) {
                    $tokens[] = ['type' => 'sep', 'glyph' => 'tab'];
                } elseif (str_starts_with($t[0], '<w:br')) {
                    $tokens[] = ['type' => 'sep', 'glyph' => 'br'];
                } else {
                    $tokens[] = ['type' => 'text', 'text' => $t[1]];
                }
            }
            $flat = '';
            foreach ($tokens as $t) {
                $flat .= 'text' === $t['type'] ? $t['text'] : ' ';
            }
            $macroKeys = [];
            if (preg_match_all('/\{\{([^{}]+)\}\}/', $flat, $mm)) {
                $macroKeys = array_values(array_map('trim', $mm[1]));
            }
            if (empty($macroKeys)) {
                continue;
            }

            // Rule 1: multiple placeholders in one paragraph with NO separator.
            //
            // Walk the flat text and check that between any two placeholders
            // there is at least one space, tab, line-break, or visible glyph.
            // Two placeholders that abut directly (e.g. `{{a}}{{b}}`) collide
            // visually at render time — that's the v4-hhff Werdegang bug.
            //
            // Whitespace counts as a valid separator (matches what Word
            // actually renders), so common shapes like
            //   "Profil {{fullname}} {{generated_month}} {{generated_year}}"
            // or "{{zip}} {{city}}" are NOT flagged. We want to catch only
            // truly-fused placeholders.
            if (count($macroKeys) >= 2) {
                $colliding = [];
                if (preg_match_all('/\{\{([^{}]+)\}\}\{\{([^{}]+)\}\}/', $flat, $cm, PREG_SET_ORDER)) {
                    foreach ($cm as $pair) {
                        $colliding[] = trim($pair[1]).' / '.trim($pair[2]);
                    }
                }
                if (!empty($colliding)) {
                    $findings[] = [
                        'code' => 'MULTIPLE_PLACEHOLDERS_NO_SEPARATOR',
                        'severity' => 'error',
                        'message' => sprintf(
                            'Two placeholders are fused with no separator: %s. Their values will collide.',
                            implode(' · ', $colliding),
                        ),
                        'hint' => 'In Word, place a tab, space, line-break, or table-cell boundary between the placeholders. '
                               .'For Werdegang time + employer, put each in its own table cell.',
                        'context' => ['placeholder_pairs' => $colliding],
                    ];
                }
            }

            // Rule 2: a list-typed placeholder shares a paragraph with other
            // text. The engine renders one paragraph per list item by cloning
            // the host paragraph; surrounding text gets duplicated on every
            // item or orphaned, which surprises the customer.
            $rowFieldShape = static fn (string $k): bool => 1 === preg_match('/\.[a-z0-9_]+\.N$/i', $k);
            $listLikeShape = function (string $k) use ($rowFieldShape): bool {
                if ($rowFieldShape($k)) {
                    return false; // row-group fields use cloneRow, not paragraph cloning
                }
                if (str_starts_with($k, 'checkb.') || str_starts_with($k, 'optional.')) {
                    return false;
                }
                if (str_contains($k, '.')) {
                    return false; // sub-fields like target.position handled elsewhere
                }
                $listSuffixes = ['list', 'positions', 'positions_for_target', 'benefits',
                    'languages', 'other_skills', 'education', 'skills'];
                foreach ($listSuffixes as $s) {
                    if ($k === $s || str_ends_with($k, '_'.$s) || str_ends_with($k, $s)) {
                        return true;
                    }
                }

                return false;
            };
            foreach ($macroKeys as $k) {
                if (!$listLikeShape($k)) {
                    continue;
                }
                // Strip the placeholder from the flat text, see if anything
                // textually meaningful remains in the same paragraph.
                $without = str_replace('{{'.$k.'}}', '', $flat);
                $without = trim(preg_replace('/\s+/', ' ', $without) ?? '');
                if ('' !== $without && mb_strlen($without) > 1) {
                    $findings[] = [
                        'code' => 'LIST_INLINE_TEXT',
                        'severity' => 'warning',
                        'message' => sprintf(
                            'List placeholder {{%s}} shares a paragraph with text ("%s"). The surrounding text will be duplicated for every list item.',
                            $k,
                            mb_substr($without, 0, 60),
                        ),
                        'hint' => 'Move {{'.$k.'}} onto its own paragraph. Put any heading text on the line above it.',
                        'context' => ['placeholder' => $k, 'inline_text' => $without],
                    ];
                }
            }
        }

        // Rule 3: bare <w:tr> rows that host row-group placeholders. The
        // generation pre-pass auto-promotes these (see ensureRowsCarryAttributes),
        // but flagging them invites the customer to fix the source so the
        // template stays clean if exported elsewhere.
        if (preg_match_all('#<w:tr>(?:(?!</w:tr>).)*?\{\{([^}]*?\.N)\}\}(?:(?!</w:tr>).)*?</w:tr>#s', $bodyXml, $rm)) {
            $bareRows = array_unique($rm[1]);
            $summary['bare_rows_promoted'] = count($bareRows);
            if (!empty($bareRows)) {
                $findings[] = [
                    'code' => 'BARE_TR_ROW_GROUP_HOST',
                    'severity' => 'info',
                    'message' => sprintf(
                        'Row-group placeholders (%s) sit in bare <w:tr> rows. Synaplan auto-patches this at generation time, '
                            .'but the source template will be more robust if you re-edit the row in Word.',
                        implode(', ', array_map(static fn ($k) => '{{'.$k.'}}', $bareRows)),
                    ),
                    'hint' => 'In Word: click anywhere in the row → right-click → Table Properties → OK. Saving re-emits the row '
                            .'with attribute-bearing markup that PhpWord identifies unambiguously.',
                    'context' => ['placeholders' => $bareRows],
                ];
            }
        }

        // Rule 4: list / row-group placeholders inside a header or footer.
        // expandListParagraphs and expandTableBlocks are body-only by design;
        // such placeholders render as raw scalar text in headers/footers.
        if ('' !== $headerFooterXml && preg_match_all('/\{\{([^{}]+)\}\}/', $headerFooterXml, $hm)) {
            foreach (array_unique(array_map('trim', $hm[1])) as $hk) {
                if (str_ends_with($hk, '.N') || (preg_match('/^[a-z0-9_]+$/i', $hk) && in_array($hk, [
                    'languages', 'other_skills', 'benefits', 'education',
                    'relevant_positions', 'relevant_positions_for_target',
                ], true))) {
                    $findings[] = [
                        'code' => 'LIST_OR_ROW_IN_HEADER_FOOTER',
                        'severity' => 'warning',
                        'message' => sprintf(
                            'Placeholder {{%s}} lives in a header or footer; list/row expansion is body-only.',
                            $hk,
                        ),
                        'hint' => 'Headers and footers support scalar placeholders only ({{fullname}}, {{generated_year}}, …). '
                               .'Move the list into the document body.',
                        'context' => ['placeholder' => $hk],
                    ];
                }
            }
        }

        // Rule 5: placeholders nested inside a content control (<w:sdt>).
        // PhpWord's setValue cannot reach text inside an SDT's stored value,
        // so the placeholder is left as literal text in the output.
        if (preg_match_all('#<w:sdt\b(?:(?!</w:sdt>).)*?\{\{([^{}]+)\}\}(?:(?!</w:sdt>).)*?</w:sdt>#s', $bodyXml, $sm)) {
            $sdtKeys = array_unique(array_map('trim', $sm[1]));
            if (!empty($sdtKeys)) {
                $findings[] = [
                    'code' => 'PLACEHOLDER_INSIDE_SDT',
                    'severity' => 'warning',
                    'message' => sprintf(
                        'Placeholders nested inside a Word content control: %s. They will not be substituted.',
                        implode(', ', array_map(static fn ($k) => '{{'.$k.'}}', $sdtKeys)),
                    ),
                    'hint' => 'In Word: select the content control → Developer tab → Properties → "Contents cannot be edited" off, '
                            .'or replace the SDT with plain text containing the placeholder.',
                    'context' => ['placeholders' => $sdtKeys],
                ];
            }
        }

        foreach ($findings as $f) {
            ++$summary[$f['severity'].'s'];
        }

        return [
            'ok' => 0 === $summary['errors'],
            'summary' => $summary,
            'findings' => $findings,
        ];
    }

    /**
     * Convert a DOCX to PDF via a headless LibreOffice invocation. The PDF is
     * written next to the source DOCX with a `.pdf` extension and cached there
     * as long as the DOCX is fresher (mtime >= DOCX mtime). Returns null if
     * the backend doesn't have libreoffice/soffice available — the caller
     * should treat that as a soft 501.
     */
    private function convertDocxToPdf(string $docxPath): ?string
    {
        $pdfPath = preg_replace('/\.docx$/i', '.pdf', $docxPath) ?? ($docxPath.'.pdf');

        if (is_file($pdfPath) && filemtime($pdfPath) >= filemtime($docxPath)) {
            return $pdfPath;
        }

        $binary = null;
        foreach (['libreoffice', 'soffice'] as $candidate) {
            $which = @shell_exec('command -v '.escapeshellarg($candidate).' 2>/dev/null');
            if (is_string($which) && '' !== trim($which)) {
                $binary = trim($which);
                break;
            }
        }
        if (null === $binary) {
            return null;
        }

        $outDir = dirname($docxPath);
        // LibreOffice writes to `$outDir/<basename>.pdf`; we use --outdir to make
        // that explicit. Isolate each conversion with a per-process user profile
        // to avoid lock contention when two conversions race.
        $userProfile = sys_get_temp_dir().'/lo-profile-'.getmypid().'-'.bin2hex(random_bytes(4));
        $cmd = sprintf(
            '%s -env:UserInstallation=file://%s --headless --nologo --nocrashreport --nodefault --nofirststartwizard --convert-to pdf --outdir %s %s 2>&1',
            escapeshellarg($binary),
            escapeshellarg($userProfile),
            escapeshellarg($outDir),
            escapeshellarg($docxPath),
        );

        exec($cmd, $out, $code);

        // Best-effort cleanup of the per-process user profile directory.
        if (is_dir($userProfile)) {
            $this->removeDirectory($userProfile);
        }

        if (0 !== $code || !is_file($pdfPath)) {
            $this->logger->warning('LibreOffice PDF conversion failed', [
                'docx' => $docxPath,
                'code' => $code,
                'stdout' => implode("\n", array_slice($out, 0, 20)),
            ]);

            return null;
        }

        return $pdfPath;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }

        rmdir($dir);
    }

    private function seedDefaultForm(int $userId): void
    {
        $defaultForm = [
            'id' => 'default',
            'name' => 'Standard Kandidatenprofil',
            'language' => 'de',
            'version' => 1,
            'fields' => [
                ['key' => 'firstname', 'label' => 'Vorname', 'type' => 'text', 'required' => true, 'source' => 'form'],
                ['key' => 'lastname', 'label' => 'Nachname', 'type' => 'text', 'required' => true, 'source' => 'form'],
                ['key' => 'target-position', 'label' => 'Vorgestellte Position', 'type' => 'text', 'required' => true, 'source' => 'form'],
                ['key' => 'nationality', 'label' => 'Nationalität', 'type' => 'text', 'required' => false, 'source' => 'form'],
                ['key' => 'maritalstatus', 'label' => 'Familienstand', 'type' => 'select', 'options' => ['ledig', 'verheiratet', 'geschieden', 'verwitwet'], 'required' => false, 'source' => 'form'],
                ['key' => 'relevantposlist', 'label' => 'Relevante vorherige Positionen', 'type' => 'list', 'required' => false, 'source' => 'form', 'hint' => 'Eine Position pro Zeile'],
                ['key' => 'relevantfortargetposlist', 'label' => 'Relevante Berufserfahrung für Position', 'type' => 'list', 'required' => false, 'source' => 'form', 'hint' => 'z.B. Direct Reports, Mitarbeiteranzahl'],
                ['key' => 'moving', 'label' => 'Umzugsbereitschaft', 'type' => 'select', 'options' => ['Ja', 'Nein'], 'required' => false, 'source' => 'form'],
                ['key' => 'commute', 'label' => 'Pendelbereitschaft', 'type' => 'select', 'options' => ['Ja', 'Nein'], 'required' => false, 'source' => 'form'],
                ['key' => 'travel', 'label' => 'Reisebereitschaft', 'type' => 'select', 'options' => ['Ja', 'Nein'], 'required' => false, 'source' => 'form'],
                ['key' => 'noticeperiod', 'label' => 'Kündigungsfrist', 'type' => 'text', 'required' => false, 'source' => 'form'],
                ['key' => 'currentansalary', 'label' => 'Aktuelles Bruttojahresgehalt', 'type' => 'text', 'required' => false, 'source' => 'form'],
                ['key' => 'expectedansalary', 'label' => 'Erwartetes Bruttojahresgehalt', 'type' => 'text', 'required' => false, 'source' => 'form', 'hint' => "'nicht relevant' zum Weglassen"],
                ['key' => 'workinghours', 'label' => 'Vertragliche Arbeitszeit', 'type' => 'text', 'required' => false, 'source' => 'form', 'hint' => "'nicht relevant' zum Weglassen"],
                ['key' => 'benefits', 'label' => 'Sonstige Leistungen', 'type' => 'list', 'required' => false, 'source' => 'form', 'hint' => 'z.B. Firmenwagen, Bonus'],
                ['key' => 'languageslist', 'label' => 'Sprachkenntnisse', 'type' => 'list', 'required' => false, 'source' => 'form', 'hint' => 'z.B. Deutsch (Muttersprache)'],
                ['key' => 'otherskillslist', 'label' => 'Sonstige Kenntnisse', 'type' => 'list', 'required' => false, 'source' => 'form', 'hint' => 'z.B. SAP, MS Office'],
            ],
            'created_at' => date('c'),
            'updated_at' => date('c'),
        ];

        $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_FORM, 'default', $defaultForm);
    }

    private function buildExtractionPrompt(int $userId, string $rawText, array $formFields = [], string $language = 'de'): string
    {
        $languageName = $this->languageName($language);
        // Same processing-context defaults as the grouped prompt (today's
        // date → "Monat der Erfassung"-style fields, language default).
        $contextBlock = strtr(
            $this->getPromptText($userId, 'extraction_context'),
            $this->promptTokens($languageName)
        );
        $fieldLines = [];

        $defaultScalars = [
            'firstname' => 'First name / given name',
            'lastname' => 'Last name / family name / surname',
            'fullname' => 'Full name (firstname + lastname combined)',
            'address1' => 'Street and house number',
            'address2' => 'City',
            'zip' => 'Postal code',
            'birthdate' => 'Date of birth (DD.MM.YYYY format)',
            'number' => 'Phone number',
            'email' => 'Email address',
            'currentposition' => 'Current/most recent job title',
            'education' => 'Education and degrees',
            'languageslist' => '(array of strings): Language skills',
            'otherskillslist' => '(array of strings): Other skills (IT, tools)',
        ];

        $coveredKeys = [];

        foreach ($formFields as $field) {
            $key = $field['key'] ?? '';
            if ('' === $key) {
                continue;
            }
            $coveredKeys[$key] = true;
            $label = $field['label'] ?? $key;
            $hint = !empty($field['hint']) ? ' — '.$field['hint'] : '';

            if (($field['type'] ?? 'text') === 'table') {
                $columns = $field['columns'] ?? [];
                if (empty($columns)) {
                    continue;
                }
                $colDescs = [];
                foreach ($columns as $col) {
                    $colDescs[] = ($col['key'] ?? '').' (string): '.($col['label'] ?? $col['key'] ?? '');
                }
                $colBlock = implode("\n              ", $colDescs);
                $fieldLines[] = "- {$key} (array of objects): {$label}{$hint}. Most recent first. Each entry:\n              {$colBlock}";
            } elseif (($field['type'] ?? 'text') === 'list') {
                $fieldLines[] = "- {$key} (array of strings): {$label}{$hint}";
            } else {
                $fieldLines[] = "- {$key} (string): {$label}{$hint}";
            }
        }

        foreach ($defaultScalars as $key => $desc) {
            if (!isset($coveredKeys[$key])) {
                $fieldLines[] = "- {$key} {$desc}";
            }
        }

        $fieldsBlock = implode("\n            ", $fieldLines);

        return <<<PROMPT
            You are extracting structured data from a CV/resume document. Return a JSON object with these fields. Use null for any field not found in the document. Do NOT invent or guess data.

            IMPORTANT - Output language: All free-text values (job titles, descriptions,
            list items, education entries, etc.) MUST be written in {$languageName}.
            If the source document is in another language, translate descriptive text
            into {$languageName}. Proper nouns (people, company names, cities), email
            addresses, phone numbers, URLs and dates stay verbatim.

            Rules for the `stations` array (career history) — these keep each
            role clean and prevent duplicated text in the generated document:
              - Create ONE entry per POSITION / PERIOD, in reverse-chronological
                order. If the SAME employer had several roles or sub-periods
                over time, output a SEPARATE entry for EACH role/period and
                repeat the `employer` name in every one. NEVER merge multiple
                roles of one employer into a single entry, and never pack
                several periods into one `details` block.
              - `time` is the date range of THAT specific role/period, in the
                format MM/YYYY – MM/YYYY (two-digit month, four-digit year, an
                en dash "–" with one space on each side); use MM/YYYY – heute
                for an ongoing role, and YYYY – YYYY only when no month is known.
                It is the role's own span, NOT the employer's overall span.
              - `employer` is the company name, repeated across that
                employer's rows.
              - `position` is the single job title held during that period.
              - `details` contains ONLY the activities/achievements for THAT
                role, as separate bullet lines. It MUST NOT repeat the `time`,
                `employer`, or `position` values, and MUST NOT contain another
                period's content. Begin `details` directly with the first
                bullet / sentence — no date header line, no title line.
              - `stations` is the full chronological Werdegang. INCLUDE, besides
                regular jobs, completed vocational training (Berufsausbildung /
                Lehre) AND professionally relevant internships (substantial,
                field-related Praktika) as their own rows (`employer` = training
                company / institution, `position` = e.g. "Ausbildung zur/zum …"
                or "Praktikum – …"). EXCLUDE only general schooling (Schule,
                Abitur / Fachabitur), academic degree programmes (Studium /
                university degrees) and short school internships
                (Schülerpraktikum) — those stay in the `education` field. A
                Berufsausbildung or relevant internship MAY appear in BOTH
                `education` (summary) and `stations` (chronological entry).
              - If a `current_position` field exists, fill it with the present
                role as "Job title – Employer, Location" (include the current
                employer and its city/location when known), not the bare title.
              - OPTIONAL grouping columns — ONLY populate these when the
                `stations` table actually declares them:
                  * `time_total`: when ONE employer had SEVERAL consecutive
                    roles, set this on that employer's FIRST row to the overall
                    span (e.g. "01/2014 – 03/2024"); leave empty on the others.
                  * `sub_of_previous`: set to true for a role whose period lies
                    INSIDE another role's period (e.g. a part-time job held
                    during a degree programme), so it can be rendered indented.
                  Never invent these — leave them empty/false when unknown.

            {$contextBlock}

            Fields to extract:
            {$fieldsBlock}

            Return ONLY valid JSON, no explanation.

            CV Text:
            ---
            {$rawText}
            ---
            PROMPT;
    }

    /**
     * Best-effort JSON extractor for LLM responses.
     *
     * Models in the wild like to wrap JSON in markdown fences, prepend
     * "Here is the result:" prose, or — in the case of OpenAI's gpt-oss
     * harmony format — leak `<|channel|>analysis<|message|>…<|end|>`
     * reasoning markers into the assistant message. We strip those
     * artefacts and then try a series of progressively looser parsers
     * until one returns a valid associative array.
     */
    private function parseJsonFromAiResponse(string $content): ?array
    {
        $original = $content;

        // 1. Strip Harmony / GPT-OSS channel markers like
        //    "<|channel|>analysis<|message|>…<|end|>" so the residual
        //    text still parses cleanly.
        $content = preg_replace('/<\|[^|]+\|>/u', '', $content) ?? $content;

        // 2. Strip leading "json" hint, BOM, zero-width chars, and trim.
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $content = trim($content);

        // 3. Direct decode — happy path when the model behaved.
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // 4. ```json fenced block.
        if (preg_match('/```(?:json|JSON)?\s*\n?([\s\S]*?)\n?\s*```/u', $content, $match)) {
            $decoded = json_decode(trim($match[1]), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // 5. Walk the content extracting every balanced { … } or [ … ]
        //    block, trying each one in order. Reasoning prose often
        //    contains its own brace-pairs ("{looks like this}") so we
        //    can't just take the first hit.
        $offset = 0;
        while (($balanced = $this->extractBalancedJson($content, $offset)) !== null) {
            $decoded = json_decode($balanced['text'], true);
            if (is_array($decoded)) {
                return $decoded;
            }
            $offset = $balanced['end'];
        }

        // 6. Last resort: legacy regex (greedy, but fenced); helps when
        //    the JSON itself is fine but the model also produced trailing
        //    prose with braces inside it.
        if (preg_match('/\{[\s\S]*\}/u', $content, $match)) {
            $decoded = json_decode($match[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $this->logger->warning('Synaform: failed to extract JSON from AI response', [
            'preview' => mb_substr($original, 0, 600),
            'length' => strlen($original),
        ]);

        return null;
    }

    /**
     * Pull the next balanced JSON object or array out of the haystack
     * starting at $fromOffset. Walks character-by-character with a depth
     * counter so we don't get tripped up by braces inside reasoning
     * text or string contents.
     *
     * @return array{text: string, end: int}|null
     */
    private function extractBalancedJson(string $haystack, int $fromOffset = 0): ?array
    {
        $len = strlen($haystack);
        for ($start = $fromOffset; $start < $len; ++$start) {
            $ch = $haystack[$start];
            if ('{' !== $ch && '[' !== $ch) {
                continue;
            }
            $open = $ch;
            $close = '{' === $ch ? '}' : ']';
            $depth = 0;
            $inString = false;
            $escape = false;
            for ($i = $start; $i < $len; ++$i) {
                $c = $haystack[$i];
                if ($escape) {
                    $escape = false;
                    continue;
                }
                if ('\\' === $c) {
                    $escape = true;
                    continue;
                }
                if ('"' === $c) {
                    $inString = !$inString;
                    continue;
                }
                if ($inString) {
                    continue;
                }
                if ($c === $open) {
                    ++$depth;
                } elseif ($c === $close) {
                    --$depth;
                    if (0 === $depth) {
                        return [
                            'text' => substr($haystack, $start, $i - $start + 1),
                            'end' => $i + 1,
                        ];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Normalises a language value coming from the API (UI dropdown, REST
     * payload, etc.) into a canonical 2-letter ISO code we use across the
     * plugin. Falls back to '' (= "inherit from form") for unknown values.
     */
    private function normalizeLanguage(mixed $raw): string
    {
        if (!is_string($raw)) {
            return '';
        }
        $code = strtolower(trim($raw));
        if ('' === $code) {
            return '';
        }
        // Accept BCP-47 style "de-DE" by taking the primary subtag.
        if (str_contains($code, '-')) {
            $code = explode('-', $code, 2)[0];
        }
        $supported = ['de', 'en', 'es', 'fr', 'it', 'tr', 'pt', 'nl', 'pl'];

        return in_array($code, $supported, true) ? $code : '';
    }

    /**
     * Maps an ISO language code to a human readable language name we feed
     * to the LLM. We always pass an English name regardless of UI locale
     * because LLM providers respond more reliably to English instructions.
     */
    private function languageName(string $code): string
    {
        return match ($code) {
            'de' => 'German',
            'en' => 'English',
            'es' => 'Spanish',
            'fr' => 'French',
            'it' => 'Italian',
            'tr' => 'Turkish',
            'pt' => 'Portuguese',
            'nl' => 'Dutch',
            'pl' => 'Polish',
            default => 'German',
        };
    }

    /**
     * Decides which language the AI should write its extracted values in.
     * Priority:
     *   1. If the form's attached target templates declare a language and
     *      they all agree, use that. (User picked DOCX templates in a
     *      specific language; values must match.)
     *   2. Otherwise fall back to the form/collection language.
     *   3. Final fallback: 'de' (historical default).
     */
    private function resolveExtractionLanguage(int $userId, ?array $form): string
    {
        $templateIds = $form['template_ids'] ?? [];
        $templateLanguages = [];
        if (is_array($templateIds)) {
            foreach ($templateIds as $tplId) {
                if (!is_string($tplId) || '' === $tplId) {
                    continue;
                }
                $tpl = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_TEMPLATE, $tplId);
                $lang = $this->normalizeLanguage($tpl['language'] ?? null);
                if ('' !== $lang) {
                    $templateLanguages[$lang] = true;
                }
            }
        }
        if (1 === count($templateLanguages)) {
            return array_key_first($templateLanguages);
        }

        $formLang = $this->normalizeLanguage($form['language'] ?? null);
        if ('' !== $formLang) {
            return $formLang;
        }

        return 'de';
    }

    private function buildImportParsePrompt(string $text): string
    {
        return <<<PROMPT
        You are parsing a variable definition table for a document template system.
        The input is pasted text (likely from a Confluence page or Word document) that describes template placeholders.

        Each variable has a placeholder like {{key}} and a description of where the data comes from and what type it is.

        Parse this into a JSON array of field objects. Each field object must have:
        - "key" (string): the placeholder name WITHOUT curly braces, lowercase, using hyphens for compound names
        - "label" (string): a human-readable German label for the field
        - "type" (string): one of "text", "textarea", "select", "list", "date", "checkbox"
        - "required" (boolean): true if the field seems mandatory
        - "source" (string): "form" if data comes from a questionnaire/form, "ai" if extracted from CV/documents
        - "fallback" (string|null): secondary source if primary is empty. "form" or "ai" or null
        - "hint" (string|null): any special instructions (e.g. "leave empty if not relevant")
        - "options" (array|null): for "select" type, the list of allowed values

        Rules for determining type:
        - If description says "als Liste verwaltet" or "Ein oder mehr Einträge" -> type "list"
        - If the value is "Ja oder Nein" or "Ja/Nein" -> type "select" with options ["Ja", "Nein"]
        - If it contains dates -> type "text" (we use text for dates)
        - If it needs multi-line content (like career details) -> type "textarea"
        - Default -> type "text"

        Rules for determining source:
        - "aus dem Lebenslauf extrahiert" or "muss aus dem Lebenslauf" -> source "ai"
        - "kommt aus dem Formular" or "aus dem vorbereiteten Formular" -> source "form"
        - "kommt aus dem Formular, Lebenslauf-Extraktion als Fallback" -> source "form", fallback "ai"
        - "aus dem Lebenslauf, Formular als Fallback" or "wenn nicht, schaue in das Formular" -> source "ai", fallback "form"
        - If both form and CV are mentioned, the first one mentioned is primary

        IMPORTANT skip rules:
        - SKIP any {{checkb.*}} placeholders (checkbox derivatives are auto-generated)
        - SKIP any {{groupname.field}} placeholders where groupname.field represents table row data (these are handled separately as table fields)
        - SKIP any {{#blockname}} / {{/blockname}} block markers

        Special handling:
        - If description says "Weglassen wenn nicht relevant" or similar -> add hint "Leave empty if not relevant"
        - For fields with "nicht relevant" logic -> add hint explaining the conditional

        Return ONLY a valid JSON array of field objects. No explanation, no markdown.

        Input text:
        ---
        {$text}
        ---
        PROMPT;
    }

    /**
     * Fetch a URL and return plain-text content for AI consumption.
     *
     * Best-effort, defensive: many public profile pages (LinkedIn,
     * Instagram, Facebook, X) **always** block unauthenticated
     * server-side fetches — this is structural, not transient. We
     * detect those hosts up front and return an actionable error
     * instead of misleading the user with "rate-limited" language
     * (which implies "wait and retry"; for these hosts retry never
     * helps without a logged-in session). Open pages (GitHub,
     * corporate sites, XING public profiles, Wikipedia, etc.) do
     * work — the AI prompt tolerates partial / noisy input, so
     * even a thin snippet is useful.
     *
     * @return array{0: ?string, 1: ?string} [plainText|null, errorMessage|null]
     */
    private function fetchUrlText(string $url): array
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));

        try {
            $response = $this->http->request('GET', $url, [
                'timeout' => 15,
                'max_duration' => 15,
                'max_redirects' => 5,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (compatible; Synaform-Bot/1.0; +https://synaplan.com)',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9,de;q=0.8',
                ],
            ]);
            $status = $response->getStatusCode();
            $raw = $response->getContent(false);
            $err = null;
        } catch (TransportExceptionInterface $e) {
            $status = 0;
            $raw = '';
            $err = $e->getMessage();
        }

        if ('' === $raw) {
            return [null, $this->describeUrlFetchError($host, $status, $err ?: 'Failed to fetch URL')];
        }

        // Strip script/style/noscript blocks, then all tags. Collapse whitespace.
        $cleaned = preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', (string) $raw) ?? '';
        $cleaned = preg_replace('#<!--.*?-->#s', ' ', $cleaned) ?? '';
        $cleaned = html_entity_decode(strip_tags($cleaned), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $cleaned = preg_replace('/[ \t]+/', ' ', $cleaned) ?? '';
        $cleaned = preg_replace("/\n{3,}/", "\n\n", $cleaned) ?? '';
        $cleaned = trim($cleaned);

        // Cap the stored snippet to keep plugin_data rows reasonable.
        $cap = 60000;
        if (function_exists('mb_strlen') && mb_strlen($cleaned) > $cap) {
            $cleaned = mb_substr($cleaned, 0, $cap)."\n…[truncated]…";
        } elseif (strlen($cleaned) > $cap) {
            $cleaned = substr($cleaned, 0, $cap)."\n…[truncated]…";
        }

        // Detect login-wall responses that come back HTTP 200 with
        // essentially a sign-in shell (LinkedIn does this on certain
        // CDNs even when 999 is not returned). Heuristic: very short
        // content that looks like a login prompt.
        $isWalledHost = $this->isLoginWalledHost($host);
        $cleanedLen = function_exists('mb_strlen') ? mb_strlen($cleaned) : strlen($cleaned);
        $looksLikeLoginShell = $isWalledHost && $cleanedLen < 600
            && 1 === preg_match('/\b(sign in|log in|join now|anmelden|einloggen|connectez-vous)\b/i', $cleaned);

        if ($status >= 400 || $looksLikeLoginShell) {
            return [
                '' !== $cleaned && !$looksLikeLoginShell ? $cleaned : null,
                $this->describeUrlFetchError($host, $status, null),
            ];
        }

        if ('' === $cleaned) {
            return [null, $this->describeUrlFetchError($host, $status, 'No extractable text')];
        }

        return [$cleaned, null];
    }

    /**
     * Translate a (host, http_code, transport_error) tuple into a
     * user-facing message that tells the user what to do next instead
     * of just dumping a raw HTTP code. Honesty matters: calling a
     * structural block "rate-limited" sends users into a retry loop
     * that can never succeed.
     */
    private function describeUrlFetchError(string $host, int $status, ?string $transportError): string
    {
        $isLinkedIn = str_contains($host, 'linkedin.com');
        $isInstagram = str_contains($host, 'instagram.com');
        $isFacebook = str_contains($host, 'facebook.com') || str_contains($host, 'fb.com');
        $isTwitter = 'x.com' === $host || str_ends_with($host, '.x.com')
            || str_contains($host, 'twitter.com');

        // 429 is the only HTTP code that genuinely means "wait and retry".
        if (429 === $status) {
            return 'The site rate-limited the fetch (HTTP 429). Try again in a minute.';
        }

        // LinkedIn 999, login walls, or any 4xx from these hosts are
        // structural — retry never works without a logged-in session.
        if ($isLinkedIn) {
            return 'LinkedIn blocks server-side fetches of profile pages. Open the profile in your browser, choose “More → Save to PDF”, and upload the PDF as a CV instead. The URL stays attached as a reference.';
        }
        if ($isInstagram) {
            return 'Instagram blocks server-side fetches. Take a screenshot of the profile and upload it as an additional document instead. The URL stays attached as a reference.';
        }
        if ($isFacebook) {
            return 'Facebook blocks server-side fetches of profile pages. Save the page as PDF from your browser and upload it as an additional document instead. The URL stays attached as a reference.';
        }
        if ($isTwitter) {
            return 'X (Twitter) blocks server-side fetches without authentication. Copy the relevant content into the description field, or take a screenshot. The URL stays attached as a reference.';
        }

        if (0 === $status && $transportError) {
            return 'Could not reach the URL: '.$transportError;
        }
        if ($status >= 400) {
            return 'The site returned HTTP '.$status.' — the page is not publicly readable by a server.';
        }
        if ($transportError) {
            return $transportError;
        }

        return 'No extractable text on the page.';
    }

    /**
     * Hosts that are known to redirect or anti-bot to a login wall.
     * Used to flag short HTTP-200 responses that are really sign-in
     * shells (no real profile content reached us).
     */
    private function isLoginWalledHost(string $host): bool
    {
        $needles = ['linkedin.com', 'instagram.com', 'facebook.com', 'x.com', 'twitter.com'];
        foreach ($needles as $n) {
            if (str_contains($host, $n)) {
                return true;
            }
        }

        return false;
    }

    private function extractTextFromDocx(string $path): ?string
    {
        // Pass 1 — PhpWord-driven traversal. Cleanest output (paragraph
        // breaks, table cell tabbing) but misses anything PhpWord wraps in
        // an element type without getText/getElements/getRows. Common
        // miss: headers + footers of CV templates.
        try {
            $phpWord = PhpWordIOFactory::load($path);
            $text = '';
            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $element) {
                    $text .= $this->extractElementText($element)."\n";
                }
                // Walk headers and footers too — sections expose them as
                // separate getHeaders()/getFooters() collections rather
                // than as elements of the section body.
                if (method_exists($section, 'getHeaders')) {
                    foreach ($section->getHeaders() as $header) {
                        foreach ($header->getElements() ?? [] as $element) {
                            $text .= $this->extractElementText($element)."\n";
                        }
                    }
                }
                if (method_exists($section, 'getFooters')) {
                    foreach ($section->getFooters() as $footer) {
                        foreach ($footer->getElements() ?? [] as $element) {
                            $text .= $this->extractElementText($element)."\n";
                        }
                    }
                }
            }

            if ('' !== trim($text)) {
                return $text;
            }
            // PhpWord parsed the document but didn't surface any text.
            // Fall through to the raw-XML pass.
            $this->logger->info('Synaform: PhpWord returned empty text, falling back to raw XML', ['path' => $path]);
        } catch (\Throwable $e) {
            // PhpWord refused the file outright (exotic Word features,
            // strict-mode parser, etc.). The raw-XML pass below is much
            // more forgiving so we still give it a shot.
            $this->logger->warning('Synaform: PhpWord extraction failed, falling back to raw XML', ['err' => $e->getMessage()]);
        }

        // Pass 2 — raw XML extraction. Reads every <w:t> node in the
        // document, headers, and footers. Same approach as
        // extractPlaceholders(), so anything that has detectable
        // placeholders also has detectable plain text.
        return $this->extractTextFromDocxRaw($path);
    }

    /**
     * Pull readable text out of a .docx by reading its XML parts directly.
     * Walks every `<w:p>` paragraph in document.xml and every header /
     * footer part, joins the text inside, and separates paragraphs with
     * newlines. Used as a fallback when PhpWord can't extract anything
     * (exotic Word features, strict-mode parser refusals, or content
     * tucked into element types PhpWord's iterator doesn't surface).
     *
     * Returns null only when the zip can't be opened or the XML yields
     * no text at all anywhere.
     */
    private function extractTextFromDocxRaw(string $path): ?string
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($path)) {
            $this->logger->warning('Synaform: raw extraction could not open docx', ['path' => $path]);

            return null;
        }

        $paragraphs = [];
        try {
            foreach ($this->collectDocumentPartNames($zip) as $partName) {
                $xml = $zip->getFromName($partName);
                if (false === $xml || '' === $xml) {
                    continue;
                }
                // Match each <w:p>…</w:p> paragraph (non-greedy, multiline)
                // and pull the text out of every <w:t> inside it.
                if (!preg_match_all('#<w:p\b[^>]*>(.*?)</w:p>#us', $xml, $paraMatches)) {
                    continue;
                }
                foreach ($paraMatches[1] as $paraInner) {
                    $paraText = '';
                    if (preg_match_all('#<w:t(?:\s[^>]*)?>([^<]*)</w:t>#u', $paraInner, $tMatches)) {
                        foreach ($tMatches[1] as $chunk) {
                            $paraText .= html_entity_decode((string) $chunk, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                        }
                    }
                    // Honour tab markers and explicit line breaks inside
                    // the paragraph. <w:br/> can carry attributes so
                    // match permissively.
                    if (preg_match('#<w:tab\b#', $paraInner)) {
                        $paraText = preg_replace_callback(
                            '#<w:tab\b[^>]*/?>#',
                            static fn () => "\t",
                            $paraText,
                        ) ?? $paraText;
                    }
                    if ('' !== trim($paraText)) {
                        $paragraphs[] = $paraText;
                    }
                }
            }
        } finally {
            $zip->close();
        }

        if (empty($paragraphs)) {
            return null;
        }

        return implode("\n", $paragraphs);
    }

    // =====================================================================
    // AI Suggest-from-DOCX pipeline — see templatesAiSuggestFromDocx() for
    // the orchestrator. The pipeline turns a draft Word document into a
    // reusable template by running multiple AI passes plus a deterministic
    // verification stage.
    //
    //   Stage 1 — analyzeDocumentProfile()      classify the doc
    //   Stage 2 — proposeVariablesPass()        (called up to 3×) propose vars
    //   Stage 3 — refineSuggestionSnippets()    verify / repair source_text
    //   Stage 4 — applyPlaceholdersToDocx()     deterministic XML rewrite
    // =====================================================================

    /**
     * Per-doc-type variable hints. Fed to the proposal prompt so the model
     * gets concrete examples of what matters for THIS kind of document
     * instead of guessing in the abstract. Keep the lists short and
     * unambiguous — they're prompts, not contracts.
     */
    private const SUGGEST_DOC_TYPE_HINTS = [
        'cv' => [
            'description' => 'A candidate CV / resume.',
            'priority_vars' => 'candidate full name, current/most-recent employer, current/most-recent role title, dates of the current employment, contact email, contact phone, address (street + city + zip), date of birth, nationality, target position, languages list, key skills list',
            'avoid' => 'do NOT propose section headings ("Berufserfahrung", "Ausbildung", "Skills"), and do NOT propose each individual job entry — career history is collected separately as a repeating row group',
        ],
        'offer_letter' => [
            'description' => 'A job offer / employment-offer letter.',
            'priority_vars' => 'recipient name, recipient address, position title, employer name, start date, annual salary, bonus / variable comp, working hours, notice period, signing deadline, sender name, sender title',
            'avoid' => 'do NOT propose legal boilerplate paragraphs',
        ],
        'contract' => [
            'description' => 'A formal contract between two or more parties.',
            'priority_vars' => 'party A name, party A address, party B name, party B address, effective date, contract term (length / end date), payment amount, payment cadence, jurisdiction, signing date, signer names',
            'avoid' => 'do NOT propose clause headings or recitals',
        ],
        'proposal' => [
            'description' => 'A sales or services proposal.',
            'priority_vars' => 'client name, client company, project title, project scope summary, project start date, project end date, total amount, payment terms, proposal valid-until date, sender / account manager name',
            'avoid' => 'do NOT propose multi-page service descriptions verbatim — pick the project title and scope summary instead',
        ],
        'invoice' => [
            'description' => 'An invoice or bill.',
            'priority_vars' => 'invoice number, invoice date, due date, biller name, biller address, customer name, customer address, line-item descriptions, line-item amounts, subtotal, tax, total, payment terms, payment reference',
            'avoid' => 'do NOT propose currency symbols or recurring header text',
        ],
        'report' => [
            'description' => 'A report (status, financial, project, …).',
            'priority_vars' => 'report title, reporting period (start–end), report date, author name, author role, executive-summary key figures, top headline metric value',
            'avoid' => 'do NOT propose long body sections — pick titles and key figures only',
        ],
        'brief' => [
            'description' => 'A creative / project brief.',
            'priority_vars' => 'client name, project name, deadline, budget, target audience description, key message, deliverables list, sender name',
            'avoid' => 'do NOT propose long narrative paragraphs',
        ],
        'letter' => [
            'description' => 'A formal letter.',
            'priority_vars' => 'sender name, sender address, recipient name, recipient address, letter date, subject line, salutation name, signature name, signature title',
            'avoid' => 'do NOT propose body-paragraph sentences as variables',
        ],
        'other' => [
            'description' => 'A general document of unknown type.',
            'priority_vars' => 'document title, primary recipient name, key date(s), key amount(s), names of the parties / people involved, contact details, deadlines',
            'avoid' => 'do NOT propose long passages — keep snippets short and meaningful',
        ],
    ];

    /**
     * Trim the document text we send to the model to a sensible budget.
     * Smart truncation: keep the head and the tail when the doc is too
     * long, so we preserve both the document title / opening AND any
     * signature / footer info, which is often where dates and signer
     * names live.
     */
    private function clipDocumentForPrompt(string $text, int $headChars = 22000, int $tailChars = 6000): string
    {
        $len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if ($len <= $headChars + $tailChars + 200) {
            return $text;
        }
        $head = function_exists('mb_substr') ? mb_substr($text, 0, $headChars) : substr($text, 0, $headChars);
        $tail = function_exists('mb_substr') ? mb_substr($text, $len - $tailChars) : substr($text, $len - $tailChars);

        return $head."\n…[middle of document omitted — ".($len - $headChars - $tailChars)." characters skipped]…\n".$tail;
    }

    /**
     * Split the source text into overlapping windows so the proposal stage
     * can scan the WHOLE document instead of only the head+tail clip.
     *
     * Pre-fix symptom: the AI saw `clipDocumentForPrompt(22 000, 6 000)` and,
     * combined with the "return between 6 and 12 entries" instruction and
     * the per-doc-type priority-var hints, it always locked onto the very
     * first page — names, addresses, dates — and never proposed anything
     * from page 2+. Multi-pass calls didn't help because every pass saw
     * the same clipped slice.
     *
     * The splitter:
     *   - Anchors window boundaries on paragraph breaks (`\n`) so we never
     *     cut a candidate snippet in half mid-word.
     *   - Adds a small backward overlap between windows so a candidate
     *     that straddles the boundary is still seen whole in one window.
     *   - Caps the number of windows so a runaway 500-page upload doesn't
     *     fan out into 50 AI calls; we head/tail-clip beyond the cap.
     *
     * @return list<string>
     */
    private function splitDocumentIntoWindows(
        string $text,
        int $windowChars = 18000,
        int $overlapChars = 2000,
        int $maxWindows = 12,
    ): array {
        $len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if (0 === $len) {
            return [];
        }
        // Short docs: one window, no overlap, no clip.
        if ($len <= $windowChars) {
            return [$text];
        }
        // Pull every paragraph break offset once — we use them to nudge each
        // window's end forward to the nearest paragraph boundary so we never
        // slice through the middle of a candidate snippet.
        $paragraphOffsets = [0];
        $offset = 0;
        $hasMb = function_exists('mb_strpos');
        while (true) {
            $next = $hasMb ? mb_strpos($text, "\n", $offset) : strpos($text, "\n", $offset);
            if (false === $next) {
                break;
            }
            $paragraphOffsets[] = $next + 1;
            $offset = $next + 1;
        }
        $paragraphOffsets[] = $len;
        $totalBreaks = count($paragraphOffsets);

        $windows = [];
        $cursor = 0;
        $lastEnd = 0;
        while ($cursor < $len && count($windows) < $maxWindows) {
            $rawEnd = min($cursor + $windowChars, $len);
            // Find the largest paragraph break ≤ rawEnd. Falls back to
            // rawEnd when the doc is one giant paragraph.
            $end = $rawEnd;
            for ($i = $totalBreaks - 1; $i >= 0; --$i) {
                $candidate = $paragraphOffsets[$i];
                if ($candidate <= $rawEnd && $candidate > $cursor + 1000) {
                    $end = $candidate;
                    break;
                }
            }
            $slice = $hasMb
                ? mb_substr($text, $cursor, $end - $cursor)
                : substr($text, $cursor, $end - $cursor);
            $windows[] = $slice;
            $lastEnd = $end;
            if ($end >= $len) {
                break;
            }
            // Step forward, leaving an overlap so a snippet that straddles
            // the cut is still complete in the next window.
            $cursor = max($end - $overlapChars, $cursor + 1);
        }

        // Doc was longer than maxWindows × windowChars. Replace the LAST
        // window with a tail slice so the signature block / last-page
        // fields are still visible to the AI; otherwise long contracts
        // would only ever get scanned up to ~12 × 18 k characters.
        if ($lastEnd < $len) {
            $tailStart = max($len - $windowChars, $lastEnd - $overlapChars);
            $tail = $hasMb
                ? mb_substr($text, $tailStart, $len - $tailStart)
                : substr($text, $tailStart, $len - $tailStart);
            $windows[count($windows) - 1] = $tail;
        }

        return $windows;
    }

    /**
     * Stage 1 — classify the document. Small AI call (≤ 1500 tokens) that
     * returns a profile we feed into the type-aware proposal prompt.
     *
     * Falls back to a generic "other" profile (and logs the failure) when
     * the AI is unavailable, returns garbage, or doesn't include enough
     * of the expected fields. The pipeline keeps working in that case —
     * just with a less-targeted proposal prompt.
     *
     * @return array{
     *   doc_type: string,
     *   doc_type_label: string,
     *   primary_language: string,
     *   summary: string,
     *   sections: list<string>,
     *   _analyzed_by_ai?: bool,
     *   _model?: string,
     * }
     */
    private function analyzeDocumentProfile(string $sourceText, int $userId, string $languageHint): array
    {
        // We only need a representative slice of the doc to classify it;
        // classification doesn't need to see every bullet point. Slightly
        // wider than the original 6 000 / 1 500 because a long offer
        // letter or report can have a 6 KB cover page that hides the
        // actual document type from a head-only excerpt.
        $excerpt = $this->clipDocumentForPrompt($sourceText, 10000, 3000);
        $allowedTypes = implode(' | ', array_map(
            static fn (string $t): string => '"'.$t.'"',
            array_keys(self::SUGGEST_DOC_TYPE_HINTS),
        ));
        $hintForLanguage = '' !== $languageHint
            ? "The caller provided a UI-language hint of \"{$languageHint}\". Use that for `doc_type_label` if it makes sense; the `primary_language` field should still reflect the document's own language."
            : 'No UI-language hint provided — pick whichever language the document is mostly written in.';

        $prompt = <<<PROMPT
        You are classifying a Word document so we can later turn it into a reusable template.

        Read the excerpt below and return ONE JSON object — nothing else, no markdown, no prose. The object MUST have EXACTLY these keys:

          "doc_type"         one of: {$allowedTypes}
          "doc_type_label"   short human-readable label for the doc type, in the document's own language (max 40 chars)
          "primary_language" 2-letter ISO 639-1 code of the document language ("en", "de", "es", "tr", "fr", "it", "nl", "pt", "pl")
          "summary"          one sentence describing what the document is (max 200 chars)
          "sections"         list of section headings visible in the document (max 10 short strings)

        Rules:
        - Pick "other" if no listed type fits — don't force-fit.
        - {$hintForLanguage}
        - Return ONLY the JSON object. No code fences. No commentary.

        Document excerpt:
        ---
        {$excerpt}
        ---
        PROMPT;

        $fallback = [
            'doc_type' => 'other',
            'doc_type_label' => 'Document',
            'primary_language' => '' !== $languageHint ? $languageHint : 'en',
            'summary' => '',
            'sections' => [],
            '_analyzed_by_ai' => false,
            '_model' => 'unknown',
        ];

        try {
            $messages = [
                ['role' => 'system', 'content' => 'You classify Word documents. Always return a single JSON object — no prose, no markdown fences.'],
                ['role' => 'user',   'content' => $prompt],
            ];
            $aiOptions = $this->resolveAiModelOptions($userId);
            $aiOptions['max_tokens'] = 1500;
            $result = $this->aiFacade->chat($messages, $userId, $aiOptions);
            $content = (string) ($result['content'] ?? '');
            $modelUsed = (string) ($result['model'] ?? 'unknown');

            $parsed = $this->parseJsonFromAiResponse($content);
            // We asked for a single object, but be tolerant if a model
            // wraps it in an array.
            if (is_array($parsed) && isset($parsed[0]) && is_array($parsed[0])) {
                $parsed = $parsed[0];
            }
            if (!is_array($parsed)) {
                $this->logger->info('Synaform analyze: unparseable response, using fallback', [
                    'model' => $modelUsed,
                    'preview' => mb_substr($content, 0, 300),
                ]);
                $fallback['_model'] = $modelUsed;

                return $fallback;
            }

            $docType = isset($parsed['doc_type']) && is_string($parsed['doc_type'])
                ? strtolower(trim($parsed['doc_type']))
                : 'other';
            if (!isset(self::SUGGEST_DOC_TYPE_HINTS[$docType])) {
                $docType = 'other';
            }

            $primaryLang = isset($parsed['primary_language']) && is_string($parsed['primary_language'])
                ? $this->normalizeLanguage($parsed['primary_language'])
                : '';
            if ('' === $primaryLang) {
                $primaryLang = '' !== $languageHint ? $languageHint : 'en';
            }

            $sections = [];
            if (isset($parsed['sections']) && is_array($parsed['sections'])) {
                foreach ($parsed['sections'] as $s) {
                    if (is_string($s) && '' !== trim($s)) {
                        $sections[] = trim($s);
                        if (count($sections) >= 10) {
                            break;
                        }
                    }
                }
            }

            return [
                'doc_type' => $docType,
                'doc_type_label' => isset($parsed['doc_type_label']) && is_string($parsed['doc_type_label'])
                    ? mb_substr(trim($parsed['doc_type_label']), 0, 60)
                    : ucfirst($docType),
                'primary_language' => $primaryLang,
                'summary' => isset($parsed['summary']) && is_string($parsed['summary'])
                    ? mb_substr(trim($parsed['summary']), 0, 240)
                    : '',
                'sections' => $sections,
                '_analyzed_by_ai' => true,
                '_model' => $modelUsed,
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('Synaform analyze: AI call failed, using fallback', ['err' => $e->getMessage()]);

            return $fallback;
        }
    }

    /**
     * Stage 2 — type-aware variable proposal. Sends the document plus the
     * profile from Stage 1 and a list of keys / snippets to AVOID (so we
     * can re-call this for a top-up pass without re-proposing the same
     * variables). Returns the normalised suggestions list plus diagnostic
     * info we surface in the pipeline log.
     *
     * @param array<string, true> $excludedKeys     keys we already kept
     * @param array<string, true> $excludedSnippets lowercase source_texts already kept
     *
     * @return array{
     *   suggestions: list<array<string, mixed>>,
     *   raw_count: int,
     *   model: string,
     *   response_len: int,
     *   recovered_truncated: bool,
     * }
     */
    private function proposeVariablesPass(
        string $sourceText,
        string $windowText,
        array $profile,
        array $excludedKeys,
        array $excludedSnippets,
        int $windowIndex,
        int $totalWindows,
        int $userId,
    ): array {
        $docText = $windowText;
        $docType = $profile['doc_type'] ?? 'other';
        $hints = self::SUGGEST_DOC_TYPE_HINTS[$docType] ?? self::SUGGEST_DOC_TYPE_HINTS['other'];
        $language = $profile['primary_language'] ?? 'en';
        $languageName = $this->languageName($language);
        $docTypeLabel = $profile['doc_type_label'] ?? ucfirst($docType);
        $summary = $profile['summary'] ?? '';
        $sections = !empty($profile['sections']) ? implode('; ', $profile['sections']) : '(none detected)';

        // Multi-window scan: tell the model exactly which slice of the
        // document it is looking at so it stops gravitating back to
        // first-page content on later windows. The hard rule that follows
        // ("only variables visible BELOW") is the lever that breaks the
        // first-page lock-in.
        if ($totalWindows > 1) {
            $windowContext = "This is window {$windowIndex} of {$totalWindows} from a longer document. You are seeing ONLY this slice. Propose variables that are visible IN THIS WINDOW, even if they look less central than variables that may exist earlier or later in the document. Other windows are handled by separate calls.";
        } else {
            $windowContext = 'You are seeing the entire document — propose every meaningful variable visible below.';
        }

        $excludedKeysList = empty($excludedKeys) ? '(none yet)'
            : implode(', ', array_keys($excludedKeys));
        $excludedSnippetsList = empty($excludedSnippets) ? '(none yet)'
            : implode(' | ', array_map(
                static fn (string $s): string => '"'.mb_substr($s, 0, 60).'"',
                array_keys($excludedSnippets),
            ));

        $prompt = <<<PROMPT
        You are turning a draft {$docTypeLabel} into a reusable Word template by proposing the variables that should become {{placeholders}}.

        Document profile (from a prior analysis pass):
          • doc_type:          {$docType}
          • doc_type_label:    {$docTypeLabel}
          • primary_language:  {$language}
          • summary:           {$summary}
          • sections visible:  {$sections}

        Per-type guidance:
          • {$hints['description']}
          • Variables that almost always matter for this type:
              {$hints['priority_vars']}
          • {$hints['avoid']}

        Window context:
          {$windowContext}

        Already proposed by previous windows (DO NOT repeat — neither these keys nor these source snippets):
          keys:     {$excludedKeysList}
          snippets: {$excludedSnippetsList}

        Return ONLY a JSON array (compact, no extra whitespace). Each item MUST have EXACTLY these 5 keys:
        - "key"          string, lowercase ASCII, hyphen-separated, max 32 chars, unique within this response AND not in the exclusion list above
        - "label"        string, short human-readable label in {$languageName}
        - "type"         one of: "text", "textarea", "number", "date", "select", "list"
        - "source"       one of: "form" (user types it in a questionnaire) or "ai" (best extracted from a sibling document like a CV)
        - "source_text"  string, the EXACT verbatim substring as it appears in the WINDOW below — SAME casing, SAME punctuation, NO surrounding whitespace, NO ellipses, NO paraphrasing

        Hard rules:
        - Propose every meaningful variable visible in this window. Return 0 entries if nothing varies in this slice (boilerplate, signatures, headings only); otherwise prefer thoroughness over brevity (up to ~15 entries per window is fine).
        - Each `source_text` MUST be a CONTIGUOUS run of characters that appears literally in the WINDOW below. If the text appears more than 3 times in this window, pick a different identifying snippet or skip the variable.
        - Snippet length: 1–60 characters strongly preferred, never more than 200.
        - Skip section headings, legal boilerplate, signatures and anything that is not a real variable.
        - Keys MUST be unique within your response AND must NOT appear in the exclusion list above. Use English hyphen-case keys even when the label is in another language.
        - For dates use "date". For monetary amounts use "number". For multi-line free text use "textarea". For comma/bullet lists use "list".
        - DO NOT include any other keys.
        - Return ONLY the JSON array. No prose. No markdown fences. No trailing commas.

        Window:
        ---
        {$docText}
        ---
        PROMPT;

        $messages = [
            ['role' => 'system', 'content' => 'You convert draft Word documents into reusable templates by proposing placeholder variables. Always return a single compact JSON array — no prose, no markdown fences.'],
            ['role' => 'user',   'content' => $prompt],
        ];
        $aiOptions = $this->resolveAiModelOptions($userId);
        // Very generous cap — JSON of 12 detailed objects + reasoning
        // tokens on o-series models still has headroom here.
        $aiOptions['max_tokens'] = 16000;

        $recoveredTruncated = false;
        try {
            $result = $this->aiFacade->chat($messages, $userId, $aiOptions);
            $aiContent = (string) ($result['content'] ?? '');
            $modelUsed = (string) ($result['model'] ?? 'unknown');
        } catch (\Throwable $e) {
            $this->logger->error('Synaform proposeVariablesPass: chat call failed', [
                'window' => $windowIndex,
                'of' => $totalWindows,
                'err' => $e->getMessage(),
            ]);

            return [
                'suggestions' => [],
                'raw_count' => 0,
                'model' => 'unknown',
                'response_len' => 0,
                'recovered_truncated' => false,
            ];
        }

        $parsed = $this->parseJsonFromAiResponse($aiContent);
        if (null === $parsed) {
            // Try partial recovery for truncated responses.
            $recovered = $this->recoverTruncatedJsonArray($aiContent);
            if (is_array($recovered) && !empty($recovered)) {
                $parsed = $recovered;
                $recoveredTruncated = true;
                $this->logger->info('Synaform proposeVariablesPass: partial recovery', [
                    'window' => $windowIndex,
                    'of' => $totalWindows,
                    'recovered' => count($recovered),
                ]);
            }
        }
        if (!is_array($parsed)) {
            $this->logger->warning('Synaform proposeVariablesPass: unparseable response', [
                'window' => $windowIndex,
                'of' => $totalWindows,
                'model' => $modelUsed,
                'response_len' => strlen($aiContent),
                'preview_head' => mb_substr($aiContent, 0, 400),
            ]);

            return [
                'suggestions' => [],
                'raw_count' => 0,
                'model' => $modelUsed,
                'response_len' => strlen($aiContent),
                'recovered_truncated' => false,
            ];
        }

        $rawSuggestions = isset($parsed[0]) ? $parsed : ($parsed['suggestions'] ?? ($parsed['fields'] ?? []));
        $rawCount = is_array($rawSuggestions) ? count($rawSuggestions) : 0;
        $normalised = $this->normalizeAiSuggestionsFromDocx($rawSuggestions, $sourceText);

        // Strip out anything that would shadow an already-accepted key.
        $filtered = array_values(array_filter(
            $normalised,
            static fn (array $s): bool => !isset($excludedKeys[$s['key']]),
        ));

        return [
            'suggestions' => $filtered,
            'raw_count' => $rawCount,
            'model' => $modelUsed,
            'response_len' => strlen($aiContent),
            'recovered_truncated' => $recoveredTruncated,
        ];
    }

    /**
     * Stage 3 — deterministic verification + repair of every suggestion's
     * source_text against the actual document. The proposal stage already
     * dropped obvious hallucinations (where the snippet wasn't present at
     * all) via normalizeAiSuggestionsFromDocx; this stage does the
     * inverse: when an exact match exists but the casing or whitespace
     * differs from what the model echoed back, we REPAIR the snippet to
     * use the document's verbatim text so applyPlaceholdersToDocx() can
     * actually substitute.
     *
     * Match strategies in priority order:
     *   1. Exact substring                   — accept as-is
     *   2. Whitespace-collapsed match        — repair to verbatim
     *   3. Case-insensitive match            — repair to verbatim
     *   4. Case + whitespace-insensitive     — repair to verbatim
     *
     * Anything still unlocatable is dropped (counted under `dropped`).
     *
     * @param list<array<string, mixed>> $suggestions
     *
     * @return array{
     *   suggestions: list<array<string, mixed>>,
     *   dropped: int,
     *   repaired: int,
     * }
     */
    private function refineSuggestionSnippets(array $suggestions, string $sourceText): array
    {
        $kept = [];
        $dropped = 0;
        $repaired = 0;

        // Pre-compute helpers for the whitespace-collapsed search.
        $docCollapsedLower = function_exists('mb_strtolower')
            ? mb_strtolower(preg_replace('/\s+/u', ' ', $sourceText) ?? $sourceText, 'UTF-8')
            : strtolower(preg_replace('/\s+/u', ' ', $sourceText) ?? $sourceText);

        foreach ($suggestions as $s) {
            $snippet = is_string($s['source_text'] ?? null) ? (string) $s['source_text'] : '';
            if ('' === $snippet) {
                ++$dropped;
                continue;
            }

            // 1. Exact match — keep verbatim.
            if (str_contains($sourceText, $snippet)) {
                $kept[] = $s;
                continue;
            }

            // 2. Whitespace-collapsed match. Try to find the snippet in
            //    the document after collapsing runs of whitespace; when
            //    we find a hit, recover the verbatim original.
            $snippetCollapsed = preg_replace('/\s+/u', ' ', $snippet) ?? $snippet;
            $verbatim = $this->findVerbatimMatch($sourceText, $snippetCollapsed, false);
            if (null !== $verbatim) {
                $s['source_text'] = $verbatim;
                $s['repaired_from'] = $snippet;
                $kept[] = $s;
                ++$repaired;
                continue;
            }

            // 3. Case + whitespace-insensitive match.
            $verbatim = $this->findVerbatimMatch($sourceText, $snippetCollapsed, true);
            if (null !== $verbatim) {
                $s['source_text'] = $verbatim;
                $s['repaired_from'] = $snippet;
                $kept[] = $s;
                ++$repaired;
                continue;
            }

            // Couldn't locate — drop. We log at info so it's findable when
            // someone wonders why a proposed variable didn't show up.
            $this->logger->info('Synaform refine: dropping unlocatable suggestion', [
                'key' => $s['key'] ?? '?',
                'snippet' => mb_substr($snippet, 0, 80),
            ]);
            ++$dropped;
        }

        return [
            'suggestions' => $kept,
            'dropped' => $dropped,
            'repaired' => $repaired,
        ];
    }

    /**
     * Search the source text for a contiguous run of words matching
     * $snippetCollapsed (whitespace already collapsed to single spaces).
     * When found, return the verbatim slice from the original document
     * (preserving its actual whitespace / casing) so the caller can use
     * it as the canonical source_text for placeholder substitution.
     *
     * Returns null when no match exists.
     */
    private function findVerbatimMatch(string $sourceText, string $snippetCollapsed, bool $caseInsensitive): ?string
    {
        $snippetCollapsed = trim($snippetCollapsed);
        if ('' === $snippetCollapsed) {
            return null;
        }

        // Build a regex where every internal space in the snippet
        // matches any whitespace run in the source.
        $pattern = preg_replace_callback(
            '/\s+/u',
            static fn (): string => '\s+',
            preg_quote($snippetCollapsed, '#'),
        ) ?? preg_quote($snippetCollapsed, '#');
        $flags = 'u';
        if ($caseInsensitive) {
            $flags .= 'i';
        }
        $regex = '#'.$pattern.'#'.$flags;

        if (1 === preg_match($regex, $sourceText, $m)) {
            return $m[0];
        }

        return null;
    }

    /**
     * Recover whatever complete JSON objects we can from a truncated AI
     * response. The common failure mode for the "suggest from docx" flow
     * is the model running out of tokens mid-array, leaving something like
     * `[ {...}, {...}, {"key": "x", "lab` with no closing bracket. The
     * standard parser refuses unclosed JSON, but the objects BEFORE the
     * cutoff are still valid and useful.
     *
     * Strategy: find every balanced top-level `{...}` block inside the
     * outer (unclosed) `[` and decode each independently. Returns a list
     * of decoded objects, or null when nothing usable can be salvaged.
     *
     * @return list<array<string, mixed>>|null
     */
    private function recoverTruncatedJsonArray(string $content): ?array
    {
        $content = trim($content);
        // Strip common decoration that parseJsonFromAiResponse normally
        // handles (markdown fences, harmony channel markers) so the same
        // input reaches us cleaned up the same way.
        $content = preg_replace('/<\|[^|]+\|>/u', '', $content) ?? $content;
        $content = trim($content);
        if (preg_match('/```(?:json|JSON)?\s*\n?([\s\S]*?)$/u', $content, $m)) {
            $candidate = trim($m[1]);
            if ('' !== $candidate) {
                $content = $candidate;
            }
        }
        if (!str_starts_with($content, '[')) {
            return null;
        }

        $objects = [];
        $depth = 0;
        $start = -1;
        $inString = false;
        $escape = false;
        $len = strlen($content);
        for ($i = 1; $i < $len; ++$i) {
            $c = $content[$i];
            if ($escape) {
                $escape = false;
                continue;
            }
            if ('\\' === $c) {
                $escape = true;
                continue;
            }
            if ('"' === $c) {
                $inString = !$inString;
                continue;
            }
            if ($inString) {
                continue;
            }
            if ('{' === $c) {
                if (0 === $depth) {
                    $start = $i;
                }
                ++$depth;
            } elseif ('}' === $c) {
                --$depth;
                if (0 === $depth && $start >= 0) {
                    $blob = substr($content, $start, $i - $start + 1);
                    $decoded = json_decode($blob, true);
                    if (is_array($decoded)) {
                        $objects[] = $decoded;
                    }
                    $start = -1;
                }
            }
        }

        return empty($objects) ? null : $objects;
    }

    /**
     * Normalise the raw AI output for the suggest-from-docx flow. Drops items
     * with missing/duplicate keys or source_text that isn't actually present
     * in the document, coerces fields to canonical values, and de-duplicates
     * by key.
     *
     * @param mixed  $raw        AI-returned array of items
     * @param string $sourceText the original document text (used to validate
     *                           that source_text really occurs there)
     *
     * @return list<array{
     *   key: string, label: string, type: string, source: string,
     *   source_text: string, hint: ?string,
     * }>
     */
    private function normalizeAiSuggestionsFromDocx(mixed $raw, string $sourceText): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = is_string($item['key'] ?? null) ? trim((string) $item['key']) : '';
            $key = strtolower($key);
            $key = preg_replace('/[^a-z0-9\-]+/', '-', $key) ?? '';
            $key = trim($key, '-');
            if ('' === $key || isset($seen[$key])) {
                continue;
            }
            $sourceTextItem = is_string($item['source_text'] ?? null) ? trim((string) $item['source_text']) : '';
            if ('' === $sourceTextItem || strlen($sourceTextItem) > 200) {
                continue;
            }
            // Validate that the AI didn't hallucinate the substring. If the
            // exact match isn't present we skip — there's no point promising
            // an applied placeholder we can't actually insert.
            if (!str_contains($sourceText, $sourceTextItem)) {
                continue;
            }
            $type = $this->normalizeFieldType((string) ($item['type'] ?? 'text'));
            $source = $this->normalizeSource($item['source'] ?? 'form') ?? 'form';
            $label = is_string($item['label'] ?? null) && '' !== trim($item['label'])
                ? trim((string) $item['label'])
                : ucwords(str_replace('-', ' ', $key));
            $hint = isset($item['hint']) && is_string($item['hint']) && '' !== trim($item['hint'])
                ? trim((string) $item['hint'])
                : null;

            $out[] = [
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'source' => $source,
                'source_text' => $sourceTextItem,
                'hint' => $hint,
                'placeholder' => '{{'.$key.'}}',
                'applied' => false,
                'applied_reason' => null,
            ];
            $seen[$key] = true;
        }

        return $out;
    }

    /**
     * Best-effort placeholder injection: for every suggestion that carries a
     * `source_text`, locate the paragraph (across body + headers + footers)
     * whose concatenated text contains that snippet and replace it with the
     * `{{key}}` placeholder.
     *
     * Strategy per paragraph match:
     *   1. If the snippet sits entirely inside a single `<w:t>` text node,
     *      do an in-place text replace there (keeps surrounding formatting
     *      perfectly intact).
     *   2. Otherwise collapse all of the paragraph's runs into a single run
     *      (using the first run's formatting), then do the text replace.
     *      This trades inline formatting for being able to bridge text that
     *      Word split across runs (formatting changes, spell-check, …).
     *
     * Each suggestion is mutated in-place to record whether the replacement
     * succeeded (`applied`) and, if not, why (`applied_reason`).
     *
     * @param array<int, array<string, mixed>> $suggestions
     *
     * @return array<int, array<string, mixed>>
     */
    private function applyPlaceholdersToDocx(string $docxPath, array $suggestions): array
    {
        if (empty($suggestions)) {
            return $suggestions;
        }
        $zip = new \ZipArchive();
        if (true !== $zip->open($docxPath)) {
            foreach ($suggestions as &$s) {
                $s['applied'] = false;
                $s['applied_reason'] = 'could_not_open_docx';
            }
            unset($s);

            return $suggestions;
        }

        try {
            $parts = $this->collectDocumentPartNames($zip);
            foreach ($parts as $partName) {
                $xml = $zip->getFromName($partName);
                if (false === $xml) {
                    continue;
                }

                $changed = false;
                foreach ($suggestions as $idx => $s) {
                    if (!empty($s['applied'])) {
                        continue;
                    }
                    $result = $this->replaceSnippetInWordXml($xml, (string) $s['source_text'], (string) $s['placeholder']);
                    if ($result['applied']) {
                        $xml = $result['xml'];
                        $suggestions[$idx]['applied'] = true;
                        $suggestions[$idx]['applied_reason'] = $result['reason'];
                        $changed = true;
                    }
                }

                if ($changed) {
                    $zip->deleteName($partName);
                    $zip->addFromString($partName, $xml);
                }
            }
        } finally {
            $zip->close();
        }

        // Anything still unapplied: record a stable reason so the UI can hint
        // the user that they'll need to add this placeholder manually.
        foreach ($suggestions as $idx => $s) {
            if (empty($s['applied']) && empty($s['applied_reason'])) {
                $suggestions[$idx]['applied_reason'] = 'snippet_not_locatable_in_xml';
            }
        }

        return $suggestions;
    }

    /**
     * Replace `$snippet` with `$placeholder` inside a single Word XML part
     * (document.xml / headerN.xml / footerN.xml).
     *
     * @return array{applied: bool, reason: ?string, xml: string}
     */
    private function replaceSnippetInWordXml(string $xml, string $snippet, string $placeholder): array
    {
        if ('' === $snippet) {
            return ['applied' => false, 'reason' => 'empty_snippet', 'xml' => $xml];
        }

        // Try the easy case first: snippet sits inside a single <w:t> node.
        // We walk text nodes manually to avoid an expensive DOM round-trip.
        if (0 === preg_match_all('#<w:t(?:\s[^>]*)?>([^<]*)</w:t>#u', $xml, $matches, PREG_OFFSET_CAPTURE)) {
            return ['applied' => false, 'reason' => 'no_text_nodes', 'xml' => $xml];
        }

        $needleEscaped = htmlspecialchars($snippet, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $replacementEscaped = htmlspecialchars($placeholder, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        foreach ($matches[1] as $m) {
            $text = (string) $m[0];
            if ('' !== $text && str_contains($text, $needleEscaped)) {
                $offset = (int) $m[1];
                $len = strlen($text);
                $newText = substr_replace($text, str_replace($needleEscaped, $replacementEscaped, $text), 0, $len);
                $xmlOut = substr_replace($xml, $newText, $offset, $len);

                return ['applied' => true, 'reason' => 'in_single_run', 'xml' => $xmlOut];
            }
        }

        // Fall back to paragraph collapse for snippets that span multiple
        // runs (formatting boundaries, spell-check rewrites, …). We scan
        // each <w:p>, concatenate its run text, look for the snippet, and
        // if found rebuild the paragraph with one collapsed run.
        $offset = 0;
        $changedXml = '';
        $lastEnd = 0;
        $applied = false;
        while (preg_match('#<w:p\b[^>]*>.*?</w:p>#us', $xml, $pm, PREG_OFFSET_CAPTURE, $offset)) {
            $paraXml = $pm[0][0];
            $paraStart = (int) $pm[0][1];
            $paraEnd = $paraStart + strlen($paraXml);

            $paraText = $this->concatParagraphText($paraXml);
            if ('' !== $paraText && str_contains($paraText, $snippet)) {
                $newParaXml = $this->collapseParagraphAndReplace($paraXml, $snippet, $placeholder);
                if (null !== $newParaXml && $newParaXml !== $paraXml) {
                    $changedXml .= substr($xml, $lastEnd, $paraStart - $lastEnd).$newParaXml;
                    $lastEnd = $paraEnd;
                    $applied = true;
                    $offset = $paraEnd;
                    continue;
                }
            }
            $offset = $paraEnd;
        }

        if ($applied) {
            $changedXml .= substr($xml, $lastEnd);

            return ['applied' => true, 'reason' => 'paragraph_collapsed', 'xml' => $changedXml];
        }

        return ['applied' => false, 'reason' => 'snippet_not_found', 'xml' => $xml];
    }

    /**
     * Pull the readable text out of a `<w:p>` paragraph fragment by joining
     * every `<w:t>` text node it contains. Entity-decodes so the result is
     * directly comparable to a plain-text snippet.
     */
    private function concatParagraphText(string $paragraphXml): string
    {
        if (0 === preg_match_all('#<w:t(?:\s[^>]*)?>([^<]*)</w:t>#u', $paragraphXml, $matches)) {
            return '';
        }
        $text = '';
        foreach ($matches[1] as $chunk) {
            $text .= html_entity_decode((string) $chunk, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        }

        return $text;
    }

    /**
     * Rebuild a paragraph so its text content equals `originalText` with
     * `$snippet` replaced by `$placeholder`. All <w:r> runs are collapsed
     * into a single run using the FIRST run's `<w:rPr>` (so the paragraph
     * keeps its dominant formatting). Non-run paragraph properties
     * (`<w:pPr>`) are preserved.
     *
     * Returns null when the paragraph has no runs (nothing we can safely
     * rewrite) or when the snippet isn't actually in the concatenated text.
     */
    private function collapseParagraphAndReplace(string $paragraphXml, string $snippet, string $placeholder): ?string
    {
        $text = $this->concatParagraphText($paragraphXml);
        if ('' === $text || !str_contains($text, $snippet)) {
            return null;
        }
        $newText = str_replace($snippet, $placeholder, $text);
        $newTextEscaped = htmlspecialchars($newText, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        // Preserve <w:pPr> if present (paragraph properties: alignment, style…).
        $pPr = '';
        if (preg_match('#<w:pPr\b[^>]*>.*?</w:pPr>#us', $paragraphXml, $pm)) {
            $pPr = $pm[0];
        } elseif (preg_match('#<w:pPr\b[^/>]*/>#u', $paragraphXml, $pm)) {
            $pPr = $pm[0];
        }

        // Take the first run's <w:rPr> as the collapsed run's formatting.
        $rPr = '';
        if (preg_match('#<w:r\b[^>]*>(.*?)</w:r>#us', $paragraphXml, $rm)) {
            $runInner = $rm[1];
            if (preg_match('#<w:rPr\b[^>]*>.*?</w:rPr>#us', $runInner, $rpm)) {
                $rPr = $rpm[0];
            } elseif (preg_match('#<w:rPr\b[^/>]*/>#u', $runInner, $rpm)) {
                $rPr = $rpm[0];
            }
        }

        // Extract <w:p ...> opening tag verbatim so we keep namespaces / ids.
        if (!preg_match('#<w:p\b[^>]*>#u', $paragraphXml, $openMatch)) {
            return null;
        }
        $openTag = $openMatch[0];

        // `xml:space="preserve"` on <w:t> keeps leading/trailing whitespace
        // (e.g. snippets that include the surrounding space).
        $newRun = '<w:r>'.$rPr.'<w:t xml:space="preserve">'.$newTextEscaped.'</w:t></w:r>';

        return $openTag.$pPr.$newRun.'</w:p>';
    }

    private function extractElementText(mixed $element): string
    {
        if (method_exists($element, 'getText')) {
            $t = $element->getText();

            return is_string($t) ? $t : '';
        }

        if (method_exists($element, 'getElements')) {
            $parts = [];
            foreach ($element->getElements() as $child) {
                $parts[] = $this->extractElementText($child);
            }

            return implode(' ', array_filter($parts));
        }

        if (method_exists($element, 'getRows')) {
            $rows = [];
            foreach ($element->getRows() as $row) {
                $cells = [];
                foreach ($row->getCells() as $cell) {
                    $cellParts = [];
                    foreach ($cell->getElements() as $cellElement) {
                        $cellParts[] = $this->extractElementText($cellElement);
                    }
                    $cells[] = implode(' ', array_filter($cellParts));
                }
                $rows[] = implode("\t", $cells);
            }

            return implode("\n", $rows);
        }

        return '';
    }

    private function normalizeFieldType(string $type): string
    {
        $valid = ['text', 'textarea', 'select', 'list', 'date', 'number', 'checkbox', 'table', 'image'];

        return in_array($type, $valid, true) ? $type : 'text';
    }

    /**
     * Normalize the optional `designer` sub-object on a form field. This carries
     * layout-level configuration that drives how the generator emits lists,
     * tables and checkboxes:
     *
     *   list:
     *     list_style        ul | ol                (default ul)
     *     prevent_orphans   bool                   (default false)
     *   table:
     *     repeat_header     bool                   (default true when a header row exists)
     *     prevent_row_break bool                   (default true — rows stay on one page)
     *     keep_with_prev    bool                   (default false)
     *   checkbox:
     *     checked_glyph     string (single char)   (default "☒")
     *     unchecked_glyph   string (single char)   (default "☐")
     *
     * Unknown keys are dropped silently to keep plugin_data clean.
     *
     * @param array<string, mixed>|null $raw
     *
     * @return array<string, mixed>
     */
    private function normalizeDesignerConfig(?array $raw, string $fieldType): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        if ('list' === $fieldType) {
            $style = isset($raw['list_style']) ? strtolower((string) $raw['list_style']) : '';
            if (in_array($style, ['ul', 'ol'], true)) {
                $out['list_style'] = $style;
            }
            if (array_key_exists('prevent_orphans', $raw)) {
                $out['prevent_orphans'] = (bool) $raw['prevent_orphans'];
            }
            if (array_key_exists('top_blank_line', $raw)) {
                $out['top_blank_line'] = (bool) $raw['top_blank_line'];
            }
            if (array_key_exists('bottom_blank_line', $raw)) {
                $out['bottom_blank_line'] = (bool) $raw['bottom_blank_line'];
            }
        } elseif ('table' === $fieldType) {
            if (array_key_exists('repeat_header', $raw)) {
                $out['repeat_header'] = (bool) $raw['repeat_header'];
            }
            if (array_key_exists('prevent_row_break', $raw)) {
                $out['prevent_row_break'] = (bool) $raw['prevent_row_break'];
            }
            if (array_key_exists('keep_with_prev', $raw)) {
                $out['keep_with_prev'] = (bool) $raw['keep_with_prev'];
            }
        } elseif ('checkbox' === $fieldType) {
            if (isset($raw['checked_glyph']) && is_string($raw['checked_glyph']) && '' !== $raw['checked_glyph']) {
                $out['checked_glyph'] = mb_substr((string) $raw['checked_glyph'], 0, 4);
            }
            if (isset($raw['unchecked_glyph']) && is_string($raw['unchecked_glyph']) && '' !== $raw['unchecked_glyph']) {
                $out['unchecked_glyph'] = mb_substr((string) $raw['unchecked_glyph'], 0, 4);
            }
            if (array_key_exists('clickable_checkbox', $raw)) {
                // Default behaviour is now "clickable" (real Word content
                // controls). Persist false explicitly when the designer
                // opted out so the renderer falls back to static glyphs.
                $out['clickable_checkbox'] = (bool) $raw['clickable_checkbox'];
            }
            if (isset($raw['yes_label']) && is_string($raw['yes_label'])) {
                $out['yes_label'] = mb_substr($raw['yes_label'], 0, 32);
            }
            if (isset($raw['no_label']) && is_string($raw['no_label'])) {
                $out['no_label'] = mb_substr($raw['no_label'], 0, 32);
            }
        } elseif ('image' === $fieldType) {
            if (isset($raw['width'])) {
                $out['width'] = max(16, min(1600, (int) $raw['width']));
            }
            if (isset($raw['height'])) {
                $out['height'] = max(16, min(2000, (int) $raw['height']));
            }
            if (array_key_exists('preserve_ratio', $raw)) {
                $out['preserve_ratio'] = (bool) $raw['preserve_ratio'];
            }
            // Positioning: "inline" (default — image replaces the placeholder
            // in the text flow) or "float" (anchored to the page margin, text
            // wraps around it — e.g. profile photo in the upper right corner).
            $placement = isset($raw['placement']) ? strtolower((string) $raw['placement']) : '';
            if (in_array($placement, ['inline', 'float'], true)) {
                $out['placement'] = $placement;
            }
            $floatH = isset($raw['float_h']) ? strtolower((string) $raw['float_h']) : '';
            if (in_array($floatH, ['left', 'center', 'right'], true)) {
                $out['float_h'] = $floatH;
            }
            $floatV = isset($raw['float_v']) ? strtolower((string) $raw['float_v']) : '';
            if (in_array($floatV, ['top', 'middle', 'bottom'], true)) {
                $out['float_v'] = $floatV;
            }
            $wrap = isset($raw['wrap']) ? (string) $raw['wrap'] : '';
            if (in_array($wrap, ['square', 'topAndBottom', 'none'], true)) {
                $out['wrap'] = $wrap;
            }
        }

        return $out;
    }

    /**
     * Normalize a list of field definitions, stripping unknown keys and
     * coercing the `designer` object to its per-type schema. Returns a fresh
     * array so caller stores a canonical representation.
     *
     * @param array<int, array<string, mixed>> $fields
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalizeFields(array $fields): array
    {
        $out = [];
        foreach ($fields as $raw) {
            if (!is_array($raw) || empty($raw['key'])) {
                continue;
            }
            $type = $this->normalizeFieldType((string) ($raw['type'] ?? 'text'));
            $field = [
                'key' => (string) $raw['key'],
                'label' => (string) ($raw['label'] ?? $raw['key']),
                'type' => $type,
                'required' => (bool) ($raw['required'] ?? false),
                'source' => $this->normalizeSource($raw['source'] ?? 'form') ?? 'form',
            ];
            $fallback = $this->normalizeSource($raw['fallback'] ?? null);
            if (null !== $fallback) {
                $field['fallback'] = $fallback;
            }
            if (!empty($raw['hint'])) {
                $field['hint'] = (string) $raw['hint'];
            }
            // Rich extraction context (v3.7.2+). All optional, all
            // additive: existing forms keep working unchanged. These
            // properties feed the per-group extraction prompt with
            // semantic disambiguation that label-only forms can't
            // provide ("target_position is the role being applied
            // for, NOT the candidate's current job title").
            if (!empty($raw['description'])) {
                $field['description'] = (string) $raw['description'];
            }
            if (!empty($raw['negative_hint'])) {
                $field['negative_hint'] = (string) $raw['negative_hint'];
            }
            if (!empty($raw['examples']) && is_array($raw['examples'])) {
                $field['examples'] = array_values(array_filter(
                    array_map(static fn ($e) => is_string($e) ? trim($e) : (string) $e, $raw['examples']),
                    static fn ($e) => '' !== $e
                ));
                if (count($field['examples']) > 6) {
                    $field['examples'] = array_slice($field['examples'], 0, 6);
                }
            }
            if ('select' === $type && !empty($raw['options']) && is_array($raw['options'])) {
                $field['options'] = array_values(array_filter(array_map(static fn ($o) => is_string($o) ? $o : (string) $o, $raw['options'])));
            }
            if ('table' === $type && !empty($raw['columns']) && is_array($raw['columns'])) {
                $cols = [];
                $validColumnTypes = ['text', 'textarea', 'list', 'date', 'number'];
                foreach ($raw['columns'] as $col) {
                    if (!is_array($col) || empty($col['key'])) {
                        continue;
                    }
                    $colType = (string) ($col['type'] ?? 'text');
                    if (!in_array($colType, $validColumnTypes, true)) {
                        $colType = 'text';
                    }
                    $colOut = [
                        'key' => (string) $col['key'],
                        'label' => (string) ($col['label'] ?? $col['key']),
                        'type' => $colType,
                    ];
                    // List columns carry an explicit rendering mode:
                    // structured (date header + title + task bullets) vs
                    // flat (every item is a bullet). An explicit false must
                    // survive normalization — it's how the user opts a
                    // `details` column OUT of the legacy structured default.
                    if ('list' === $colType && array_key_exists('structured', $col)) {
                        $colOut['structured'] = (bool) $col['structured'];
                    }
                    $cols[] = $colOut;
                }
                if (!empty($cols)) {
                    $field['columns'] = $cols;
                }
            }
            $designer = $this->normalizeDesignerConfig($raw['designer'] ?? null, $type);
            if (!empty($designer)) {
                $field['designer'] = $designer;
            }
            $out[] = $field;
        }

        return $out;
    }

    private function getTableFieldMeta(array $formFields): object
    {
        $meta = [];
        foreach ($formFields as $field) {
            if (($field['type'] ?? '') === 'table' && !empty($field['key'])) {
                $meta[$field['key']] = [
                    'label' => $field['label'] ?? $field['key'],
                    'columns' => $field['columns'] ?? [],
                    'designer' => $field['designer'] ?? (object) [],
                ];
            }
        }

        return (object) $meta;
    }

    /**
     * Build an index of designer configs keyed by field key. Used by the DOCX
     * generator so list/table/checkbox rendering can honour per-variable
     * settings without repeatedly walking the fields[] array.
     *
     * @return array<string, array<string, mixed>>
     */
    private function getDesignerConfigMap(array $formFields): array
    {
        $out = [];
        foreach ($formFields as $field) {
            $key = $field['key'] ?? '';
            if ('' === $key) {
                continue;
            }
            $designer = $field['designer'] ?? null;
            if (is_array($designer) && !empty($designer)) {
                $designer['_type'] = $field['type'] ?? 'text';
                $out[$key] = $designer;
            } elseif (!empty($field['type'])) {
                // Even empty designer: record type so generator can know it's a list/table
                $out[$key] = ['_type' => $field['type']];
            }
        }

        return $out;
    }

    /**
     * Group the raw placeholder list coming out of extractPlaceholders() into
     * ready-to-apply form fields:
     *
     *  - `block_marker` ({{#…}}/{{/…}})   → skipped (structural only)
     *  - `row_field` (`group.col.N`)      → collapsed into ONE `table` field per group, with columns = unique cols
     *  - `checkbox` (`checkb.X.yes|no`)   → collapsed into ONE `checkbox` field named `X` with default glyphs
     *  - `list` (ends with `list`)        → `list` field
     *  - everything else                  → `text` field
     *
     * Also classifies each entry as `new`, `duplicate` (already in the form),
     * or `structural` (skipped) so the UI can show a preview with pre-unchecked
     * duplicates.
     *
     * @return array{fields: list<array<string, mixed>>, summary: array<string, int>}
     */
    /**
     * True when a placeholder key names a photo/image slot. Matches whole
     * tokens (split on ._- and digits) so German compounds like
     * "ausbildung" (contains "bild") are NOT misclassified.
     */
    private function keyLooksLikeImage(string $key): bool
    {
        $imageTokens = ['photo', 'foto', 'picture', 'image', 'img', 'portrait', 'portraet', 'bild', 'headshot', 'profilbild', 'lichtbild'];
        $tokens = preg_split('/[^a-z]+/', strtolower($key), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return [] !== array_intersect($tokens, $imageTokens);
    }

    private function buildVariableSuggestions(array $placeholders, array $existingKeys): array
    {
        $fields = [];
        $summary = ['new' => 0, 'duplicate' => 0, 'structural' => 0, 'tables' => 0, 'checkboxes' => 0, 'lists' => 0, 'texts' => 0, 'images' => 0];

        $rowGroups = [];   // group => [col => true]
        $checkGroups = []; // key => [yes => true, no => true]

        foreach ($placeholders as $ph) {
            $key = $ph['key'] ?? '';
            $type = $ph['type'] ?? '';
            if ('' === $key) {
                continue;
            }

            if ('block_marker' === $type) {
                ++$summary['structural'];
                continue;
            }

            if ('checkbox' === $type && str_starts_with($key, 'checkb.')) {
                $parts = explode('.', $key);
                if (count($parts) >= 3) {
                    $grp = $parts[1];
                    $leaf = end($parts);
                    $checkGroups[$grp][$leaf] = true;
                    continue;
                }
            }

            if ('row_field' === $type && str_contains($key, '.')) {
                $segs = explode('.', $key);
                if (count($segs) >= 3) {
                    $group = $segs[0];
                    $col = $segs[1];
                    $rowGroups[$group][$col] = true;
                    continue;
                }
            }

            $suggestedType = 'list' === $type ? 'list' : 'text';
            // Placeholders named like a photo ({{picture}}, {{candidate_photo}},
            // {{foto}}, ...) become image variables so the profile-photo
            // auto-extraction and DOCX image embedding kick in out of the box.
            // Token match (not substring) so e.g. "ausbildung" stays text.
            if ('text' === $suggestedType && $this->keyLooksLikeImage($key)) {
                $suggestedType = 'image';
            }
            $field = [
                'key' => $key,
                'label' => $this->humanizeKey($key),
                'type' => $suggestedType,
                'required' => false,
                'source' => 'form',
                '_status' => isset($existingKeys[$key]) ? 'duplicate' : 'new',
            ];
            if ('list' === $field['type']) {
                ++$summary['lists'];
            } elseif ('image' === $field['type']) {
                ++$summary['images'];
            } else {
                ++$summary['texts'];
            }
            ++$summary[$field['_status']];
            $fields[] = $field;
        }

        // Column names commonly holding bullet lists get suggested as type=list
        // by default. The user can override in the import preview. This matches
        // how HR profile templates typically use these columns.
        $listColumnHeuristics = ['details', 'highlights', 'achievements', 'responsibilities', 'bullets'];

        foreach ($rowGroups as $group => $cols) {
            $columns = [];
            foreach (array_keys($cols) as $c) {
                $type = in_array(strtolower($c), $listColumnHeuristics, true) ? 'list' : 'text';
                $columns[] = ['key' => $c, 'label' => $this->humanizeKey($c), 'type' => $type];
            }
            $field = [
                'key' => $group,
                'label' => $this->humanizeKey($group),
                'type' => 'table',
                'required' => false,
                'source' => 'form',
                'columns' => $columns,
                // Auto-detected row-group tables come from a single clone-row
                // template, i.e. they are data-first with NO header row. Forcing
                // a repeating header would stamp <w:tblHeader/> onto the first
                // entry (it then reappears atop every page, undeletable) and
                // forcing cantSplit wastes page space. Both default OFF; an author
                // with a real header row can opt in via the designer.
                'designer' => ['repeat_header' => false, 'prevent_row_break' => false],
                '_status' => isset($existingKeys[$group]) ? 'duplicate' : 'new',
            ];
            ++$summary['tables'];
            ++$summary[$field['_status']];
            $fields[] = $field;
        }

        foreach ($checkGroups as $grp => $leaves) {
            $field = [
                'key' => $grp,
                'label' => $this->humanizeKey($grp),
                'type' => 'checkbox',
                'required' => false,
                'source' => 'form',
                'designer' => ['checked_glyph' => '☒', 'unchecked_glyph' => '☐'],
                '_status' => isset($existingKeys[$grp]) ? 'duplicate' : 'new',
            ];
            ++$summary['checkboxes'];
            ++$summary[$field['_status']];
            $fields[] = $field;
        }

        usort($fields, static function (array $a, array $b): int {
            $order = ['text' => 0, 'list' => 1, 'checkbox' => 2, 'table' => 3];
            $ra = $order[$a['type']] ?? 9;
            $rb = $order[$b['type']] ?? 9;

            return $ra === $rb ? strcmp($a['key'], $b['key']) : $ra <=> $rb;
        });

        return ['fields' => $fields, 'summary' => $summary];
    }

    /**
     * Turn a snake_case / camelCase / dotted / hyphenated key into a human
     * readable label: `current_annual_salary` → "Current annual salary",
     * `targetPosition` → "Target position", `stations.employer` → "Stations employer".
     */
    private function humanizeKey(string $key): string
    {
        $s = preg_replace('/[._-]+/', ' ', $key) ?? $key;
        $s = preg_replace('/([a-z])([A-Z])/', '$1 $2', $s) ?? $s;
        $s = trim($s);
        if ('' === $s) {
            return $key;
        }

        return mb_strtoupper(mb_substr($s, 0, 1)).mb_substr($s, 1);
    }

    private function normalizeSource(?string $source): ?string
    {
        if (null === $source || '' === $source) {
            return null;
        }
        $valid = ['form', 'ai'];

        return in_array($source, $valid, true) ? $source : 'form';
    }

    /**
     * Clean up the rows of the `stations` row-group AFTER they come back from
     * AI extraction (or arrive via override / form data). Two cleanups, both
     * idempotent and defensive:
     *
     *   1. Strip a "duplicate-prefix" from `details`. The extraction model
     *      sometimes parrots the outer `time` and/or `position` back as the
     *      first line(s) of `details`, which then renders twice in the
     *      output Word document (once as the row header, once as the lead
     *      of details). We drop any leading non-empty lines of `details`
     *      that exactly match — case-insensitively, whitespace-collapsed —
     *      the row's own `time`, `position`, OR any slash/comma-separated
     *      chunk of `position`.
     *
     *   2. Insert a blank line before sub-period date-headers inside
     *      `details`. A line that looks like a date header (e.g.
     *      "MM/YYYY – MM/YYYY", "MM/YYYY -- heute", "since MM/YYYY",
     *      "YYYY – YYYY") gets prefixed with a blank line unless it's
     *      already the first line of details or already preceded by an
     *      empty line. This is what visually separates consecutive
     *      sub-periods inside one station.
     *
     * @param array<int|string, mixed> $stations array of row objects
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalizeStationsRows(array $stations): array
    {
        $out = [];
        foreach ($stations as $row) {
            if (!is_array($row)) {
                continue;
            }
            $time = isset($row['time']) && is_string($row['time']) ? trim($row['time']) : '';
            $position = isset($row['position']) && is_string($row['position']) ? trim($row['position']) : '';
            $details = isset($row['details']) && is_string($row['details']) ? $row['details'] : '';

            if ('' !== $details) {
                $details = $this->stripStationDetailsDuplicatePrefix($details, $time, $position);
                $details = $this->ensureBlankLinesBeforeSubPeriods($details);
                $row['details'] = $details;
            }
            if ('' !== $time) {
                $row['time'] = $this->normalizeStationTime($time);
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Normalize a station's `time` range to the requested house format
     * "MM/YYYY – MM/YYYY" (two-digit month, four-digit year, an en dash with a
     * single space either side) and "MM/YYYY – heute" for ongoing roles.
     *
     * Year-only ranges (when no month is known) are kept as "YYYY – YYYY".
     * Anything we can't confidently parse is returned unchanged so we never
     * mangle an unusual but deliberate label. Idempotent — running it again on
     * already-formatted input is a no-op.
     */
    private function normalizeStationTime(string $time): string
    {
        $raw = trim($time);
        if ('' === $raw) {
            return '';
        }

        $ongoing = (bool) preg_match(
            '/\b(heute|today|present|gegenw[aä]rtig|aktuell|laufend|ongoing|current|now|jetzt)\b/iu',
            $raw
        );
        $tokens = $this->extractMonthYearTokens($raw);

        if (count($tokens) >= 2) {
            return $tokens[0].' – '.$tokens[count($tokens) - 1];
        }
        if (1 === count($tokens)) {
            if ($ongoing || preg_match('/^\s*(seit|since|ab)\b/iu', $raw)) {
                return $tokens[0].' – heute';
            }

            return $tokens[0];
        }

        return $raw;
    }

    /**
     * Pull month/year (or bare-year) tokens out of a free-text date string, in
     * document order, each normalized to "MM/YYYY" (or "YYYY" when no month is
     * present). Recognizes MM/YYYY, MM.YYYY, MM-YYYY, YYYY/MM, YYYY-MM,
     * "Mon YYYY" / "Month YYYY" (German + English) and bare 19xx/20xx years.
     *
     * @return list<string>
     */
    private function extractMonthYearTokens(string $s): array
    {
        $months = [
            'jan' => 1, 'januar' => 1, 'january' => 1,
            'feb' => 2, 'februar' => 2, 'february' => 2,
            'mar' => 3, 'mär' => 3, 'maer' => 3, 'märz' => 3, 'maerz' => 3, 'march' => 3,
            'apr' => 4, 'april' => 4,
            'mai' => 5, 'may' => 5,
            'jun' => 6, 'juni' => 6, 'june' => 6,
            'jul' => 7, 'juli' => 7, 'july' => 7,
            'aug' => 8, 'august' => 8,
            'sep' => 9, 'sept' => 9, 'september' => 9,
            'oct' => 10, 'okt' => 10, 'oktober' => 10, 'october' => 10,
            'nov' => 11, 'november' => 11,
            'dec' => 12, 'dez' => 12, 'dezember' => 12, 'december' => 12,
        ];

        $found = [];
        $consume = function (string $pattern, callable $fmt) use (&$s, &$found): void {
            if (preg_match_all($pattern, $s, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $whole = $m[0][0];
                    $offset = $m[0][1];
                    $found[$offset] = $fmt($m);
                    // Blank the matched span (same length) so later, broader
                    // patterns (e.g. the bare-year pass) don't re-match it.
                    $s = substr_replace($s, str_repeat(' ', strlen($whole)), $offset, strlen($whole));
                }
            }
        };

        // MM<sep>YYYY (month first)
        $consume(
            '#\b(0?[1-9]|1[0-2])\s*[./\-]\s*((?:19|20)\d{2})\b#u',
            fn (array $m): string => sprintf('%02d/%s', (int) $m[1][0], $m[2][0])
        );
        // YYYY<sep>MM (year first)
        $consume(
            '#\b((?:19|20)\d{2})\s*[./\-]\s*(0?[1-9]|1[0-2])\b#u',
            fn (array $m): string => sprintf('%02d/%s', (int) $m[2][0], $m[1][0])
        );
        // Month name + YYYY
        $monthAlt = implode('|', array_map('preg_quote', array_keys($months)));
        $consume(
            '#\b('.$monthAlt.')\.?\s+((?:19|20)\d{2})\b#iu',
            function (array $m) use ($months): string {
                $mon = $months[mb_strtolower($m[1][0])] ?? 0;

                return $mon > 0 ? sprintf('%02d/%s', $mon, $m[2][0]) : $m[2][0];
            }
        );
        // Bare year
        $consume(
            '#\b((?:19|20)\d{2})\b#u',
            fn (array $m): string => $m[1][0]
        );

        ksort($found);

        return array_values($found);
    }

    /**
     * Strip leading lines from `$details` that duplicate the row's outer
     * `time` / `position` (or any slash- or comma-separated chunk of
     * `position` ≥ 5 chars). Stops at the first non-empty, non-matching
     * line. Whitespace-collapsed, case-insensitive comparison.
     */
    private function stripStationDetailsDuplicatePrefix(string $details, string $time, string $position): string
    {
        $needles = [];
        if ('' !== $time) {
            $needles[] = $time;
        }
        if ('' !== $position) {
            $needles[] = $position;
            // Peel chunks so we catch partial echoes like
            // "Web Merchandiser, Category Modern Woman" being the head of
            // an outer "Web Merchandiser, Category Modern Woman / Substitute Einkauf Textil".
            $chunks = preg_split('#\s*[/,]+\s*#u', $position) ?: [];
            foreach ($chunks as $chunk) {
                $chunk = trim((string) $chunk);
                if (mb_strlen($chunk) >= 5) {
                    $needles[] = $chunk;
                }
            }
        }
        if (empty($needles)) {
            return $details;
        }

        $norm = static function (string $s): string {
            $collapsed = preg_replace('/\s+/u', ' ', trim($s)) ?? '';

            return function_exists('mb_strtolower') ? mb_strtolower($collapsed, 'UTF-8') : strtolower($collapsed);
        };
        $needleSet = [];
        foreach ($needles as $n) {
            $needleSet[$norm($n)] = true;
        }

        $lines = preg_split("/\r\n|\n|\r/", $details);
        if (!is_array($lines)) {
            return $details;
        }

        $drop = 0;
        $count = count($lines);
        while ($drop < $count) {
            $stripped = trim($lines[$drop]);
            if ('' === $stripped) {
                ++$drop;
                continue;
            }
            if (!isset($needleSet[$norm($stripped)])) {
                break;
            }
            ++$drop;
        }

        if (0 === $drop) {
            return $details;
        }

        return ltrim(implode("\n", array_slice($lines, $drop)), "\n");
    }

    /**
     * Insert a blank line before every line in `$details` that looks like a
     * sub-period date header. Idempotent: never inserts two blank lines in
     * a row, and never adds a blank in front of the very first line.
     */
    private function ensureBlankLinesBeforeSubPeriods(string $details): string
    {
        $lines = preg_split("/\r\n|\n|\r/", $details);
        if (!is_array($lines) || count($lines) < 2) {
            return $details;
        }

        // Date-header heuristic. Accepts:
        //   "MM/YYYY – MM/YYYY"   (any dash: - or – or —, 1-2 chars)
        //   "MM/YYYY -- heute"    (heute / today / present / now / jetzt)
        //   "since|seit MM/YYYY"
        //   "YYYY – YYYY"         (year-only ranges)
        $datePattern = '/^\s*(?:since\s+|seit\s+)?(?:\d{1,2}\/)?\d{4}\s*[–—\-]{1,2}\s*(?:(?:\d{1,2}\/)?\d{4}|heute|today|present|now|jetzt)\s*$/iu';

        $out = [];
        foreach ($lines as $idx => $line) {
            if ($idx > 0 && 1 === preg_match($datePattern, $line)) {
                $prev = end($out);
                if (false !== $prev && '' !== trim((string) $prev)) {
                    $out[] = '';
                }
            }
            $out[] = $line;
        }

        return implode("\n", $out);
    }

    /**
     * Build variable source map from form fields, falling back to hardcoded defaults.
     *
     * @param array $formFields The form's fields[] array, each with optional 'source' and 'fallback'
     *
     * @return array<string, array{primary: string, fallback?: string}>
     */
    private function getVariableSources(array $formFields = []): array
    {
        $sources = [];
        foreach ($formFields as $field) {
            $key = $field['key'] ?? null;
            if (null === $key) {
                continue;
            }
            $primary = $field['source'] ?? 'form';
            $sources[$key] = ['primary' => $primary];
            $fallback = $field['fallback'] ?? null;
            if (null !== $fallback && '' !== $fallback) {
                $sources[$key]['fallback'] = $fallback;
            } elseif ('form' === $primary) {
                // Honour the documented contract ("fill what you know, let
                // AI extract the rest"): a form-sourced field with no
                // explicit fallback still picks up AI-extracted data. The
                // fallback only fires when the manually entered form value
                // is empty, so user input always wins.
                $sources[$key]['fallback'] = 'ai';
            }
        }

        foreach (self::DEFAULT_VARIABLE_SOURCES as $key => $config) {
            if (!isset($sources[$key])) {
                $sources[$key] = $config;
            }
        }

        return $sources;
    }

    /** @return array{variables: array<string, mixed>} */
    private function resolveVariables(array $entry, ?array $formFields = null): array
    {
        $formData = $entry['field_values'] ?? [];
        $aiData = $entry['ai_extracted'] ?? [];
        $overrides = $entry['variable_overrides'] ?? [];
        $sources = $this->getVariableSources($formFields ?? []);

        $variables = [];

        foreach ($sources as $key => $config) {
            if (array_key_exists($key, $overrides) && null !== $overrides[$key]) {
                $variables[$key] = $overrides[$key];
                continue;
            }

            $primarySource = $config['primary'];
            $fallbackSource = $config['fallback'] ?? null;
            $value = null;

            if ('ai' === $primarySource) {
                $value = $aiData[$key] ?? null;
            } elseif ('form' === $primarySource) {
                $value = $formData[$key] ?? null;
            }

            // An empty primary value ('' or []) must not block the fallback
            // source — a form field left blank should still pick up the
            // AI-extracted value (and vice versa), also on later re-runs.
            if (!$this->fieldValueIsFilled($value) && null !== $fallbackSource) {
                $fallbackValue = null;
                if ('ai' === $fallbackSource) {
                    $fallbackValue = $aiData[$key] ?? null;
                } elseif ('form' === $fallbackSource) {
                    $fallbackValue = $formData[$key] ?? null;
                }
                if ($this->fieldValueIsFilled($fallbackValue)) {
                    $value = $fallbackValue;
                }
            }

            $variables[$key] = $value;
        }

        if (isset($variables['expectedansalary']) && 'nicht relevant' === strtolower((string) $variables['expectedansalary'])) {
            $variables['expectedansalary'] = null;
        }
        if (isset($variables['workinghours']) && 'nicht relevant' === strtolower((string) $variables['workinghours'])) {
            $variables['workinghours'] = null;
        }

        // Auto-generate checkbox variables from form fields with type=checkbox
        $checkboxKeys = [];
        foreach (($formFields ?? []) as $field) {
            if (($field['type'] ?? '') === 'checkbox' && !empty($field['key'])) {
                $checkboxKeys[] = $field['key'];
            }
        }
        // Backward compat: always include these three if present
        foreach (['moving', 'commute', 'travel'] as $legacyKey) {
            if (isset($variables[$legacyKey]) && !in_array($legacyKey, $checkboxKeys, true)) {
                $checkboxKeys[] = $legacyKey;
            }
        }
        foreach ($checkboxKeys as $cbKey) {
            $cbYes = 'ja' === strtolower((string) ($variables[$cbKey] ?? ''))
                || (true === $variables[$cbKey]);
            $variables['checkb.'.$cbKey.'.yes'] = $cbYes;
            $variables['checkb.'.$cbKey.'.no'] = !$cbYes;
        }

        // Backward compat: travelorcommute → commute/travel checkboxes
        if (!isset($variables['commute']) && isset($variables['travelorcommute'])) {
            $commuteVal = $formData['commute'] ?? $variables['travelorcommute'] ?? '';
            $commuteYes = 'ja' === strtolower((string) $commuteVal);
            $variables['checkb.commute.yes'] = $commuteYes;
            $variables['checkb.commute.no'] = !$commuteYes;
        }
        if (!isset($variables['travel']) && isset($variables['travelorcommute'])) {
            $travelVal = $formData['travel'] ?? $variables['travelorcommute'] ?? '';
            $travelYes = 'ja' === strtolower((string) $travelVal);
            $variables['checkb.travel.yes'] = $travelYes;
            $variables['checkb.travel.no'] = !$travelYes;
        }

        // WS-G (feedback #7): derive {{relevant_positions}} from the rows the
        // recruiter ticked "relevant" in a selectable table (the career
        // `stations` table by convention, or any table with designer flag
        // `selectable`). Deterministic format "Zeitraum, Position, Arbeitgeber"
        // per selected row, in table order — predictable and fast (no extra AI
        // round-trip). Only set when not already provided by an override.
        if (!array_key_exists('relevant_positions', $variables) || !$this->fieldValueIsFilled($variables['relevant_positions'] ?? null)) {
            foreach (($formFields ?? []) as $field) {
                if (($field['type'] ?? '') !== 'table') {
                    continue;
                }
                $tKey = (string) ($field['key'] ?? '');
                $selectable = ($field['selectable'] ?? false) === true || 'stations' === $tKey;
                if (!$selectable || '' === $tKey) {
                    continue;
                }
                $tableRows = $formData[$tKey] ?? [];
                if (!is_array($tableRows)) {
                    continue;
                }
                $picked = [];
                foreach ($tableRows as $r) {
                    if (!is_array($r)) {
                        continue;
                    }
                    $sel = $r['selected'] ?? false;
                    $isSel = true === $sel
                        || (is_string($sel) && in_array(strtolower(trim($sel)), ['1', 'true', 'ja', 'yes'], true));
                    if (!$isSel) {
                        continue;
                    }
                    $parts = [];
                    foreach (['time', 'position', 'employer'] as $k) {
                        $v = trim((string) ($r[$k] ?? ''));
                        if ('' !== $v) {
                            $parts[] = $v;
                        }
                    }
                    if ([] !== $parts) {
                        $picked[] = implode(', ', $parts);
                    }
                }
                if ([] !== $picked) {
                    $variables['relevant_positions'] = $picked;
                    break;
                }
            }
        }

        return [
            'variables' => $variables,
        ];
    }

    /**
     * Wrapper around AiFacade::chat for the extraction pipeline that emits
     * structured, greppable telemetry so a slow or failed call can always
     * be investigated after the fact (event=synaform.ai.chat[_error],
     * phase, candidate, model, elapsed_ms). Any provider/network error is
     * logged with its class + message and re-thrown so the caller's own
     * fallback/telemetry still runs.
     *
     * @param array<int, array<string, string>> $messages
     * @param array<string, mixed>              $options
     *
     * @return array<string, mixed>
     */
    private function extractionChat(array $messages, int $userId, array $options, string $phase, string $candidateId): array
    {
        $started = microtime(true);
        $requestedModel = (string) ($options['model'] ?? 'default');
        try {
            $result = $this->aiFacade->chat($messages, $userId, $options);
            $this->logger->info('Synaform extraction AI call', [
                'event' => 'synaform.ai.chat',
                'phase' => $phase,
                'candidateId' => $candidateId,
                'userId' => $userId,
                'requested_model' => $requestedModel,
                'model' => $result['model'] ?? $requestedModel,
                'provider' => $result['provider'] ?? null,
                'elapsed_ms' => (int) ((microtime(true) - $started) * 1000),
                'content_chars' => strlen((string) ($result['content'] ?? '')),
            ]);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Synaform extraction AI call failed', [
                'event' => 'synaform.ai.chat_error',
                'phase' => $phase,
                'candidateId' => $candidateId,
                'userId' => $userId,
                'requested_model' => $requestedModel,
                'elapsed_ms' => (int) ((microtime(true) - $started) * 1000),
                'error_class' => $e::class,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function resolveAiModelOptions(int $userId): array
    {
        // Per-plugin override first: when the user pins a specific model in
        // Settings (P_synaform.extraction_model = a numeric BMODELS id), it
        // wins over the global capability defaults. A faster, non-reasoning
        // model here (e.g. Gemini Flash) keeps the single-call extraction
        // path below its acceptance gate instead of dropping to the slow
        // grouped fallback. "default"/empty/unknown id ignores the override.
        $override = $this->configRepository->getValue($userId, self::CONFIG_GROUP, 'extraction_model');
        if (is_string($override) && ctype_digit($override)) {
            $overrideId = (int) $override;
            $overrideName = $this->modelConfigService->getModelName($overrideId);
            if (null !== $overrideName) {
                return [
                    'model' => $overrideName,
                    'provider' => $this->modelConfigService->getProviderForModel($overrideId),
                ];
            }
        }

        // synaform's "read files & auto-fill" extraction is a text-analytics
        // workload, so honour synaplan's dedicated DEFAULTMODEL.ANALYZE
        // capability (the "Text Analytics" entry in the model collection)
        // first, then fall back to CHAT. This mirrors core's
        // FileAnalysisHandler (ANALYZE → CHAT). getDefaultModel() already
        // cascades user-scope → global (ownerId=0), so a single call per
        // capability covers both.
        $modelId = $this->modelConfigService->getDefaultModel('ANALYZE', $userId)
            ?? $this->modelConfigService->getDefaultModel('CHAT', $userId);
        if ($modelId) {
            return [
                'model' => $this->modelConfigService->getModelName($modelId),
                'provider' => $this->modelConfigService->getProviderForModel($modelId),
            ];
        }

        return [];
    }

    /**
     * Model options for second-opinion runs (the automatic missing-field
     * second pass and explicit "fill missing fields" re-runs).
     *
     * Prefers the `validation_model` plugin setting so a DIFFERENT (often
     * stronger) model can take the second look — the setting existed in the
     * config UI but was never wired to anything. Falls back to the regular
     * extraction model resolution when unset.
     */
    private function resolveSecondPassModelOptions(int $userId): array
    {
        $override = $this->configRepository->getValue($userId, self::CONFIG_GROUP, 'validation_model');
        if (is_string($override) && ctype_digit($override)) {
            $overrideId = (int) $override;
            $overrideName = $this->modelConfigService->getModelName($overrideId);
            if (null !== $overrideName) {
                return [
                    'model' => $overrideName,
                    'provider' => $this->modelConfigService->getProviderForModel($overrideId),
                ];
            }
        }

        return $this->resolveAiModelOptions($userId);
    }

    /**
     * Collect array/repeating-group data from the entry.
     * Each key maps to an array of associative arrays (rows) or flat string arrays (lists).
     *
     * @return array<string, array<int, array<string, string>|string>>
     */
    private function collectArrayData(array $entry, array $formFields): array
    {
        $arrays = [];
        $formData = $entry['field_values'] ?? [];
        $aiData = $entry['ai_extracted'] ?? [];
        $overrides = $entry['variable_overrides'] ?? [];

        $scannedKeys = [];
        foreach ($formFields as $field) {
            $key = $field['key'] ?? '';
            $type = $field['type'] ?? 'text';
            if ('' === $key || ('table' !== $type && 'list' !== $type)) {
                continue;
            }
            $scannedKeys[$key] = true;
            $primarySource = $field['source'] ?? 'form';
            $fallbackSource = $field['fallback'] ?? null;
            // Mirror getVariableSources(): a form-sourced table/list with no
            // explicit fallback still falls back to AI-extracted rows, so
            // repeating groups (e.g. `stations`) fill from extraction when
            // the user hasn't entered them by hand.
            if ((null === $fallbackSource || '' === $fallbackSource) && 'form' === $primarySource) {
                $fallbackSource = 'ai';
            }

            $val = $overrides[$key] ?? null;
            if (null === $val) {
                $val = 'ai' === $primarySource ? ($aiData[$key] ?? null) : ($formData[$key] ?? null);
            }
            if (null === $val && null !== $fallbackSource) {
                $val = 'ai' === $fallbackSource ? ($aiData[$key] ?? null) : ($formData[$key] ?? null);
            }
            if (is_array($val) && !empty($val)) {
                $arrays[$key] = $val;
            }
        }

        // Backward compat: pick up hardcoded list keys from DEFAULT_VARIABLE_SOURCES
        $legacyListKeys = ['relevantposlist', 'relevantfortargetposlist', 'languageslist', 'otherskillslist', 'benefits'];
        foreach ($legacyListKeys as $key) {
            if (isset($scannedKeys[$key])) {
                continue;
            }
            $val = $overrides[$key] ?? $formData[$key] ?? $aiData[$key] ?? null;
            if (is_array($val) && !empty($val)) {
                $arrays[$key] = $val;
            }
        }

        // Backward compat: pick up legacy 'stations' from any source if not covered
        // by a form table field. Resolution order: override > form > AI > empty —
        // matching the same precedence used for other fields.
        if (!isset($scannedKeys['stations']) && !isset($arrays['stations'])) {
            $stations = null;
            if (array_key_exists('stations', $overrides) && is_array($overrides['stations'])) {
                $stations = $overrides['stations'];
            } elseif (isset($formData['stations']) && is_array($formData['stations'])) {
                $stations = $formData['stations'];
            } elseif (isset($aiData['stations']) && is_array($aiData['stations'])) {
                $stations = $aiData['stations'];
            }
            if (is_array($stations) && !empty($stations)) {
                $arrays['stations'] = $stations;
            }
        }

        // Defensive: re-run the post-extraction cleanup whenever we hand
        // `stations` to the generator. Catches data extracted by older
        // plugin versions, manually-edited rows, and override paths that
        // never went through the AI normaliser.
        if (isset($arrays['stations']) && is_array($arrays['stations'])) {
            $arrays['stations'] = $this->normalizeStationsRows($arrays['stations']);
        }

        // v4.3.1: collapse duplicate entries in every list value handed to the
        // generator. This runs at the DATA layer — before any template render
        // path (table / row-group / block / flat list) and before the
        // structured classifier (which would treat the first line as a title,
        // hiding its duplicate) — so "alle Unterpunkte doppelt" is caught
        // regardless of how the customer's template consumes the data.
        $arrays = $this->dedupeListColumnValues($arrays, $formFields);

        return $arrays;
    }

    /**
     * v4.3.1 data-layer de-duplication for list values.
     *
     * Collapses repeated entries in (a) stand-alone `list` variables and
     * (b) `list`-typed columns inside table rows (always including
     * `stations.details`). Uses {@see dedupeListStrings} so entries differing
     * only in casing / spacing / punctuation collapse to one, preserving order
     * and the first occurrence. Only touches flat string lists — nested row
     * arrays are recursed into, never de-duplicated as whole rows.
     *
     * @param array<string, mixed>             $arrays
     * @param array<int, array<string, mixed>> $formFields
     *
     * @return array<string, mixed>
     */
    private function dedupeListColumnValues(array $arrays, array $formFields): array
    {
        $topLevelListKeys = [];
        $listColumns = [];
        foreach ($formFields as $field) {
            $key = (string) ($field['key'] ?? '');
            $type = (string) ($field['type'] ?? 'text');
            if ('' === $key) {
                continue;
            }
            if ('list' === $type) {
                $topLevelListKeys[$key] = true;
                continue;
            }
            if ('table' === $type) {
                foreach (($field['columns'] ?? []) as $col) {
                    if (is_array($col) && ($col['type'] ?? '') === 'list' && !empty($col['key'])) {
                        $listColumns[$key][] = (string) $col['key'];
                    }
                }
            }
        }
        // `stations.details` is a rich bullet list even when a template only
        // declares it loosely — always de-dup it.
        $listColumns['stations'] = array_values(array_unique(
            array_merge($listColumns['stations'] ?? [], ['details'])
        ));

        foreach ($arrays as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            // Stand-alone flat list variable (e.g. languages, other_skills).
            if (isset($topLevelListKeys[$key]) && !is_array($value[0] ?? null)) {
                $arrays[$key] = $this->dedupeListStrings(
                    array_map(static fn ($v) => (string) $v, $value)
                );
                continue;
            }
            // Table rows: de-dup each list column's array in every row.
            if (isset($listColumns[$key])) {
                foreach ($value as $ri => $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    foreach ($listColumns[$key] as $colKey) {
                        if (isset($row[$colKey]) && is_array($row[$colKey])) {
                            $arrays[$key][$ri][$colKey] = $this->dedupeListStrings(
                                array_map(static fn ($v) => (string) $v, $row[$colKey])
                            );
                        }
                    }
                }
            }
        }

        return $arrays;
    }

    /**
     * Classify template placeholders into rendering modes by inspecting patterns.
     *
     * - ROW groups: {{groupname.field}} where groupname is a known array of objects
     * - BLOCK groups: {{#groupname}} / {{/groupname}} bracket pairs
     * - Checkboxes: {{checkb.key.yes}} / {{checkb.key.no}}
     * - Lists: placeholder whose resolved value is an array (flat list of strings)
     * - Scalars: everything else
     *
     * @return array{rowGroups: array<string, list<string>>, blockGroups: list<string>, checkboxes: array<string, list<string>>, lists: list<string>, scalars: list<string>}
     */
    private function classifyTemplatePlaceholders(array $placeholders, array $variables, array $arrays): array
    {
        $rowGroups = [];
        $blockGroupNames = [];
        $checkboxes = [];
        $lists = [];
        $scalars = [];

        $arrayObjectKeys = [];
        foreach ($arrays as $name => $data) {
            if (!empty($data) && is_array($data[0] ?? null)) {
                $arrayObjectKeys[$name] = true;
            }
        }

        foreach ($placeholders as $ph) {
            if (str_starts_with($ph, '#') || str_starts_with($ph, '/')) {
                $blockGroupNames[trim($ph, '#/')] = true;
                continue;
            }

            if (str_starts_with($ph, 'checkb.')) {
                $parts = explode('.', $ph);
                $cbKey = $parts[1] ?? '';
                if ('' !== $cbKey) {
                    $checkboxes[$cbKey][] = $ph;
                }
                continue;
            }

            if (str_contains($ph, '.')) {
                $prefix = explode('.', $ph)[0];
                if (isset($arrayObjectKeys[$prefix])) {
                    $rowGroups[$prefix][] = $ph;
                    continue;
                }
            }

            $val = $variables[$ph] ?? null;
            if (is_array($val) || isset($arrays[$ph])) {
                $lists[] = $ph;
                continue;
            }

            $scalars[] = $ph;
        }

        return [
            'rowGroups' => $rowGroups,
            'blockGroups' => array_keys($blockGroupNames),
            'checkboxes' => $checkboxes,
            'lists' => $lists,
            'scalars' => $scalars,
        ];
    }

    /**
     * ROW mode: clone table rows for repeating groups like stations.
     * Template has {{stations.employer}}, {{stations.time}}, etc. in a table row.
     * PhpWord cloneRow duplicates the row, suffixing #1, #2, etc.
     *
     * The extra $designerMap (not currently used for substitution — it is
     * consumed by applyTableLayoutHelpers in a later pass) keeps the signature
     * consistent across row/list/checkbox handlers so callers can pass a single
     * config map regardless of rendering mode.
     *
     * @param array<string, array<string, mixed>> $designerMap
     */
    private function processRowGroups(TemplateProcessor $tp, array $rowGroups, array $arrays, array $designerMap = [], array $richSubfields = self::RICH_ROW_SUBFIELDS_DEFAULT): void
    {
        foreach ($rowGroups as $groupName => $fields) {
            $data = $arrays[$groupName] ?? [];
            $count = count($data);
            if (0 === $count) {
                foreach ($fields as $field) {
                    $tp->setValue($field, '');
                }
                continue;
            }

            $anchorField = $fields[0] ?? null;
            if (null === $anchorField) {
                continue;
            }

            try {
                $tp->cloneRow($anchorField, $count);
            } catch (\Throwable $e) {
                $this->logger->warning('cloneRow failed, falling back to setValue', [
                    'group' => $groupName,
                    'anchor' => $anchorField,
                    'error' => $e->getMessage(),
                ]);
                foreach ($fields as $field) {
                    $tp->setValue($field, '');
                }
                continue;
            }

            $uniqueFieldSuffixes = [];
            foreach ($fields as $field) {
                $suffix = substr($field, strlen($groupName) + 1);
                $uniqueFieldSuffixes[$suffix] = true;
            }

            for ($i = 0; $i < $count; ++$i) {
                $num = $i + 1;
                $row = $data[$i] ?? [];
                foreach (array_keys($uniqueFieldSuffixes) as $suffix) {
                    $cleanSuffix = str_replace('.N', '', $suffix);

                    // Rich sub-fields are left as-is (placeholder remains in XML) and
                    // rendered by a post-save pass that can emit multiple paragraphs,
                    // proper bullet formatting, bold date headers, etc. See
                    // expandStationDetails(). Simple sub-fields continue through the
                    // plain setValue path below.
                    if (in_array("{$groupName}.{$cleanSuffix}", $richSubfields, true)) {
                        continue;
                    }

                    $value = $row[$cleanSuffix] ?? '';
                    $value = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                    if (str_contains($value, "\n")) {
                        $value = str_replace("\n", '</w:t><w:br/><w:t>', $value);
                    }
                    $phName = "{$groupName}.{$suffix}";
                    $tp->setValue($phName.'#'.$num, $value);
                }
            }
        }
    }

    /**
     * BLOCK mode: clone everything between {{#name}} and {{/name}} markers.
     * Fills inner placeholders per iteration.
     */
    private function processBlockGroups(TemplateProcessor $tp, array $blockGroupNames, array $arrays): void
    {
        foreach ($blockGroupNames as $groupName) {
            $data = $arrays[$groupName] ?? [];
            $count = count($data);
            if (0 === $count) {
                try {
                    $tp->cloneBlock($groupName, 0, true, true);
                } catch (\Throwable) {
                }
                continue;
            }

            try {
                $tp->cloneBlock($groupName, $count, true, true);
            } catch (\Throwable $e) {
                $this->logger->warning('cloneBlock failed', [
                    'group' => $groupName,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            for ($i = 0; $i < $count; ++$i) {
                $num = $i + 1;
                $row = is_array($data[$i]) ? $data[$i] : ['value' => (string) $data[$i]];
                foreach ($row as $field => $value) {
                    $value = (string) ($value ?? '');
                    $value = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                    if (str_contains($value, "\n")) {
                        $value = str_replace("\n", '</w:t><w:br/><w:t>', $value);
                    }
                    $tp->setValue("{$groupName}.{$field}#{$num}", $value);
                }
            }
        }
    }

    /**
     * Checkbox mode: detect all checkb.KEY.yes / checkb.KEY.no pairs.
     * Checks or unchecks Word checkbox content controls. When the variable's
     * designer config overrides the glyphs, use those instead of the ☒/☐
     * defaults (e.g. a template that uses ✅/❌).
     *
     * @param array<string, array<string, mixed>> $designerMap
     */
    private function processCheckboxes(TemplateProcessor $tp, array $checkboxes, array $variables, array $designerMap = []): void
    {
        foreach ($checkboxes as $cbKey => $fields) {
            $yesVal = (bool) ($variables["checkb.{$cbKey}.yes"] ?? false);
            $designer = $designerMap[$cbKey] ?? [];
            $checkedGlyph = is_string($designer['checked_glyph'] ?? null) ? $designer['checked_glyph'] : '☒';
            $uncheckedGlyph = is_string($designer['unchecked_glyph'] ?? null) ? $designer['unchecked_glyph'] : '☐';
            // Per-variable opt-out: plain `clickable_checkbox` => false leaves
            // the historical static glyph behaviour untouched. Default is true
            // because the customer wants real, interactive checkboxes.
            $clickable = !array_key_exists('clickable_checkbox', $designer)
                || false !== $designer['clickable_checkbox'];

            foreach ($fields as $ph) {
                $checked = str_ends_with($ph, '.yes') ? $yesVal : !$yesVal;

                // Best-effort: for templates that already use real Word checkbox
                // content controls (<w:sdt>…<w:sdtCheckbox>), update the state
                // via PhpWord directly. Plain-text placeholders fall through to
                // the marker path below.
                try {
                    $tp->setCheckbox($ph, $checked);
                } catch (\Throwable) {
                    // ignored — marker / text replacement below is the guaranteed path
                }

                if ($clickable) {
                    // Emit a unique marker that survives PhpWord's setValue
                    // XML escaping. The post-pass
                    // convertCheckboxMarkersToContentControls() then rewrites
                    // each `<w:r>…marker…</w:r>` into a real <w:sdt> Word
                    // content-control checkbox so the generated DOCX exposes
                    // a clickable checkbox pre-set to the correct state.
                    $tp->setValue($ph, $this->buildCheckboxMarker($checked, $checkedGlyph, $uncheckedGlyph));
                } else {
                    $tp->setValue($ph, $checked ? $checkedGlyph : $uncheckedGlyph);
                }
            }
        }
    }

    /**
     * Build the unique placeholder marker we substitute for a checkbox during
     * the PhpWord pass. The marker carries the desired state plus both glyphs
     * so the post-pass can use designer-customised glyphs without re-reading
     * the variable map.
     *
     * Format: [[SYNCB|state|checkedGlyph|uncheckedGlyph]]
     */
    private function buildCheckboxMarker(bool $checked, string $checkedGlyph, string $uncheckedGlyph): string
    {
        return '[[SYNCB|'.($checked ? 'on' : 'off').'|'.$checkedGlyph.'|'.$uncheckedGlyph.']]';
    }

    /**
     * LIST mode: array values rendered as newline-separated text with OOXML line breaks.
     */
    private function processLists(TemplateProcessor $tp, array $listKeys, array $variables, array $designerMap = []): void
    {
        foreach ($listKeys as $key) {
            $val = $variables[$key] ?? null;
            $items = is_array($val) ? array_map('strval', $val) : ('' === (string) ($val ?? '') ? [] : [(string) $val]);
            if ([] === $items) {
                // Keep the placeholder visible (yellow) instead of wiping it —
                // same contract as processScalars for empty variables.
                $tp->setValue($key, $this->buildEmptyPlaceholderXml($key));
                continue;
            }
            $designer = $designerMap[$key] ?? [];
            if (!empty($designer['top_blank_line'])) {
                array_unshift($items, '');
            }
            if (!empty($designer['bottom_blank_line'])) {
                $items[] = '';
            }
            $text = implode("\n", $items);
            $text = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $text = str_replace("\n", '</w:t><w:br/><w:t>', $text);
            $tp->setValue($key, $text);
        }
    }

    /**
     * Image mode: for every image-typed form field whose candidate record
     * carries a stored image path, replace the matching `{{key}}` placeholder
     * with an actual embedded image using PhpWord's setImageValue().
     *
     * Size defaults are conservative (140x180 px) and can be overridden either
     * in the template via the `{{key:width=W:height=H}}` suffix (PhpWord
     * understands this natively) or in the variable's designer config
     * (`designer.width`, `designer.height`). Missing images leave the
     * placeholder untouched; the scalar pass then keeps it visible with a
     * yellow highlight so the customer can spot the missing image.
     *
     * Fields whose designer config sets `placement=float` are NOT embedded
     * inline. Their placeholder is replaced with a `[[SYNIMG|key]]` marker
     * and the actual floating (absolutely positioned, text-wrapping) shape
     * is inserted by embedFloatingImages() after saveAs() — PhpWord's
     * setImageValue can only produce inline shapes.
     *
     * @param array<int, array<string, mixed>> $formFields
     * @param array<string, mixed>             $entry
     *
     * @return array<string, array{path: string, mime: string, designer: array<string, mixed>}> float-configured images keyed by variable key
     */
    private function processImages(TemplateProcessor $tp, array $formFields, array $entry): array
    {
        $floating = [];
        foreach ($formFields as $field) {
            if (($field['type'] ?? '') !== 'image' || empty($field['key'])) {
                continue;
            }
            $key = (string) $field['key'];
            $meta = $entry['field_values'][$key] ?? null;
            if (!is_array($meta) || empty($meta['path']) || !is_file($meta['path'])) {
                continue;
            }

            $designer = $field['designer'] ?? [];
            if (($designer['placement'] ?? 'inline') === 'float') {
                $tp->setValue($key, '[[SYNIMG|'.$key.']]');
                $floating[$key] = [
                    'path' => (string) $meta['path'],
                    'mime' => (string) ($meta['mime'] ?? 'image/png'),
                    'designer' => $designer,
                ];
                continue;
            }

            $width = (int) ($designer['width'] ?? 140);
            $height = (int) ($designer['height'] ?? 180);

            try {
                $tp->setImageValue($key, [
                    'path' => $meta['path'],
                    'width' => $width,
                    'height' => $height,
                    'ratio' => !empty($designer['preserve_ratio']),
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('Image placeholder replacement failed', [
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $floating;
    }

    /**
     * Phase F post-pass: replace every `[[SYNIMG|key]]` marker emitted by
     * processImages() with a floating VML picture shape — absolutely
     * positioned relative to the page margins (e.g. profile photo in the
     * upper right corner) with configurable text wrapping. Handles the main
     * body plus headers/footers, and registers the image binary, its
     * content-type override, and the relationship entry per part.
     *
     * @param array<string, array{path: string, mime: string, designer: array<string, mixed>}> $floatingImages
     */
    private function embedFloatingImages(string $docxPath, array $floatingImages): void
    {
        if ([] === $floatingImages || !is_file($docxPath)) {
            return;
        }

        $zip = new \ZipArchive();
        if (true !== $zip->open($docxPath)) {
            $this->logger->warning('Failed to open DOCX for floating-image post-pass', ['path' => $docxPath]);

            return;
        }

        $contentTypes = $zip->getFromName('[Content_Types].xml');
        $imgIdx = 0;

        foreach ($this->collectDocumentPartNames($zip) as $partName) {
            $xml = $zip->getFromName($partName);
            if (false === $xml) {
                continue;
            }
            $partDirty = false;

            foreach ($floatingImages as $key => $img) {
                $marker = '[[SYNIMG|'.$key.']]';
                if (!str_contains($xml, $marker)) {
                    continue;
                }

                ++$imgIdx;
                $ext = $this->mimeToExtension($img['mime']);
                $mediaName = 'synimg'.$imgIdx.'.'.$ext;
                $zip->addFile($img['path'], 'word/media/'.$mediaName);

                if (is_string($contentTypes) && !str_contains($contentTypes, '"/word/media/'.$mediaName.'"')) {
                    $override = '<Override PartName="/word/media/'.$mediaName.'" ContentType="'.htmlspecialchars($img['mime'], ENT_XML1 | ENT_QUOTES, 'UTF-8').'"/>';
                    $contentTypes = str_replace('</Types>', $override.'</Types>', $contentTypes);
                }

                $relsName = 'word/_rels/'.basename($partName).'.rels';
                $relsXml = $zip->getFromName($relsName);
                if (false === $relsXml) {
                    $relsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>';
                }
                $rid = 'rIdSynImg'.$imgIdx;
                $relation = '<Relationship Id="'.$rid.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/'.$mediaName.'"/>';
                $zip->addFromString($relsName, str_replace('</Relationships>', $relation.'</Relationships>', $relsXml));

                // The marker sits inside a <w:t> of a <w:r>: close that run,
                // emit a run carrying the pict, reopen a run for any tail text.
                $pict = $this->buildFloatingImagePict($rid, $img['designer'], $img['path']);
                $xml = str_replace(
                    $marker,
                    '</w:t></w:r><w:r>'.$pict.'</w:r><w:r><w:t xml:space="preserve">',
                    $xml
                );
                $partDirty = true;
            }

            if ($partDirty) {
                $zip->addFromString($partName, $this->ensureVmlNamespaces($xml));
            }
        }

        if (is_string($contentTypes)) {
            $zip->addFromString('[Content_Types].xml', $contentTypes);
        }
        $zip->close();
    }

    /**
     * Build the floating `<w:pict>` for one image. Position and wrap come
     * from the variable's designer config:
     *   float_h: left|center|right   (relative to the page margins)
     *   float_v: top|middle|bottom   (relative to the page margins)
     *   wrap:    square|topAndBottom|none.
     */
    private function buildFloatingImagePict(string $rid, array $designer, string $imagePath): string
    {
        $width = max(16, min(1600, (int) ($designer['width'] ?? 140)));
        $height = max(16, min(2000, (int) ($designer['height'] ?? 180)));
        if (!empty($designer['preserve_ratio'])) {
            $size = @getimagesize($imagePath);
            if (is_array($size) && (int) $size[0] > 0 && (int) $size[1] > 0) {
                $scale = min($width / (int) $size[0], $height / (int) $size[1]);
                $width = max(16, (int) round((int) $size[0] * $scale));
                $height = max(16, (int) round((int) $size[1] * $scale));
            }
        }

        $h = in_array($designer['float_h'] ?? '', ['left', 'center', 'right'], true) ? $designer['float_h'] : 'right';
        $v = $designer['float_v'] ?? 'top';
        $v = match ($v) {
            'middle' => 'center',
            'bottom' => 'bottom',
            default => 'top',
        };
        $wrap = in_array($designer['wrap'] ?? '', ['square', 'topAndBottom', 'none'], true) ? $designer['wrap'] : 'square';

        $style = sprintf(
            'position:absolute;mso-position-horizontal:%s;mso-position-horizontal-relative:margin;'
            .'mso-position-vertical:%s;mso-position-vertical-relative:margin;width:%dpx;height:%dpx;z-index:251658240',
            $h,
            $v,
            $width,
            $height
        );
        $wrapXml = 'none' === $wrap ? '' : '<w10:wrap type="'.$wrap.'"/>';

        return '<w:pict><v:shape type="#_x0000_t75" style="'.$style.'" stroked="f" filled="f">'
            .'<v:imagedata r:id="'.$rid.'" o:title=""/>'.$wrapXml.'</v:shape></w:pict>';
    }

    /**
     * Make sure the part's root element declares the VML namespaces the
     * floating shape uses. Word-authored documents usually carry them, but a
     * minimal template (e.g. generated programmatically) may not — and a
     * missing xmlns makes Word refuse to open the file.
     */
    private function ensureVmlNamespaces(string $xml): string
    {
        $needed = [
            'xmlns:v' => 'urn:schemas-microsoft-com:vml',
            'xmlns:o' => 'urn:schemas-microsoft-com:office:office',
            'xmlns:w10' => 'urn:schemas-microsoft-com:office:word',
        ];
        $missing = '';
        foreach ($needed as $attr => $uri) {
            if (!str_contains($xml, $attr.'=')) {
                $missing .= ' '.$attr.'="'.$uri.'"';
            }
        }
        if ('' === $missing) {
            return $xml;
        }

        $patched = preg_replace('#<w:(document|hdr|ftr)\b([^>]*)>#', '<w:$1$2'.$missing.'>', $xml, 1);

        return is_string($patched) ? $patched : $xml;
    }

    /**
     * Scalar mode: simple text replacement for all remaining placeholders.
     *
     * Empty variables are NOT blanked out: the literal placeholder stays in
     * the document with a yellow highlight, so the customer sees exactly
     * which values are still missing and can re-run extraction / fill them
     * and generate again.
     */
    private function processScalars(TemplateProcessor $tp, array $scalarKeys, array $variables): void
    {
        foreach ($scalarKeys as $key) {
            $value = $variables[$key] ?? null;
            if (null === $value || '' === (string) $value) {
                $tp->setValue($key, $this->buildEmptyPlaceholderXml($key));
                continue;
            }
            $value = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $tp->setValue($key, $value);
        }
    }

    /**
     * OOXML fragment that keeps an unresolved `{{key}}` visible in the
     * generated document, rendered on a yellow highlight. Assumes the
     * replacement happens inside a `<w:t>` of a `<w:r>` (which is what
     * PhpWord's setValue guarantees): the host run is closed, a dedicated
     * highlighted run carries the placeholder text, and a fresh run reopens
     * for whatever text followed the placeholder.
     */
    private function buildEmptyPlaceholderXml(string $key): string
    {
        $label = htmlspecialchars('{{'.$key.'}}', ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '</w:t></w:r>'
            .'<w:r><w:rPr><w:highlight w:val="yellow"/></w:rPr>'
            .'<w:t xml:space="preserve">'.$label.'</w:t></w:r>'
            .'<w:r><w:t xml:space="preserve">';
    }

    private function cleanTemplateMacros(string $docxPath): string
    {
        $cleanedPath = dirname($docxPath).'/template_cleaned.docx';
        copy($docxPath, $cleanedPath);

        $zip = new \ZipArchive();
        if (true !== $zip->open($cleanedPath)) {
            return $cleanedPath;
        }

        // Apply the same normalisation to every "document part" that can carry
        // placeholder text: the main body PLUS every word/header*.xml and
        // word/footer*.xml. Without this, placeholders that the customer put
        // into a Word header / footer ("Profil von {{fullname}}") never get
        // replaced — PhpWord's setValue does walk those parts on its own, but
        // it cannot find a placeholder whose `{{` and `}}` were split across
        // multiple `<w:r>` runs by Word's autocorrect, and this method's
        // job is to defragment those runs so PhpWord can see them.
        foreach ($this->collectDocumentPartNames($zip) as $partName) {
            $xml = $zip->getFromName($partName);
            if (false === $xml) {
                continue;
            }

            $xml = preg_replace('/\{(<[^>]*>)*\{/', '{{', $xml);
            $xml = preg_replace('/\}(<[^>]*>)*\}/', '}}', $xml);

            $xml = preg_replace_callback('/\{\{(.*?)\}\}/s', function ($match) {
                $inner = strip_tags($match[1]);
                $inner = preg_replace('/\s+/', '', $inner);

                return '{{'.trim($inner).'}}';
            }, $xml);

            // Font preservation pass: every paragraph that hosts a {{placeholder}}
            // gets its placeholder runs guaranteed an explicit <w:rPr> with the
            // surrounding font/size, so the values that PhpWord swaps in keep the
            // designer-intended typeface instead of falling back to the document
            // default (Times New Roman / theme.minorHAnsi). Critical when the
            // template was authored in Word with a non-default body font (e.g.
            // Arial, Helvetica-Light) and the placeholder runs lost their explicit
            // font during Word autocorrect / cut-paste.
            $xml = $this->normalizePlaceholderRunFonts($xml);

            // CloneRow defensive pass: PhpWord's TemplateProcessor::findRowStart
            // searches BACKWARD for `<w:tr ` (with the trailing space — i.e. a
            // row carrying at least one attribute) before falling back to bare
            // `<w:tr>`. When a customer-authored template uses a bare `<w:tr>`
            // for the row hosting `{{group.col.N}}` placeholders (anything we
            // generate programmatically tends to look that way), findRowStart
            // skips that row and matches the LAST attributed `<w:tr ` ABOVE the
            // current table — which is typically the closing row of the
            // PREVIOUS table on the page. cloneRow then duplicates a slice that
            // spans both tables and the table boundary, producing the
            // "sonstige Kenntnisse leaks into Werdegang" bug we hit on the v5
            // NeedleHaystack template before this fix. Inject a stable
            // synthetic `w:rsidR` attribute onto every bare `<w:tr>` so
            // findRowStart always lands on the right row. The attribute is
            // schema-valid, ignored by Word, and the value is deterministic
            // so re-running the pass is byte-stable.
            $xml = $this->ensureRowsCarryAttributes($xml);

            $zip->addFromString($partName, $xml);
        }

        $zip->close();

        return $cleanedPath;
    }

    /**
     * Return the names of every "document part" inside a DOCX zip that can
     * carry user-visible text and therefore placeholders: the main body
     * (`word/document.xml`) plus every `word/header*.xml` and
     * `word/footer*.xml`. Order is stable so callers can iterate
     * deterministically.
     *
     * Note: footnotes / endnotes / glossary parts are intentionally excluded
     * — neither Synaform's templates nor any production workflow we know of
     * places `{{placeholders}}` there, and including them would also expose
     * us to PhpWord's known-flaky behaviour around those parts.
     *
     * @return list<string>
     */
    private function collectDocumentPartNames(\ZipArchive $zip): array
    {
        $names = ['word/document.xml'];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $stat = $zip->statIndex($i);
            $entry = $stat['name'] ?? '';
            if ('' === $entry || 'word/document.xml' === $entry) {
                continue;
            }
            if (preg_match('#^word/(header|footer)\d*\.xml$#', $entry)) {
                $names[] = $entry;
            }
        }

        return $names;
    }

    /**
     * For every paragraph that contains a `{{placeholder}}`, rewrite the runs
     * that contribute to the placeholder text so each carries a complete
     * `<w:rPr>` with the paragraph's dominant `<w:rFonts>` (and `<w:sz>` /
     * `<w:szCs>` if available). Pure-formatting bookkeeping — we never touch
     * the actual run text or the placeholder syntax.
     *
     * The "dominant" font/size for a paragraph is, in order of preference:
     *   1. The font/size on the first non-empty run in the paragraph that
     *      already declares one,
     *   2. otherwise the most common run rFonts/sz/szCs across the WHOLE
     *      document (a "document-wide fallback"). This is what fixes
     *      templates whose placeholder runs were created without any explicit
     *      font (e.g. NH-style templates where the body paragraphs all rely
     *      on the document default that points at the wrong theme font).
     *   3. otherwise the run is left untouched and falls back to the
     *      document-default behaviour — same as before this pass existed.
     */
    /**
     * Promote every bare `<w:tr>` opening tag to `<w:tr w:rsidR="…">` so
     * PhpWord's `TemplateProcessor::findRowStart()` can locate the host row
     * of a row-group placeholder. See the comment in `cleanTemplateMacros`
     * for the rationale; the regression test lives in
     * `tests/phase-c2-clonerow-bare-tr.php`.
     *
     * The attribute value is a fixed sentinel so the transform is
     * idempotent: running the pass twice produces byte-identical output.
     * Rows that already carry any attribute are left untouched.
     */
    private function ensureRowsCarryAttributes(string $xml): string
    {
        $patched = preg_replace(
            '#<w:tr>#',
            '<w:tr w:rsidR="00000000" w:rsidTr="00000000">',
            $xml
        );

        return is_string($patched) ? $patched : $xml;
    }

    private function normalizePlaceholderRunFonts(string $xml): string
    {
        $documentWide = $this->detectDocumentDominantRunStyle($xml);

        $paraPattern = '#<w:p\b[^>]*>(?:(?!</w:p>).)*?</w:p>#s';
        $rewritten = preg_replace_callback(
            $paraPattern,
            function (array $m) use ($documentWide): string {
                $paraXml = $m[0];

                // Quick reject: only paragraphs that mention {{ matter.
                if (false === strpos($paraXml, '{{')) {
                    return $paraXml;
                }

                $dominant = $this->detectDominantRunStyle($paraXml);

                // Fill any missing slot from the document-wide signal so a
                // placeholder paragraph that has no explicit font of its
                // own still ends up with a font baked in.
                if ('' === $dominant['rFonts'] && '' !== $documentWide['rFonts']) {
                    $dominant['rFonts'] = $documentWide['rFonts'];
                }
                if ('' === $dominant['sz'] && '' !== $documentWide['sz']) {
                    $dominant['sz'] = $documentWide['sz'];
                }
                if ('' === $dominant['szCs'] && '' !== $documentWide['szCs']) {
                    $dominant['szCs'] = $documentWide['szCs'];
                }

                if ('' === $dominant['rFonts'] && '' === $dominant['sz'] && '' === $dominant['szCs']) {
                    // No font signal anywhere — leave the paragraph alone.
                    return $paraXml;
                }

                return preg_replace_callback(
                    '#<w:r\b[^>]*>(.*?)</w:r>#s',
                    function (array $rm) use ($dominant): string {
                        return $this->ensureRunHasFont($rm[0], $dominant);
                    },
                    $paraXml
                ) ?? $paraXml;
            },
            $xml
        );

        return is_string($rewritten) ? $rewritten : $xml;
    }

    /**
     * Walk every run in the document and return the most common `<w:rFonts>`
     * (and `<w:sz>`, `<w:szCs>`) declaration. "Most common" is tallied by
     * exact serialised XML fragment so we keep schema-valid, complete tags
     * (e.g. `<w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>` rather
     * than guessing one from raw font names).
     *
     * This intentionally ignores theme-only declarations
     * (`w:asciiTheme="minorHAnsi"`) because those resolve via theme1.xml to
     * "Calibri" (or similar) which is rarely what the template author wanted
     * for placeholder content — they nearly always typed the placeholder into
     * a paragraph that already used a concrete font.
     *
     * @return array{rFonts: string, sz: string, szCs: string}
     */
    private function detectDocumentDominantRunStyle(string $xml): array
    {
        $fontTallies = [];
        $szTallies = [];
        $szCsTallies = [];

        if (preg_match_all('#<w:r\b[^>]*>(?:(?!</w:r>).)*?</w:r>#s', $xml, $rm)) {
            foreach ($rm[0] as $runXml) {
                if (!preg_match('#<w:rPr\b[^>]*>(.*?)</w:rPr>#s', $runXml, $rprm)) {
                    continue;
                }
                $rPrInner = $rprm[1];
                if (preg_match('#<w:rFonts\b[^>]*/?>#', $rPrInner, $fm)) {
                    $tag = $fm[0];
                    // Skip theme-only declarations (no concrete font name).
                    if (false !== strpos($tag, 'w:ascii=') || false !== strpos($tag, 'w:hAnsi=')) {
                        $fontTallies[$tag] = ($fontTallies[$tag] ?? 0) + 1;
                    }
                }
                if (preg_match('#<w:sz\b[^>]*/?>#', $rPrInner, $sm)) {
                    $szTallies[$sm[0]] = ($szTallies[$sm[0]] ?? 0) + 1;
                }
                if (preg_match('#<w:szCs\b[^>]*/?>#', $rPrInner, $sm2)) {
                    $szCsTallies[$sm2[0]] = ($szCsTallies[$sm2[0]] ?? 0) + 1;
                }
            }
        }

        return [
            'rFonts' => $this->topTallyKey($fontTallies),
            'sz' => $this->topTallyKey($szTallies),
            'szCs' => $this->topTallyKey($szCsTallies),
        ];
    }

    /**
     * Helper: return the key with the highest count from a string => int map,
     * or '' if the map is empty.
     *
     * @param array<string, int> $tallies
     */
    private function topTallyKey(array $tallies): string
    {
        if (empty($tallies)) {
            return '';
        }
        arsort($tallies);

        return (string) array_key_first($tallies);
    }

    /**
     * Inspect a paragraph's runs and return the first font / size declaration
     * we find. Returns serialised XML fragments ready to splice into a `<w:rPr>`.
     *
     * @return array{rFonts: string, sz: string, szCs: string}
     */
    private function detectDominantRunStyle(string $paraXml): array
    {
        $rFonts = '';
        $sz = '';
        $szCs = '';
        if (preg_match_all('#<w:r\b[^>]*>(.*?)</w:r>#s', $paraXml, $rm)) {
            foreach ($rm[1] as $runInner) {
                if (!preg_match('#<w:rPr\b[^>]*>(.*?)</w:rPr>#s', $runInner, $rprm)) {
                    continue;
                }
                $rPrInner = $rprm[1];
                if ('' === $rFonts && preg_match('#<w:rFonts\b[^>]*/?>#', $rPrInner, $fm)) {
                    $rFonts = $fm[0];
                }
                if ('' === $sz && preg_match('#<w:sz\b[^>]*/?>#', $rPrInner, $sm)) {
                    $sz = $sm[0];
                }
                if ('' === $szCs && preg_match('#<w:szCs\b[^>]*/?>#', $rPrInner, $sm2)) {
                    $szCs = $sm2[0];
                }
                if ('' !== $rFonts && '' !== $sz && '' !== $szCs) {
                    break;
                }
            }
        }

        return ['rFonts' => $rFonts, 'sz' => $sz, 'szCs' => $szCs];
    }

    /**
     * Make sure a single `<w:r>...</w:r>` carries the dominant font/size
     * properties. If the run has no `<w:rPr>`, one is injected at the
     * canonical position (immediately after the opening `<w:r>`). If it has
     * a `<w:rPr>` already, missing rFonts / sz / szCs are appended without
     * disturbing existing formatting (bold, italic, colour, language, …).
     *
     * @param array{rFonts: string, sz: string, szCs: string} $dominant
     */
    private function ensureRunHasFont(string $runXml, array $dominant): string
    {
        // Already has rPr? Add only what's missing.
        if (preg_match('#<w:rPr\b[^>]*>(.*?)</w:rPr>#s', $runXml, $rprm)) {
            $rPrFull = $rprm[0];
            $rPrInner = $rprm[1];
            $needRFonts = '' !== $dominant['rFonts'] && !preg_match('#<w:rFonts\b#', $rPrInner);
            $needSz = '' !== $dominant['sz'] && !preg_match('#<w:sz\b#', $rPrInner);
            $needSzCs = '' !== $dominant['szCs'] && !preg_match('#<w:szCs\b#', $rPrInner);
            if (!$needRFonts && !$needSz && !$needSzCs) {
                return $runXml;
            }
            // <w:rFonts> must come first inside <w:rPr> per the OOXML schema
            // (CT_RPr orders rFonts/b/i/sz/szCs/...). Inject it at the start
            // of the rPr inner; sz / szCs after that is still schema-valid
            // because everything we add precedes the existing children.
            $injection = '';
            if ($needRFonts) {
                $injection .= $dominant['rFonts'];
            }
            if ($needSz) {
                $injection .= $dominant['sz'];
            }
            if ($needSzCs) {
                $injection .= $dominant['szCs'];
            }
            $newRPrInner = $injection.$rPrInner;
            $newRPr = preg_replace(
                '#<w:rPr\b[^>]*>.*?</w:rPr>#s',
                '<w:rPr>'.$newRPrInner.'</w:rPr>',
                $rPrFull,
                1
            ) ?? $rPrFull;

            return str_replace($rPrFull, $newRPr, $runXml);
        }

        // No <w:rPr> at all — inject a fresh one right after `<w:r…>`.
        $injection = '';
        if ('' !== $dominant['rFonts']) {
            $injection .= $dominant['rFonts'];
        }
        if ('' !== $dominant['sz']) {
            $injection .= $dominant['sz'];
        }
        if ('' !== $dominant['szCs']) {
            $injection .= $dominant['szCs'];
        }
        if ('' === $injection) {
            return $runXml;
        }

        return preg_replace(
            '#<w:r\b([^>]*)>#',
            '<w:r$1><w:rPr>'.addcslashes($injection, '\\$').'</w:rPr>',
            $runXml,
            1
        ) ?? $runXml;
    }

    /**
     * Pull the first non-empty run's `<w:rPr>` block out of a paragraph so it
     * can be reused on Phase B-generated child runs (bullet items, date
     * headers in stations.details, …). Returns an empty string if no run in
     * the paragraph declares an rPr — the caller is expected to handle that
     * gracefully (the child runs then degrade to the document default font,
     * which is the pre-fix behaviour).
     */
    private function extractFirstRunRPr(string $paraXml): string
    {
        if (!preg_match_all('#<w:r\b[^>]*>(.*?)</w:r>#s', $paraXml, $rm)) {
            return '';
        }
        foreach ($rm[1] as $idx => $runInner) {
            // Skip runs with no visible text — they often only carry
            // bookmarkStart/instrText etc. and their rPr may be a
            // hyperlink/colour that we don't want to propagate to bullets.
            $hasText = false;
            if (preg_match_all('#<w:t\b[^>]*>([^<]*)</w:t>#s', $runInner, $tm)) {
                foreach ($tm[1] as $txt) {
                    if ('' !== trim($txt)) {
                        $hasText = true;
                        break;
                    }
                }
            }
            if (!$hasText) {
                continue;
            }
            if (preg_match('#<w:rPr\b[^>]*>.*?</w:rPr>#s', $runInner, $rprm)) {
                return $rprm[0];
            }
            // First text-bearing run had no rPr — fall through to the next
            // one in case it carries the font; some templates wrap a
            // placeholder in 1 plain run + 1 styled run.
        }

        return '';
    }

    /**
     * Take an `<w:rPr>...</w:rPr>` fragment and return a copy with `<w:b/>`
     * guaranteed inside it. Used so date headers in stations.details inherit
     * the host font but are still rendered bold. Empty input yields a minimal
     * bold-only rPr (matches the pre-fix behaviour for templates with no
     * detectable host run rPr).
     */
    private function mergeRPrAddBold(string $baseRPr): string
    {
        if ('' === $baseRPr) {
            return '<w:rPr><w:b/></w:rPr>';
        }
        if (preg_match('#<w:b\s*/>#', $baseRPr)) {
            return $baseRPr;
        }
        // Insert <w:b/> immediately after the opening <w:rPr...> tag so it
        // stays at the front of the rPr child list (close to schema order).
        $injected = preg_replace('#(<w:rPr\b[^>]*>)#', '$1<w:b/>', $baseRPr, 1);

        return is_string($injected) ? $injected : $baseRPr;
    }

    /**
     * Like {@see mergeRPrAddBold} but forces italic (<w:i/>). Used to render
     * the employer name italic in umbrella / nested station lines (feedback
     * #10: "Arbeitgeber kursiv geschrieben").
     */
    private function mergeRPrAddItalic(string $baseRPr): string
    {
        if ('' === $baseRPr) {
            return '<w:rPr><w:i/></w:rPr>';
        }
        if (preg_match('#<w:i\s*/>#', $baseRPr)) {
            return $baseRPr;
        }
        $injected = preg_replace('#(<w:rPr\b[^>]*>)#', '$1<w:i/>', $baseRPr, 1);

        return is_string($injected) ? $injected : $baseRPr;
    }

    /**
     * Add (or bump) a left indent on a paragraph's `<w:pPr>` so a whole
     * station block can be shifted right one level (feedback #10: nested
     * positions "auf Höhe der Position einrücken"). Additive: an existing
     * `w:left` is increased by $twips; otherwise a fresh `<w:ind>` is added.
     */
    private function addLeftIndent(string $pPrXml, int $twips): string
    {
        if ($twips <= 0) {
            return $pPrXml;
        }
        if ('' === $pPrXml) {
            return '<w:pPr><w:ind w:left="'.$twips.'"/></w:pPr>';
        }
        if (1 === preg_match('#<w:ind\b[^>]*\bw:left="(\d+)"#', $pPrXml, $m)) {
            $newLeft = (int) $m[1] + $twips;
            $out = preg_replace('#(<w:ind\b[^>]*\bw:left=")\d+(")#', '${1}'.$newLeft.'$2', $pPrXml, 1);

            return is_string($out) ? $out : $pPrXml;
        }
        if (1 === preg_match('#<w:ind\b[^>]*/>#', $pPrXml)) {
            $out = preg_replace('#(<w:ind\b[^>]*?)(/>)#', '$1 w:left="'.$twips.'"$2', $pPrXml, 1);

            return is_string($out) ? $out : $pPrXml;
        }
        // No <w:ind> yet — insert one right after the opening <w:pPr...>.
        $out = preg_replace('#(<w:pPr\b[^>]*>)#', '$1<w:ind w:left="'.$twips.'"/>', $pPrXml, 1);

        return is_string($out) ? $out : $pPrXml;
    }

    /**
     * Pre-pass: for each list-type placeholder, find the Word paragraph (<w:p>)
     * that contains it and clone the entire paragraph once per list item. This
     * preserves paragraph formatting (bullet style via <w:numPr>, indentation,
     * justification, run properties) so each item renders as a proper paragraph
     * in Word instead of line-break text inside a single paragraph.
     *
     * Two outcomes per placeholder key:
     *  - Expanded: key is returned in the result; the placeholder no longer exists
     *    in the DOCX and PhpWord will simply ignore it.
     *  - Not expanded (placeholder missing, inline, or the regex could not match):
     *    key is left in place and processLists() handles it via the <w:br/> fallback.
     *
     * Empty lists are skipped here and fall through to processLists(), which
     * keeps the placeholder visible with a yellow highlight.
     *
     * @param list<string>                        $listKeys
     * @param array<string, mixed>                $variables
     * @param array<string, mixed>                $arrays
     * @param array<string, array<string, mixed>> $designerMap
     *
     * @return list<string> keys that were successfully expanded
     */
    private function expandListParagraphs(string $docxPath, array $listKeys, array $variables, array $arrays, array $designerMap = []): array
    {
        if (empty($listKeys)) {
            return [];
        }

        $zip = new \ZipArchive();
        if (true !== $zip->open($docxPath)) {
            $this->logger->warning('Failed to open DOCX for list expansion', ['path' => $docxPath]);

            return [];
        }

        $xml = $zip->getFromName('word/document.xml');
        if (false === $xml) {
            $zip->close();

            return [];
        }

        $numberingXml = $zip->getFromName('word/numbering.xml');
        $orderedNumId = is_string($numberingXml) ? $this->detectOrderedNumId($numberingXml) : null;
        $bulletNumId = is_string($numberingXml) ? $this->detectBulletNumId($numberingXml) : null;

        $originalXml = $xml;
        $expanded = [];

        foreach ($listKeys as $key) {
            $raw = array_key_exists($key, $variables) ? $variables[$key] : ($arrays[$key] ?? null);
            $items = $this->normalizeListValue($raw);

            // Empty lists are left for processLists(), which keeps the
            // placeholder visible with a yellow highlight instead of
            // dropping the host paragraph. (Empty variables must survive
            // generation so the customer can spot and fill them later.)
            if ([] === $items || null === $items) {
                continue;
            }

            $placeholder = '{{'.$key.'}}';
            if (!str_contains($xml, $placeholder)) {
                continue;
            }

            $designer = $designerMap[$key] ?? [];
            $wantsOrdered = ($designer['list_style'] ?? null) === 'ol';
            $preventOrphans = !empty($designer['prevent_orphans']);
            $topBlankLine = !empty($designer['top_blank_line']);
            $bottomBlankLine = !empty($designer['bottom_blank_line']);

            // Non-greedy match of the <w:p>...</w:p> containing the placeholder.
            // The negative lookahead on </w:p> keeps us inside one paragraph.
            $pattern = '#<w:p\b[^>]*>(?:(?!</w:p>).)*?'.preg_quote($placeholder, '#').'(?:(?!</w:p>).)*?</w:p>#s';

            $replacementCount = 0;
            $newXml = preg_replace_callback(
                $pattern,
                function (array $match) use ($placeholder, $items, $wantsOrdered, $preventOrphans, $topBlankLine, $bottomBlankLine, $orderedNumId, $bulletNumId, &$replacementCount): string {
                    ++$replacementCount;
                    $paragraph = $match[0];

                    // If the designer wants an ordered (OL) list and the template
                    // paragraph has bullet numPr, rewrite numPr to point at the
                    // detected ordered numId. If no numPr exists, synthesize one.
                    $paragraphForItem = $paragraph;
                    if ($wantsOrdered && null !== $orderedNumId) {
                        $paragraphForItem = $this->swapListNumPr($paragraphForItem, $orderedNumId);
                    } elseif (!$wantsOrdered && null !== $bulletNumId) {
                        // No change needed usually, but ensure bullet numId if we
                        // detect the paragraph has a numPr referring to an OL id
                        // that happens to equal the ordered one. Best-effort.
                    }
                    if ($preventOrphans) {
                        $paragraphForItem = $this->addKeepNext($paragraphForItem);
                    }

                    $out = '';
                    if ($topBlankLine) {
                        $out .= $this->buildBlankSpacerParagraph($paragraph);
                    }
                    $lastIdx = count($items) - 1;
                    foreach ($items as $idx => $item) {
                        // The last item drops `keepNext` even when preventing orphans,
                        // so the list can still break naturally at its end.
                        $paraForThisItem = ($preventOrphans && $idx === $lastIdx)
                            ? $paragraph
                            : $paragraphForItem;
                        $escaped = $this->escapeForWordXml($item);
                        $out .= str_replace($placeholder, $escaped, $paraForThisItem);
                    }
                    if ($bottomBlankLine) {
                        $out .= $this->buildBlankSpacerParagraph($paragraph);
                    }

                    return $out;
                },
                $xml
            );

            if (null !== $newXml && $replacementCount > 0) {
                $xml = $newXml;
                $expanded[] = $key;
            }
        }

        if ($xml !== $originalXml) {
            $zip->addFromString('word/document.xml', $xml);
        }
        $zip->close();

        return $expanded;
    }

    /**
     * Rewrite a paragraph's <w:numPr> so its <w:numId> points at the given id.
     * Used to flip bullet paragraphs into ordered-list paragraphs (and vice versa)
     * without losing the paragraph's other formatting (run props, indentation,
     * justification, etc.). When the paragraph has no numPr yet, we insert one
     * with level 0.
     */
    private function swapListNumPr(string $paragraphXml, int $numId): string
    {
        if (preg_match('#<w:numPr\b[^/]*?>.*?</w:numPr>#s', $paragraphXml)) {
            return preg_replace_callback(
                '#<w:numPr\b[^/]*?>.*?</w:numPr>#s',
                static function () use ($numId): string {
                    return '<w:numPr><w:ilvl w:val="0"/><w:numId w:val="'.$numId.'"/></w:numPr>';
                },
                $paragraphXml,
                1
            ) ?? $paragraphXml;
        }

        $numPr = '<w:numPr><w:ilvl w:val="0"/><w:numId w:val="'.$numId.'"/></w:numPr>';
        if (preg_match('#<w:pPr\b[^>]*>#', $paragraphXml)) {
            return preg_replace('#(<w:pPr\b[^>]*>)#', '$1'.$numPr, $paragraphXml, 1) ?? $paragraphXml;
        }

        // No pPr: inject a minimal one right after the opening <w:p …>.
        return preg_replace(
            '#(<w:p\b[^>]*>)#',
            '$1<w:pPr>'.$numPr.'</w:pPr>',
            $paragraphXml,
            1
        ) ?? $paragraphXml;
    }

    /**
     * Build an empty Word paragraph used as a "blank line" before/after a
     * list. We strip out the source paragraph's bullet numPr so the spacer
     * does not show a stray bullet, but otherwise inherit its run/paragraph
     * properties (font, indentation, alignment) so the spacer's height
     * matches the surrounding list visually.
     */
    private function buildBlankSpacerParagraph(string $sourceParagraphXml): string
    {
        // Best-effort: pull the original <w:pPr> if present so the spacer's
        // line-height matches the list. Strip <w:numPr> to avoid showing a
        // bullet. Strip <w:keepNext/> so the spacer doesn't glue to the
        // next paragraph.
        $pPr = '';
        if (preg_match('#<w:pPr\b[^>]*>.*?</w:pPr>#s', $sourceParagraphXml, $m)) {
            $pPr = $m[0];
            $pPr = preg_replace('#<w:numPr\b.*?</w:numPr>#s', '', $pPr) ?? $pPr;
            $pPr = preg_replace('#<w:keepNext\s*/>#', '', $pPr) ?? $pPr;
        }

        return '<w:p>'.$pPr.'</w:p>';
    }

    /**
     * Add a <w:keepNext/> directive to a paragraph's <w:pPr>. Used to glue
     * list items together across page boundaries so a dangling last item is
     * either kept with the previous one or pushed to the next page as a block.
     */
    private function addKeepNext(string $paragraphXml): string
    {
        if (preg_match('#<w:keepNext\s*/>#', $paragraphXml)) {
            return $paragraphXml;
        }
        if (preg_match('#<w:pPr\b[^>]*>#', $paragraphXml)) {
            return preg_replace('#(<w:pPr\b[^>]*>)#', '$1<w:keepNext/>', $paragraphXml, 1) ?? $paragraphXml;
        }

        return preg_replace(
            '#(<w:p\b[^>]*>)#',
            '$1<w:pPr><w:keepNext/></w:pPr>',
            $paragraphXml,
            1
        ) ?? $paragraphXml;
    }

    /**
     * Phase T pre-pass: expand `{{varname}}` placeholders that reference a
     * `table`-typed form field with declared columns into a fully rendered
     * table row sequence.
     *
     * For each qualifying field with non-empty array-of-object data:
     *   - locate the first <w:tc> containing `{{varname}}`
     *   - use its host <w:tr> as the row template
     *   - within that template, treat each of the row's cells left-to-right as
     *     one column (matching declared `columns[]` in order)
     *   - clone the row once per data row, substituting each cell's inner text
     *     with the matching column value for that data row
     *
     * Templates that still use the legacy `{{varname.col.N}}` syntax are
     * left untouched and keep flowing through the existing `processRowGroups`
     * / `cloneParagraphGroupsPrepass` paths.
     *
     * @param array<int, array<string, mixed>>                $formFields
     * @param array<string, array<int, array<string, mixed>>> $arrays
     *
     * @return array<string, true> keys that were expanded
     */
    private function expandTableBlocks(string $docxPath, array $formFields, array $arrays, array $richSubfields = self::RICH_ROW_SUBFIELDS_DEFAULT): array
    {
        $handled = [];

        $tableFields = [];
        foreach ($formFields as $field) {
            $key = $field['key'] ?? '';
            $type = $field['type'] ?? '';
            $cols = $field['columns'] ?? [];
            if ('' === $key || 'table' !== $type || !is_array($cols) || 0 === count($cols)) {
                continue;
            }
            $data = $arrays[$key] ?? null;
            if (!is_array($data) || empty($data) || !is_array($data[0] ?? null)) {
                continue;
            }
            $tableFields[$key] = [
                'columns' => array_values(array_filter($cols, fn ($c) => is_array($c) && !empty($c['key']))),
                'data' => $data,
            ];
        }

        if (empty($tableFields)) {
            return $handled;
        }

        $zip = new \ZipArchive();
        if (true !== $zip->open($docxPath)) {
            return $handled;
        }

        $xml = $zip->getFromName('word/document.xml');
        if (false === $xml) {
            $zip->close();

            return $handled;
        }

        $originalXml = $xml;

        foreach ($tableFields as $key => $cfg) {
            $token = '{{'.$key.'}}';
            if (!str_contains($xml, $token)) {
                continue;
            }

            // Locate the nearest <w:tr> that contains the token — but only if the
            // token lives inside a <w:tbl>. Placeholders outside a table fall
            // back to the normal list/scalar path.
            $tokenPos = strpos($xml, $token);
            if (false === $tokenPos) {
                continue;
            }
            $before = substr($xml, 0, $tokenPos);
            $tblOpen = strrpos($before, '<w:tbl>');
            $tblClose = strrpos($before, '</w:tbl>');
            $insideTable = false !== $tblOpen && (false === $tblClose || $tblOpen > $tblClose);
            if (!$insideTable) {
                continue;
            }

            $trPattern = '#<w:tr\b[^>]*>(?:(?!</w:tr>).)*?'.preg_quote($token, '#').'(?:(?!</w:tr>).)*?</w:tr>#s';
            if (1 !== preg_match($trPattern, $xml, $trMatch, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $rowTemplate = $trMatch[0][0];
            $rowStart = $trMatch[0][1];
            $rowEnd = $rowStart + strlen($rowTemplate);

            // Extract each <w:tc>…</w:tc> left-to-right and find inner text.
            if (!preg_match_all('#<w:tc\b[^>]*>(?:(?!</w:tc>).)*?</w:tc>#s', $rowTemplate, $cellMatches)) {
                continue;
            }
            $cells = $cellMatches[0];
            $cellCount = count($cells);
            if (0 === $cellCount) {
                continue;
            }

            $columns = $cfg['columns'];
            $maxColumns = min($cellCount, count($columns));
            if (0 === $maxColumns) {
                continue;
            }

            // Build one fresh row per data entry.
            $allRows = '';
            $dataIdx = 0;
            foreach ($cfg['data'] as $rowData) {
                if (!is_array($rowData)) {
                    continue;
                }
                ++$dataIdx;
                $newRow = $rowTemplate;
                // Replace the placeholder token first so it never leaks.
                $newRow = str_replace($token, '', $newRow);
                // Walk cells left-to-right, substituting the first <w:t>…</w:t>
                // (or injecting a new run if missing) with the column value.
                $cellIdx = 0;
                $newRow = preg_replace_callback(
                    '#<w:tc\b[^>]*>(?:(?!</w:tc>).)*?</w:tc>#s',
                    function (array $cm) use (&$cellIdx, $columns, $rowData, $maxColumns, $key, $richSubfields, $dataIdx): string {
                        $cell = $cm[0];
                        if ($cellIdx >= $maxColumns) {
                            ++$cellIdx;

                            return $cell;
                        }
                        $colKey = $columns[$cellIdx]['key'] ?? '';
                        ++$cellIdx;
                        if ('' === $colKey) {
                            return $cell;
                        }

                        // Rich column? Leave a token for expandRichRowColumns to
                        // replace post-save with real bullet paragraphs. The
                        // {{...#N}} syntax is the same one processRowGroups uses,
                        // so the post-pass works uniformly for both row-flow modes.
                        $richKey = $key.'.'.$colKey;
                        if (in_array($richKey, $richSubfields, true)) {
                            $placeholder = '{{'.$richKey.'#'.$dataIdx.'}}';
                            $escaped = $placeholder;
                        } else {
                            $value = (string) ($rowData[$colKey] ?? '');
                            $escaped = $this->escapeForWordXml($value);
                        }

                        // Replace first <w:t>…</w:t> with the escaped value.
                        if (preg_match('#<w:t\b[^>]*>.*?</w:t>#s', $cell)) {
                            return preg_replace(
                                '#<w:t\b[^>]*>.*?</w:t>#s',
                                '<w:t xml:space="preserve">'.$escaped.'</w:t>',
                                $cell,
                                1
                            ) ?? $cell;
                        }

                        // No run at all — inject one before </w:tc>.
                        return preg_replace(
                            '#</w:tc>\s*$#s',
                            '<w:p><w:r><w:t xml:space="preserve">'.$escaped.'</w:t></w:r></w:p></w:tc>',
                            $cell,
                            1
                        ) ?? $cell;
                    },
                    $newRow
                ) ?? $newRow;
                $allRows .= $newRow;
            }

            if ('' === $allRows) {
                continue;
            }

            $xml = substr($xml, 0, $rowStart).$allRows.substr($xml, $rowEnd);
            $handled[$key] = true;
        }

        if ($xml !== $originalXml) {
            $zip->addFromString('word/document.xml', $xml);
        }
        $zip->close();

        return $handled;
    }

    /**
     * Phase C pre-pass: clone paragraph-based row groups that are not inside a
     * Word table row. PhpWord's TemplateProcessor::cloneRow() only works on
     * <w:tr> structures — templates that lay out a repeating block as a
     * sequence of plain paragraphs (e.g. one paragraph per field) previously
     * had their placeholders silently cleared when cloneRow threw. This method
     * is the paragraph-level equivalent: it finds the smallest contiguous
     * <w:p>…<w:p> range covering all of a group's placeholders, duplicates
     * that range once per row of array data, and substitutes simple sub-field
     * placeholders inline. Rich sub-fields listed in RICH_ROW_SUBFIELDS are
     * suffixed with #N and left for Phase B's post-save renderer.
     *
     * Placeholders already inside a <w:tr> are skipped — cloneRow is still the
     * right tool for table-based layouts.
     *
     * @param array<string, list<string>> $rowGroups groupName => list of full placeholder keys (e.g. "stations.time.N")
     * @param array<string, mixed>        $arrays
     *
     * @return array<string, true> set of group names handled by this pre-pass
     */
    private function cloneParagraphGroupsPrepass(string $docxPath, array $rowGroups, array $arrays, array $richSubfields = self::RICH_ROW_SUBFIELDS_DEFAULT): array
    {
        $handled = [];
        if (empty($rowGroups)) {
            return $handled;
        }

        $zip = new \ZipArchive();
        if (true !== $zip->open($docxPath)) {
            return $handled;
        }

        $xml = $zip->getFromName('word/document.xml');
        if (false === $xml) {
            $zip->close();

            return $handled;
        }

        $originalXml = $xml;

        foreach ($rowGroups as $groupName => $placeholders) {
            if (!is_array($placeholders) || empty($placeholders)) {
                continue;
            }

            $data = $arrays[$groupName] ?? [];
            if (!is_array($data)) {
                continue;
            }
            $count = count($data);
            if (0 === $count) {
                continue;
            }

            // If the first placeholder lives inside a <w:tr>, defer to cloneRow.
            $firstProbe = '{{'.$placeholders[0].'}}';
            $firstPos = strpos($xml, $firstProbe);
            if (false === $firstPos) {
                continue;
            }
            $before = substr($xml, 0, $firstPos);
            $trOpenA = strrpos($before, '<w:tr ');
            $trOpenB = strrpos($before, '<w:tr>');
            $trOpen = max(false === $trOpenA ? -1 : $trOpenA, false === $trOpenB ? -1 : $trOpenB);
            $trClose = strrpos($before, '</w:tr>');
            $insideRow = -1 !== $trOpen && (false === $trClose || $trOpen > $trClose);
            if ($insideRow) {
                continue;
            }

            // Locate every <w:p>…</w:p> paragraph that contains any group placeholder.
            preg_match_all('#<w:p\b[^>]*>(?:(?!</w:p>).)*?</w:p>#s', $xml, $paragraphs, PREG_OFFSET_CAPTURE);
            if (empty($paragraphs[0])) {
                continue;
            }

            $hitIndices = [];
            foreach ($paragraphs[0] as $idx => $entry) {
                foreach ($placeholders as $ph) {
                    if (str_contains($entry[0], '{{'.$ph.'}}')) {
                        $hitIndices[] = $idx;
                        break;
                    }
                }
            }
            if (empty($hitIndices)) {
                continue;
            }

            $firstIdx = min($hitIndices);
            $lastIdx = max($hitIndices);
            $rangeStart = $paragraphs[0][$firstIdx][1];
            $lastEntry = $paragraphs[0][$lastIdx];
            $rangeEnd = $lastEntry[1] + strlen($lastEntry[0]);
            $blockXml = substr($xml, $rangeStart, $rangeEnd - $rangeStart);

            // Build N copies with inline substitution. Rich sub-fields are suffixed
            // but not replaced — Phase B will expand them after saveAs.
            $allCopies = '';
            for ($n = 1; $n <= $count; ++$n) {
                $row = is_array($data[$n - 1] ?? null) ? $data[$n - 1] : [];
                $copy = $blockXml;
                foreach ($placeholders as $ph) {
                    $suffix = substr($ph, strlen($groupName) + 1);
                    $cleanSubfield = str_replace('.N', '', $suffix);
                    $richKey = "{$groupName}.{$cleanSubfield}";
                    $token = '{{'.$ph.'}}';

                    if (in_array($richKey, $richSubfields, true)) {
                        $copy = str_replace($token, '{{'.$ph.'#'.$n.'}}', $copy);
                        continue;
                    }

                    $value = $row[$cleanSubfield] ?? '';
                    $value = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                    if (str_contains($value, "\n")) {
                        $value = str_replace("\n", '</w:t><w:br/><w:t>', $value);
                    }
                    $copy = str_replace($token, $value, $copy);
                }
                $allCopies .= $copy;
            }

            $xml = substr($xml, 0, $rangeStart).$allCopies.substr($xml, $rangeEnd);
            $handled[$groupName] = true;
        }

        if ($xml !== $originalXml) {
            $zip->addFromString('word/document.xml', $xml);
        }
        $zip->close();

        return $handled;
    }

    /**
     * Normalize a list-type variable value into an array of non-empty trimmed strings.
     * Accepts arrays (mixed element types are cast to string), newline- or
     * semicolon-separated strings, or null. Returns null only for genuinely
     * missing values (distinct from empty list []), so the caller can
     * distinguish "drop the paragraph because empty" from "do nothing".
     */
    private function normalizeListValue(mixed $val): ?array
    {
        if (null === $val) {
            return null;
        }
        if (is_array($val)) {
            $out = [];
            foreach ($val as $entry) {
                if (is_scalar($entry)) {
                    $s = trim((string) $entry);
                    if ('' !== $s) {
                        $out[] = $s;
                    }
                }
            }

            return $out;
        }
        if (is_string($val)) {
            $parts = preg_split('/\r\n|\r|\n/', $val) ?: [];
            $out = [];
            foreach ($parts as $p) {
                $p = trim($p);
                if ('' !== $p) {
                    $out[] = $p;
                }
            }

            return $out;
        }

        return null;
    }

    /**
     * XML-escape a user string for embedding inside a <w:t>...</w:t> run.
     * Intra-item newlines are preserved as <w:br/> soft breaks within the
     * same paragraph (for multi-line list items such as "Deutsch\n(Muttersprache)").
     */
    private function escapeForWordXml(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        if (1 === preg_match('/\r|\n/', $escaped)) {
            $escaped = preg_replace('/\r\n|\r|\n/', '</w:t><w:br/><w:t>', $escaped);
        }

        return $escaped;
    }

    /**
     * Build the list of "rich" row sub-fields — `{group}.{col}` strings whose
     * cell content must be rendered as a sequence of bullet paragraphs instead
     * of a single text run. Two things feed into the list:
     *
     *   - the default constant (always includes `stations.details` for back-compat
     *     with forms created before column-level `type` was supported)
     *   - every `table` variable whose column declares `type=list`
     *
     * Used by processRowGroups / cloneParagraphGroupsPrepass / expandTableBlocks
     * to leave the cell as a `{{group.col.N#i}}` placeholder that the Phase B
     * post-pass (`expandRichRowColumns`) then expands into real paragraphs.
     *
     * @param array<int, array<string, mixed>> $formFields
     *
     * @return list<string>
     */
    private function getRichRowSubfields(array $formFields): array
    {
        $rich = [];
        foreach (self::RICH_ROW_SUBFIELDS_DEFAULT as $k) {
            $rich[$k] = true;
        }
        foreach ($formFields as $field) {
            if (($field['type'] ?? '') !== 'table' || empty($field['key'])) {
                continue;
            }
            $group = (string) $field['key'];
            foreach (($field['columns'] ?? []) as $col) {
                if (!is_array($col) || empty($col['key'])) {
                    continue;
                }
                if (($col['type'] ?? 'text') === 'list') {
                    $rich["{$group}.{$col['key']}"] = true;
                }
            }
        }

        return array_keys($rich);
    }

    /**
     * Phase B post-pass: after TemplateProcessor has saved the DOCX, every
     * rich-column placeholder (left intact by processRowGroups / expandTableBlocks)
     * is replaced with a sequence of real <w:p> elements — one bullet per array
     * item, or — when the incoming value is still a multi-line string — the
     * legacy "parseStationDetails" heuristic renderer (date-range detection,
     * dash-prefixed bullets, spacers) for back-compat with existing datasets
     * that carry unstructured text.
     */
    /**
     * Deterministic post-pass dedup: drop any bullet from a rich-list
     * column whose text equals (or is prefixed by) another column's
     * value in the same row. Catches the "Interim-CTO" position that
     * the AI sometimes leaks into `details` even after explicit
     * prompt rules forbid it — typically because the source CV line
     * starts with the role title and the AI copies the whole line as
     * the first bullet.
     *
     * Match modes (case-insensitive, whitespace-tolerant):
     *   - exact equality  ("Interim-CTO" === "Interim-CTO")
     *   - bullet starts with the sibling value, optionally followed
     *     by punctuation/space ("Interim-CTO – 5 team", "Interim-CTO,
     *     Berlin", "Interim-CTO bei Vicoland")
     *   - bullet IS the sibling value with simple wrapping
     *     ("Position: Interim-CTO" — strip "Position: " labels too)
     *
     * Only sibling values longer than 3 chars participate, to avoid
     * stripping legitimate bullets that happen to start with a
     * generic word ("CEO" alone is long enough to match; "of" is
     * not).
     *
     * @param list<mixed>          $bullets The detail bullets the AI returned
     * @param array<string, mixed> $row     The full row dict (so we can read time/employer/position siblings)
     * @param string               $thisCol The column being rendered (we don't dedup against ourselves)
     *
     * @return list<string>
     */
    private function dedupeBulletsAgainstSiblings(array $bullets, array $row, string $thisCol): array
    {
        $siblingValues = [];
        foreach ($row as $col => $val) {
            if ($col === $thisCol) {
                continue;
            }
            if (!is_string($val)) {
                continue;
            }
            $clean = trim($val);
            if (mb_strlen($clean) < 4) {
                continue;
            }
            $siblingValues[] = $clean;
        }
        if (empty($siblingValues)) {
            return array_values(array_map(static fn ($b) => is_string($b) ? $b : (string) $b, $bullets));
        }

        $labels = ['Position', 'Tätigkeit', 'Rolle', 'Job', 'Aufgaben', 'Verantwortung', 'Firma', 'Arbeitgeber', 'Zeitraum'];
        $labelPrefix = '/^\s*(?:'.implode('|', array_map('preg_quote', $labels)).')\s*[:\-–]\s*/iu';

        $kept = [];
        foreach ($bullets as $bullet) {
            if (!is_string($bullet)) {
                $bullet = (string) $bullet;
            }
            $b = trim($bullet);
            if ('' === $b) {
                continue;
            }
            // Strip leading "Label: " from the bullet for comparison
            $bForMatch = preg_replace($labelPrefix, '', $b) ?? $b;
            $bLower = mb_strtolower($bForMatch);

            $isDup = false;
            foreach ($siblingValues as $sib) {
                $sibLower = mb_strtolower($sib);
                if ($bLower === $sibLower) {
                    $isDup = true;
                    break;
                }
                if (str_starts_with($bLower, $sibLower)) {
                    // Position followed by a separator or end-of-string
                    $tail = substr($bLower, strlen($sibLower));
                    if ('' === $tail || 1 === preg_match('/^[\s\-–:,;\.\(\)\/]/u', $tail)) {
                        $isDup = true;
                        break;
                    }
                }
            }
            if (!$isDup) {
                $kept[] = $bullet;
            } else {
                $this->logger->info('Synaform: dropped bullet duplicating sibling column', [
                    'bullet' => mb_substr($b, 0, 100),
                    'matched_sibling' => 'col_value',
                ]);
            }
        }

        return $kept;
    }

    private function expandRichRowColumns(string $docxPath, array $richSubfields, array $arrays, array $formFields): void
    {
        if (empty($richSubfields)) {
            return;
        }

        // Map "group.col" → declared column `type` and `structured` flag, so
        // the renderer knows whether the stored value is an array or a legacy
        // string AND whether array items should be classified into structured
        // station blocks (date / position title / tasks) or rendered as a flat
        // bullet list.
        $columnTypes = [];
        $columnStructured = [];
        $columnBullet = [];
        foreach ($formFields as $field) {
            if (($field['type'] ?? '') !== 'table' || empty($field['key'])) {
                continue;
            }
            $group = (string) $field['key'];
            foreach (($field['columns'] ?? []) as $col) {
                if (!is_array($col) || empty($col['key'])) {
                    continue;
                }
                $colKey = (string) $col['key'];
                $combined = "{$group}.{$colKey}";
                $columnTypes[$combined] = (string) ($col['type'] ?? 'text');
                $columnStructured[$combined] = $this->isStructuredListColumn($col);
                $columnBullet[$combined] = $this->bulletStyleForColumn($col);
            }
        }

        $zip = new \ZipArchive();
        if (true !== $zip->open($docxPath)) {
            $this->logger->warning('Failed to open DOCX for rich-column expansion', ['path' => $docxPath]);

            return;
        }

        $xml = $zip->getFromName('word/document.xml');
        if (false === $xml) {
            $zip->close();

            return;
        }

        $numberingXml = $zip->getFromName('word/numbering.xml');
        $bulletNumId = is_string($numberingXml) ? $this->detectBulletNumId($numberingXml) : null;

        $originalXml = $xml;

        foreach ($richSubfields as $richKey) {
            [$group, $col] = array_pad(explode('.', $richKey, 2), 2, '');
            if ('' === $group || '' === $col) {
                continue;
            }
            $rows = $arrays[$group] ?? [];
            if (!is_array($rows)) {
                continue;
            }
            $columnType = $columnTypes[$richKey] ?? 'text';
            // Default-on for stations.details (legacy back-compat: this column
            // has always rendered with date / title / bullet structure when
            // fed a multi-line string).
            $structured = $columnStructured[$richKey] ?? ('stations.details' === $richKey);
            $bulletStyle = $columnBullet[$richKey] ?? [];

            foreach ($rows as $i => $row) {
                $num = $i + 1;

                // Pull the value — an array means the user/AI returned structured
                // bullets, a string means legacy markdown-ish prose.
                $raw = null;
                if (is_array($row)) {
                    $raw = $row[$col] ?? null;
                } elseif (is_string($row) && 'details' === $col) {
                    // Legacy "stations" entries stored as plain strings.
                    $raw = $row;
                }

                // Deterministic safety net: even if the AI ignored the
                // prompt rule "never repeat the row's position/employer/time
                // as a bullet" (and they sometimes do, depending on model and
                // CV format), strip any bullet that equals or is prefixed by
                // a sibling-column value. This guarantees the rendered
                // template never shows the position twice — once as the
                // {{stations.position.N}} paragraph above the bullets, and
                // once as the first bullet itself.
                if (is_array($raw) && is_array($row)) {
                    $raw = $this->dedupeBulletsAgainstSiblings($raw, $row, $col);
                }

                // WS-F: umbrella/nested context for the structured details
                // renderer (feedback #9/#10). Only meaningful for the
                // structured column; ignored otherwise. `sub_of_previous`
                // tolerates bool or "true"/"1"/"ja" strings from the editor.
                $rowContext = [];
                if ($structured && is_array($row)) {
                    $subRaw = $row['sub_of_previous'] ?? null;
                    $sub = true === $subRaw
                        || (is_string($subRaw) && in_array(strtolower(trim($subRaw)), ['1', 'true', 'ja', 'yes'], true));
                    $rowContext = [
                        'time_total' => (string) ($row['time_total'] ?? ''),
                        'employer' => (string) ($row['employer'] ?? ''),
                        'sub_of_previous' => $sub,
                    ];
                }

                foreach (["{{{$richKey}.N#{$num}}}", "{{{$richKey}#{$num}}}"] as $placeholder) {
                    if (!str_contains($xml, $placeholder)) {
                        continue;
                    }

                    $isEmpty = null === $raw
                        || '' === $raw
                        || (is_array($raw) && empty(array_filter($raw, static fn ($v) => '' !== trim((string) $v))));
                    if ($isEmpty) {
                        $xml = str_replace($placeholder, '', $xml);
                        continue;
                    }

                    $pattern = '#<w:p\b[^>]*>(?:(?!</w:p>).)*?'.preg_quote($placeholder, '#').'(?:(?!</w:p>).)*?</w:p>#s';
                    $replaced = preg_replace_callback(
                        $pattern,
                        function (array $m) use ($raw, $columnType, $bulletNumId, $structured, $bulletStyle, $rowContext): string {
                            $basePPr = '';
                            if (preg_match('#<w:pPr\b[^>]*>.*?</w:pPr>#s', $m[0], $pm)) {
                                $basePPr = $pm[0];
                            }
                            // Inherit the placeholder run's rPr (font/size/colour)
                            // so generated bullets/date headers stay in the same
                            // typeface as the surrounding cell content. Without
                            // this, the new <w:r>s would fall back to the
                            // document default font, which is rarely what the
                            // template designer picked.
                            $baseRPr = $this->extractFirstRunRPr($m[0]);

                            return $this->renderRichColumnXml(
                                $raw,
                                $columnType,
                                $basePPr,
                                $bulletNumId,
                                $structured,
                                $baseRPr,
                                $bulletStyle,
                                $rowContext,
                            );
                        },
                        $xml
                    );

                    if (null !== $replaced && $replaced !== $xml) {
                        $xml = $replaced;
                    } else {
                        // Regex couldn't locate the host paragraph. Fall back to
                        // a safe line-break substitution so the cell is never
                        // left with a raw placeholder.
                        $fallback = is_array($raw)
                            ? implode("\n", array_map('strval', $raw))
                            : (string) $raw;
                        $xml = str_replace($placeholder, $this->escapeForWordXml($fallback), $xml);
                    }
                }
            }
        }

        if ($xml !== $originalXml) {
            $zip->addFromString('word/document.xml', $xml);
        }
        $zip->close();
    }

    /**
     * Render a rich column value into a sequence of <w:p> OOXML paragraphs.
     *
     * Two array-shape paths:
     *   - "structured" (auto-enabled for the `details` sub-field, opt-in via
     *     designer for other columns): items are classified into date
     *     headers, position titles and tasks (bullets). This mirrors what the
     *     legacy {@see parseStationDetails} multi-line string path produces,
     *     so HR templates render with the same date-bold + title-no-bullet +
     *     tasks-as-bullets layout regardless of whether the data was stored
     *     as a string or as a JSON array.
     *   - "flat" (default for skill-style list columns like languages,
     *     other_skills): every non-empty item becomes a bullet. Same behavior
     *     as before this method existed.
     *
     * Multi-line string values still flow through {@see renderStationDetailsXml}
     * for back-compat with datasets created before column-level types existed.
     *
     * @param array<int, mixed>|string|null $raw
     */
    private function renderRichColumnXml(
        array|string|null $raw,
        string $columnType,
        string $basePPr,
        ?int $bulletNumId,
        bool $structured = false,
        string $baseRPr = '',
        array $bulletStyle = [],
        array $rowContext = [],
    ): string {
        if (is_array($raw)) {
            $items = array_map(static fn ($v) => trim((string) $v), $raw);

            if ($structured) {
                $blocks = $this->classifyListItems($items);
                $hasUmbrella = '' !== trim((string) ($rowContext['time_total'] ?? ''));
                if (empty($blocks) && !$hasUmbrella) {
                    return '';
                }

                return $this->renderStationBlocksXml($blocks, $basePPr, $bulletNumId, $baseRPr, $bulletStyle, $rowContext);
            }

            $items = array_values(array_filter($items, static fn ($v) => '' !== $v));
            if (empty($items)) {
                return '';
            }

            return $this->renderBulletList($items, $basePPr, $bulletNumId, $baseRPr, $bulletStyle);
        }

        // Legacy string path: keep the date-header / dash-bullet heuristic so
        // existing "stations.details" prose still renders nicely.
        $str = (string) ($raw ?? '');
        if ('' === trim($str)) {
            return '';
        }

        return $this->renderStationDetailsXml($str, $basePPr, $bulletNumId, $baseRPr, $bulletStyle);
    }

    /**
     * Helper: emit `<w:p>` paragraphs — one per item — using the bullet
     * numbering defined in the template's numbering.xml (falling back to a
     * "• " character prefix if no numId is available).
     *
     * @param list<string> $items
     */
    private function renderBulletList(array $items, string $basePPr, ?int $bulletNumId, string $baseRPr = '', array $bulletStyle = []): string
    {
        // `<w:widowControl w:val="0"/>` lets Word split a single long
        // bullet that wraps to 4–5 lines across a page boundary. The
        // default (widowControl on) keeps at least 2 lines together at
        // the start/end of every paragraph, which can push a long
        // bullet entirely to the next page when only 1 line would fit
        // at the bottom — making the previous page look short. Each
        // bullet is its own paragraph with no keepNext, so successive
        // bullets always break freely between each other.
        // The bullet GLYPH (• or the numbering symbol) takes its size and font
        // from the paragraph-MARK run properties, not the text run. If we leave
        // them unset, the dot renders at the Normal-style default while the text
        // uses the host's size — exactly the "bullets in different sizes"
        // complaint. Re-using the host run's rPr as the paragraph-mark rPr (the
        // last child of pPr, per the OOXML schema) keeps glyph and text in sync.
        $markRPr = $baseRPr;
        [$bulletPPr, $charPrefix] = $this->buildBulletParagraphProps($bulletNumId, $markRPr, $bulletStyle);

        // v4.3.1: collapse bullets that repeat within the same list (see
        // dedupeListStrings) so a skill/activity is never printed twice.
        $items = $this->dedupeListStrings($items);

        $out = '';
        foreach ($items as $item) {
            $text = $this->escapeForWordXml($item);
            $out .= '<w:p>'.$bulletPPr
                .'<w:r>'.$baseRPr.'<w:t xml:space="preserve">'.$charPrefix.$text.'</w:t></w:r>'
                .'</w:p>';
        }

        return $out;
    }

    /**
     * Normalised comparison key for bullet de-duplication: lower-cased and
     * stripped of every non-alphanumeric character, so bullets that differ
     * only in casing, spacing or punctuation collapse to the same key.
     * Returns '' for items with no alphanumeric content — those are never
     * de-duplicated against (an empty/spacer entry must survive).
     */
    private function bulletDedupKey(string $text): string
    {
        $lower = mb_strtolower(trim($text));
        $key = preg_replace('/[^\p{L}\p{N}]+/u', '', $lower);

        return is_string($key) ? $key : $lower;
    }

    /**
     * Drop repeated bullets within a single list (v4.3.1). The v4.3 change
     * that blends the CV with certificates/Zeugnisse into the same `details`
     * bullets can emit the same activity twice when both source documents
     * describe it; this collapses exact duplicates (case/spacing/punctuation
     * -insensitive) while preserving order and the first occurrence.
     *
     * @param list<string> $items
     *
     * @return list<string>
     */
    private function dedupeListStrings(array $items): array
    {
        $seen = [];
        $kept = [];
        foreach ($items as $item) {
            $s = (string) $item;
            $key = $this->bulletDedupKey($s);
            if ('' !== $key && isset($seen[$key])) {
                continue;
            }
            if ('' !== $key) {
                $seen[$key] = true;
            }
            $kept[] = $s;
        }

        return array_values($kept);
    }

    /**
     * Collapse duplicate `bullet` blocks within a station's classified details
     * (v4.3.1). See {@see dedupeListStrings}. The "seen" set resets at each
     * date header so an activity legitimately repeated under a different
     * period is kept; date / title / spacer blocks are never touched.
     *
     * @param list<array{type: string, text?: string}> $blocks
     *
     * @return list<array{type: string, text?: string}>
     */
    private function dedupeBulletBlocks(array $blocks): array
    {
        $seen = [];
        $kept = [];
        foreach ($blocks as $b) {
            $type = $b['type'] ?? '';
            if ('date' === $type) {
                $seen = [];
                $kept[] = $b;
                continue;
            }
            if ('bullet' === $type) {
                $key = $this->bulletDedupKey((string) ($b['text'] ?? ''));
                if ('' !== $key && isset($seen[$key])) {
                    continue;
                }
                if ('' !== $key) {
                    $seen[$key] = true;
                }
            }
            $kept[] = $b;
        }

        return array_values($kept);
    }

    /**
     * Build the shared bullet-paragraph pPr and the literal glyph prefix for a
     * bullet list, honouring an optional per-column designer bullet style
     * (`char` = custom glyph like "■"/"♥", `indent` = left indent in twips).
     *
     * When a custom glyph is set we deliberately DON'T use the template's
     * numbering.xml numId (the customer's Word templates often carry no bullet
     * definition at all, feedback #4), and instead emit the glyph as a literal
     * character prefix with a tokenised indent — giving deterministic square /
     * heart / custom bullets regardless of the template. When no custom glyph
     * is set the previous behaviour is preserved exactly: real Word numbering
     * when the template defines it, else a "• " character fallback.
     *
     * @param array{char?: ?string, indent?: ?int} $bulletStyle
     *
     * @return array{0: string, 1: string} [pPr, literalPrefix]
     */
    private function buildBulletParagraphProps(?int $bulletNumId, string $markRPr, array $bulletStyle): array
    {
        $char = isset($bulletStyle['char']) ? trim((string) $bulletStyle['char']) : '';
        $forceChar = '' !== $char;
        $indent = isset($bulletStyle['indent']) && (int) $bulletStyle['indent'] > 0
            ? (int) $bulletStyle['indent']
            : 360;
        $indXml = '<w:ind w:left="'.$indent.'" w:hanging="'.$indent.'"/>';
        $useNumId = null !== $bulletNumId && !$forceChar;

        $pPr = $useNumId
            ? '<w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="'.$bulletNumId.'"/></w:numPr>'
                .'<w:widowControl w:val="0"/>'
                .'<w:spacing w:after="0"/>'.$indXml.$markRPr.'</w:pPr>'
            : '<w:pPr><w:widowControl w:val="0"/>'
                .'<w:spacing w:after="0"/>'.$indXml.$markRPr.'</w:pPr>';

        // With real numbering the glyph comes from numbering.xml (no literal
        // prefix). Otherwise emit the chosen glyph, or "•" as the default.
        $prefix = $useNumId ? '' : (($forceChar ? $char : '•').' ');

        return [$pPr, $prefix];
    }

    /**
     * Phase D post-pass: walk every <w:tbl> in the generated DOCX and apply
     * layout helpers based on (a) the original template XML and (b) per-table
     * designer config.
     *
     *   - prevent_row_break  → <w:cantSplit/> on every <w:tr><w:trPr>…
     *   - repeat_header      → <w:tblHeader/> on the first row's <w:trPr>
     *   - keep_with_prev     → <w:keepNext/> on every paragraph inside the
     *                          table cells, gluing the table to the paragraph
     *                          above it across a page break.
     *
     * The key question is "which table is this?" — since designer config keys
     * reference variable names (e.g. "stations"), we treat the mapping by
     * scanning table XML for placeholders or their cloned #N counterparts.
     * A table is associated with a designer entry if ANY of that entry's
     * placeholder variants appears (or used to appear — we also peek at the
     * post-cleaned template to be safe) inside the table.
     *
     * Post-pass: rewrite [[SYNCB|state|on|off]] placeholders left behind by
     * processCheckboxes() into proper Word content-control checkboxes
     * (`<w:sdt>` with `<w14:checkbox>`). The result is a generated DOCX where
     * every Synaform-rendered checkbox is fully clickable in Word 2010+,
     * pre-set to the resolved state, and uses the designer's chosen glyph
     * pair for the visible state.
     *
     * Best-effort and non-fatal: if the DOCX cannot be opened or the regex
     * finds nothing, the document keeps the static glyph fallback (which is
     * visually identical, just not interactive).
     */
    private function convertCheckboxMarkersToContentControls(string $docxPath): void
    {
        if (!is_file($docxPath)) {
            return;
        }

        $zip = new \ZipArchive();
        if (true !== $zip->open($docxPath)) {
            $this->logger->warning('Failed to open DOCX for checkbox SDT post-pass', ['path' => $docxPath]);

            return;
        }

        // Run the SYNCB → SDT conversion on every part that PhpWord's setValue
        // touched (main body + every header + every footer). Without the
        // header/footer pass, a checkbox the customer placed inside a header
        // ("[ ] Vertraulich") would render as `[[SYNCB|on|☒|☐]]` literal text
        // in the final DOCX.
        foreach ($this->collectDocumentPartNames($zip) as $partName) {
            $this->convertCheckboxMarkersInPart($zip, $partName);
        }

        $zip->close();
    }

    /**
     * Convert every `[[SYNCB|state|✓|✗]]` marker inside a single document
     * part into a real Word content-control checkbox. Splits one Word run
     * into up to two surviving text-runs plus an `<w:sdt>` per marker, and
     * loops so a single run carrying multiple markers is fully converted.
     *
     * Side effects: writes the rewritten XML back into the zip, and ensures
     * the part's root element declares `xmlns:w14` so Word/LibreOffice
     * actually render the SDT.
     */
    private function convertCheckboxMarkersInPart(\ZipArchive $zip, string $partName): void
    {
        $xml = $zip->getFromName($partName);
        if (false === $xml) {
            return;
        }

        $count = 0;
        $hasW14 = str_contains($xml, 'xmlns:w14=');

        // Match a single Word run that contains the SYNCB marker anywhere
        // inside its <w:t>…</w:t> (text may have arbitrary surrounding
        // characters because PhpWord's setValue replaces only the
        // {{placeholder}} substring inside whatever text node hosted it).
        // We capture:
        //   1 = the rPr block (preserved on the surviving runs so the
        //       generated DOCX keeps the template's font/size/color),
        //   2 = inline-content nodes that sit between </w:rPr> and <w:t>
        //       in the original run (e.g. <w:tab/>, <w:br/>, <w:sym/>,
        //       <w:noBreakHyphen/>, <w:cr/>, …). These are preserved on
        //       the surviving "before" run only, so the visual layout —
        //       e.g. the tab that separates the checkbox glyph from its
        //       label in the v4 hhff template — is kept intact. Without
        //       this slot, customer-authored runs that carry a `<w:tab/>`
        //       between rPr and the placeholder text never matched and
        //       the SDT post-pass left every checkbox as a literal
        //       `[[SYNCB|on|☒|☐]]` token in the final document.
        //   3 = the literal <w:t …> opening tag (with its xml:space attr
        //       intact),
        //   4 = text before the marker,
        //   5 = state ("on"/"off"),
        //   6 = checked glyph,
        //   7 = unchecked glyph,
        //   8 = text after the marker.
        // The body uses [^<]* to stay inside a single text node (no nested
        // elements), which is exactly what PhpWord setValue produces.
        //
        // The rPr inner content uses a tempered greedy token so the lazy
        // `.*?</w:rPr>` cannot backtrack across a run boundary. Without this
        // guard, on the SECOND iteration of the loop (when the previous
        // iteration just emitted an SDT directly before a remaining
        // SYNCB-bearing run), the regex would start at the SDT-internal
        // `<w:r>`, fail to find SYNCB inside that run's `<w:t>`, then
        // backtrack the rPr's `.*?` to the *next* `</w:rPr>` — which lives
        // inside the leftover run AFTER `</w:sdtContent></w:sdt>`. The
        // engine would then match successfully but the captured rPr would
        // contain the closing SDT tags. The replacement re-emits that rPr
        // twice (around the new SDT), producing an over-closed SDT and a
        // bare `<w:r>`, which Word + LibreOffice both reject as malformed.
        // Replicate with the test in `_devnotes/v4-api-smoketest.php` on
        // the v4 hhff template and inspect `word/document.xml` for an
        // unbalanced `<w:sdt>` count.
        $rPrInner = '(?:(?!</w:rPr>|<w:r\b|</w:r>).)*?';
        $rPrAlt = '(<w:rPr\b[^/]*?>'.$rPrInner.'</w:rPr>|<w:rPr\b[^/]*?/>)?';
        // Tempered token for the optional inline-content nodes between
        // </w:rPr> and <w:t>. Allows arbitrary OOXML inline children
        // (<w:tab/>, <w:br/>, <w:sym/>, <w:noBreakHyphen/>, …) but
        // refuses to cross into a <w:t…> opening tag or a run boundary,
        // so the regex never matches across a different run.
        $inlineMid = '((?:(?!<w:t[\s>/]|<w:r\b|</w:r>).)*?)';
        $pattern = '#<w:r\b[^>]*>'.$rPrAlt.$inlineMid
            .'(<w:t\b[^>]*>)([^<]*?)\[\[SYNCB\|(on|off)\|([^|]+)\|([^\]]+)\]\]([^<]*?)</w:t></w:r>#s';

        // Iterate so that runs containing multiple SYNCB markers all get
        // converted. Each pass splits one marker out into its own SDT plus
        // up to two surrounding text-only runs; subsequent passes find
        // any remaining markers in the freshly created text-only runs.
        // Cap the loop to a sane number of iterations as a safety net
        // against pathological inputs.
        $newXml = $xml;
        for ($i = 0; $i < 64; ++$i) {
            $passCount = 0;
            $newXml = preg_replace_callback(
                $pattern,
                function (array $match) use (&$count, &$passCount): string {
                    ++$count;
                    ++$passCount;
                    $rPr = $match[1] ?? '';
                    $inlineMid = $match[2] ?? '';
                    $tOpen = $match[3];
                    $before = $match[4];
                    $state = $match[5];
                    $checkedGlyph = $match[6];
                    $uncheckedGlyph = $match[7];
                    $after = $match[8];

                    // Force xml:space="preserve" on surviving text fragments
                    // so leading/trailing whitespace around the original
                    // placeholder is not collapsed by Word's XML parser.
                    $tOpenPreserved = $this->ensureXmlSpacePreserve($tOpen);

                    // The inline middle (e.g. <w:tab/>) belongs visually
                    // BEFORE the SDT — it's what separated the leading text
                    // from the placeholder in the source layout. Emit it
                    // exactly once, on the leading run if there is "before"
                    // text, otherwise as its own bare run so the tab/break
                    // still lands ahead of the checkbox glyph.
                    $out = '';
                    if ('' !== $before) {
                        $out .= '<w:r>'.$rPr.$inlineMid.$tOpenPreserved.$before.'</w:t></w:r>';
                    } elseif ('' !== $inlineMid) {
                        $out .= '<w:r>'.$rPr.$inlineMid.'</w:r>';
                    }
                    $out .= $this->buildCheckboxSdtXml('on' === $state, $checkedGlyph, $uncheckedGlyph);
                    if ('' !== $after) {
                        $out .= '<w:r>'.$rPr.$tOpenPreserved.$after.'</w:t></w:r>';
                    }

                    return $out;
                },
                $newXml
            );

            if (null === $newXml) {
                return;
            }
            if (0 === $passCount) {
                break;
            }
        }

        if (0 === $count) {
            return;
        }

        // Word's content-control checkbox lives in the w14 namespace. Older
        // templates (Word 2007) may not declare it — inject it on the part's
        // root element (`<w:document>` for the body, `<w:hdr>` for headers,
        // `<w:ftr>` for footers) so Word/LibreOffice render the SDT
        // correctly. Without this, the SDT renders as plain text instead of
        // a clickable checkbox.
        if (!$hasW14) {
            $newXml = preg_replace(
                '#<w:(document|hdr|ftr)\b([^>]*?)>#',
                '<w:$1$2 xmlns:w14="http://schemas.microsoft.com/office/word/2010/wordml">',
                $newXml,
                1
            ) ?? $newXml;
        }

        $zip->addFromString($partName, $newXml);
    }

    /**
     * Build a Word content-control checkbox (`<w:sdt>` with `<w14:checkbox>`)
     * pre-set to $checked. The visible state shows $checkedGlyph or
     * $uncheckedGlyph depending on $checked. Uses MS Gothic for the symbol
     * because that's the font Word's UI inserts for checkbox content
     * controls and it ships on every Office install.
     */
    private function buildCheckboxSdtXml(bool $checked, string $checkedGlyph, string $uncheckedGlyph): string
    {
        $checkedFlag = $checked ? '1' : '0';
        $glyph = $checked ? $checkedGlyph : $uncheckedGlyph;
        $checkedHex = $this->glyphToHex($checkedGlyph, '2612');
        $uncheckedHex = $this->glyphToHex($uncheckedGlyph, '2610');
        // Random per-control id so multiple checkboxes do not collide.
        $id = random_int(1, 2_147_483_647);
        $glyphXml = htmlspecialchars($glyph, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<w:sdt>'
            .'<w:sdtPr>'
            .'<w:rPr><w:rFonts w:ascii="MS Gothic" w:eastAsia="MS Gothic" w:hAnsi="MS Gothic" w:cs="MS Gothic" w:hint="eastAsia"/></w:rPr>'
            .'<w:id w:val="'.$id.'"/>'
            .'<w14:checkbox>'
            .'<w14:checked w14:val="'.$checkedFlag.'"/>'
            .'<w14:checkedState w14:val="'.$checkedHex.'" w14:font="MS Gothic"/>'
            .'<w14:uncheckedState w14:val="'.$uncheckedHex.'" w14:font="MS Gothic"/>'
            .'</w14:checkbox>'
            .'</w:sdtPr>'
            .'<w:sdtContent>'
            .'<w:r><w:rPr><w:rFonts w:ascii="MS Gothic" w:eastAsia="MS Gothic" w:hAnsi="MS Gothic" w:cs="MS Gothic" w:hint="eastAsia"/></w:rPr><w:t xml:space="preserve">'.$glyphXml.'</w:t></w:r>'
            .'</w:sdtContent>'
            .'</w:sdt>';
    }

    /**
     * Ensure a `<w:t …>` opening tag carries `xml:space="preserve"` so the
     * surrounding whitespace from the original template text is honoured
     * after we split a run around a SYNCB marker. PhpWord uses this
     * attribute pattern itself, so we just inject it when missing.
     */
    private function ensureXmlSpacePreserve(string $wtOpen): string
    {
        if (str_contains($wtOpen, 'xml:space=')) {
            return $wtOpen;
        }

        return preg_replace('#<w:t\b#', '<w:t xml:space="preserve"', $wtOpen, 1) ?? $wtOpen;
    }

    /**
     * Convert a single character to its 4-digit upper-hex Unicode codepoint
     * for the `w14:val` attribute on checkbox state declarations. Falls back
     * to $default when the input is empty or the codepoint cannot be derived
     * (e.g. the designer used a multi-character emoji).
     */
    private function glyphToHex(string $glyph, string $default): string
    {
        if ('' === $glyph) {
            return $default;
        }
        $arr = function_exists('mb_str_split') ? mb_str_split($glyph, 1, 'UTF-8') : preg_split('//u', $glyph, -1, PREG_SPLIT_NO_EMPTY);
        $first = is_array($arr) && [] !== $arr ? $arr[0] : '';
        if ('' === $first) {
            return $default;
        }
        $cp = mb_ord($first, 'UTF-8');
        if (!is_int($cp) || $cp <= 0) {
            return $default;
        }

        return strtoupper(str_pad(dechex($cp), 4, '0', STR_PAD_LEFT));
    }

    /**
     * @param array<string, mixed>                $arrays      group => rows[]
     * @param array<string, array<string, mixed>> $designerMap varKey => designer
     */
    private function applyTableLayoutHelpers(string $docxPath, array $arrays, array $designerMap): void
    {
        if (empty($designerMap)) {
            return;
        }

        // Gather designer entries that apply to tables (either explicit type=table
        // or an array-of-objects variable that clones into a Word table). If any
        // have non-empty design settings, we must rewrite.
        $tableDesigners = [];
        foreach ($designerMap as $key => $cfg) {
            $type = $cfg['_type'] ?? 'text';
            $isTableLike = 'table' === $type || (is_array($arrays[$key] ?? null) && !empty($arrays[$key]) && is_array($arrays[$key][0] ?? null));
            if (!$isTableLike) {
                continue;
            }
            // Drop designer-meta key so `array_filter` below reads cleanly.
            $filtered = $cfg;
            unset($filtered['_type']);
            $tableDesigners[$key] = $filtered;
        }
        if (empty($tableDesigners)) {
            return;
        }

        // We proceed even when every flag is false: header-less clone-row
        // tables (career history) must still be normalised so that any
        // <w:cantSplit/> baked into the template row is stripped and no header
        // is invented on the first data row.

        $zip = new \ZipArchive();
        if (true !== $zip->open($docxPath)) {
            $this->logger->warning('Failed to open DOCX for table layout pass', ['path' => $docxPath]);

            return;
        }

        $xml = $zip->getFromName('word/document.xml');
        if (false === $xml) {
            $zip->close();

            return;
        }

        $originalXml = $xml;

        // Walk every <w:tbl>…</w:tbl>. For each, check whether any of its
        // cells still contain (or contained) variable placeholders for a
        // configured designer key. Since placeholders have been substituted
        // by now, we use a heuristic: if the table has more rows than a
        // single-row fixed layout typically would (≥2) AND row content
        // overlap is likely cloned, apply all configured designer settings.
        // In practice, designer settings tend to be global toggles per table,
        // so we simply apply the union of all designer settings to every
        // table that is clearly an iterated one (≥2 body rows).
        $merged = [];
        foreach ($tableDesigners as $cfg) {
            foreach ($cfg as $k => $v) {
                $merged[$k] = $merged[$k] ?? $v;
            }
        }
        // Largest iterated data set among the configured table fields. A table
        // whose body-row count is GREATER than this has a genuine static header
        // row (header + N data rows); a table with exactly this many rows is
        // purely cloned data with no header — the common HR "career history"
        // case. This lets us tell a real header apart from a first DATA row, so
        // we never stamp a repeating header onto an entry.
        $maxDataRows = 0;
        foreach (array_keys($tableDesigners) as $key) {
            if (is_array($arrays[$key] ?? null)) {
                $maxDataRows = max($maxDataRows, count($arrays[$key]));
            }
        }

        $pattern = '#<w:tbl>(?:(?!</w:tbl>).)*?</w:tbl>#s';
        $newXml = preg_replace_callback(
            $pattern,
            function (array $m) use ($merged, $maxDataRows): string {
                $tbl = $m[0];
                $rowCount = substr_count($tbl, '</w:tr>');
                if ($rowCount < 2) {
                    // Don't rewrite single-row tables (e.g. the scalar-in-cell style).
                    return $tbl;
                }
                // A real header row exists only when the table has MORE rows
                // than the data set that filled it. Otherwise every row is
                // cloned data and the table is header-less.
                $hasRealHeader = $maxDataRows > 0 && $rowCount > $maxDataRows;

                return $this->applyHelpersToTableXml($tbl, $merged, $hasRealHeader);
            },
            $xml
        );

        if (null !== $newXml && $newXml !== $originalXml) {
            $zip->addFromString('word/document.xml', $newXml);
        }
        $zip->close();
    }

    /**
     * Rewrite a single <w:tbl> block, injecting the requested layout helpers.
     *
     * @param array<string, mixed> $cfg Merged designer directives
     */
    private function applyHelpersToTableXml(string $tblXml, array $cfg, bool $hasRealHeader = true): string
    {
        $preventSplit = !empty($cfg['prevent_row_break']);
        $repeatHeader = !empty($cfg['repeat_header']);
        $keepWithPrev = !empty($cfg['keep_with_prev']);

        // Header-less clone-row tables (the common HR "career history" layout)
        // must never carry a repeating header: stamping <w:tblHeader/> on the
        // first DATA row makes Word repeat that entry at the top of every page,
        // where it can't be deleted like a normal row and reappears on edit.
        // They must also be free to break across a page boundary, otherwise a
        // long entry jumps to the next page and leaves the rest blank. So we
        // strip every <w:cantSplit/> (including one baked into the template row)
        // and skip the header — overriding stale auto-defaults that older forms
        // saved before this behaviour was fixed.
        if (!$hasRealHeader) {
            $tblXml = preg_replace('#<w:cantSplit\b[^>]*/>#', '', $tblXml) ?? $tblXml;
            if ($keepWithPrev) {
                $tblXml = preg_replace_callback(
                    '#<w:p\b[^>]*>(?:(?!</w:p>).)*?</w:p>#s',
                    fn (array $pm): string => $this->addKeepNext($pm[0]),
                    $tblXml
                ) ?? $tblXml;
            }

            return $tblXml;
        }

        if (!$preventSplit && !$repeatHeader && !$keepWithPrev) {
            return $tblXml;
        }

        $rowIndex = 0;
        $tblXml = preg_replace_callback(
            '#<w:tr\b[^>]*>(?:(?!</w:tr>).)*?</w:tr>#s',
            function (array $rm) use (&$rowIndex, $preventSplit, $repeatHeader): string {
                $tr = $rm[0];
                $isHeader = 0 === $rowIndex;
                ++$rowIndex;

                $directives = '';
                if ($preventSplit && !str_contains($tr, '<w:cantSplit/>')) {
                    $directives .= '<w:cantSplit/>';
                }
                if ($repeatHeader && $isHeader && !str_contains($tr, '<w:tblHeader/>')) {
                    $directives .= '<w:tblHeader/>';
                }
                if ('' === $directives) {
                    return $tr;
                }

                if (preg_match('#<w:trPr\b[^>]*>#', $tr)) {
                    return preg_replace('#(<w:trPr\b[^>]*>)#', '$1'.$directives, $tr, 1) ?? $tr;
                }

                return preg_replace(
                    '#(<w:tr\b[^>]*>)#',
                    '$1<w:trPr>'.$directives.'</w:trPr>',
                    $tr,
                    1
                ) ?? $tr;
            },
            $tblXml
        ) ?? $tblXml;

        if ($keepWithPrev) {
            $tblXml = preg_replace_callback(
                '#<w:p\b[^>]*>(?:(?!</w:p>).)*?</w:p>#s',
                fn (array $pm): string => $this->addKeepNext($pm[0]),
                $tblXml
            ) ?? $tblXml;
        }

        return $tblXml;
    }

    /**
     * Auto-detect a numId that produces an ordered (decimal/roman/…) list
     * from a template's numbering.xml. Counterpart to detectBulletNumId().
     *
     * Accepts any numFmt at level 0 that is NOT "bullet" and NOT "none" —
     * Word templates commonly ship with "decimal", "lowerLetter" etc.
     * Returns the first matching numId, or null.
     */
    private function detectOrderedNumId(string $numberingXml): ?int
    {
        if ('' === $numberingXml) {
            return null;
        }

        $orderedAbstractIds = [];
        if (preg_match_all('#<w:abstractNum\b[^>]*?w:abstractNumId="(\d+)"[^>]*>(.*?)</w:abstractNum>#s', $numberingXml, $am)) {
            foreach ($am[1] as $idx => $absId) {
                $body = $am[2][$idx];
                if (preg_match('#<w:lvl\b[^>]*?w:ilvl="0"[^>]*>(.*?)</w:lvl>#s', $body, $lvl)) {
                    if (preg_match('#<w:numFmt\s+w:val="([^"]+)"\s*/?>#', $lvl[1], $fm)) {
                        $fmt = strtolower($fm[1]);
                        if ('bullet' !== $fmt && 'none' !== $fmt && '' !== $fmt) {
                            $orderedAbstractIds[$absId] = true;
                        }
                    }
                }
            }
        }

        if (empty($orderedAbstractIds)) {
            return null;
        }

        if (preg_match_all('#<w:num\b[^>]*?w:numId="(\d+)"[^>]*>(.*?)</w:num>#s', $numberingXml, $nm)) {
            foreach ($nm[1] as $idx => $numId) {
                $body = $nm[2][$idx];
                if (preg_match('#<w:abstractNumId\s+w:val="(\d+)"\s*/>#', $body, $ref)) {
                    if (isset($orderedAbstractIds[$ref[1]])) {
                        return (int) $numId;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Auto-detect a numId that produces a bullet list from a template's numbering.xml.
     *
     * Looks for a <w:num w:numId="X"> whose referenced <w:abstractNum> has
     * <w:numFmt w:val="bullet"/> at level 0. Returns the first such numId or
     * null if no bullet numbering is defined.
     */
    private function detectBulletNumId(string $numberingXml): ?int
    {
        if ('' === $numberingXml) {
            return null;
        }

        $bulletAbstractIds = [];
        if (preg_match_all('#<w:abstractNum\b[^>]*?w:abstractNumId="(\d+)"[^>]*>(.*?)</w:abstractNum>#s', $numberingXml, $am)) {
            foreach ($am[1] as $idx => $absId) {
                $body = $am[2][$idx];
                if (preg_match('#<w:lvl\b[^>]*?w:ilvl="0"[^>]*>(.*?)</w:lvl>#s', $body, $lvl)) {
                    if (str_contains($lvl[1], '<w:numFmt w:val="bullet"/>')) {
                        $bulletAbstractIds[$absId] = true;
                    }
                }
            }
        }

        if (empty($bulletAbstractIds)) {
            return null;
        }

        if (preg_match_all('#<w:num\b[^>]*?w:numId="(\d+)"[^>]*>(.*?)</w:num>#s', $numberingXml, $nm)) {
            foreach ($nm[1] as $idx => $numId) {
                $body = $nm[2][$idx];
                if (preg_match('#<w:abstractNumId\s+w:val="(\d+)"\s*/>#', $body, $ref)) {
                    if (isset($bulletAbstractIds[$ref[1]])) {
                        return (int) $numId;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Parse a multi-line station `details` string into a sequence of typed blocks.
     *
     * Heuristics (German-first but language-agnostic patterns):
     *   - blank line          → spacer (consecutive spacers collapse to one)
     *   - date-range line     → date header (rendered bold)
     *   - "- text" / "• text" / "– text" / "* text" / "· text" → bullet
     *   - anything else       → plain text line (typically a sub-position title)
     *
     * @return list<array{type: string, text?: string}>
     */
    private function parseStationDetails(string $details): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $details) ?: [];

        // Matches patterns like "02/2021 -- heute", "02.2021 – 04.2024", "2019-2021"
        $dateRangePattern = '~^\s*\d{1,2}[./]\d{4}\s*[\-–—]{1,2}\s*(?:heute|today|laufend|\d{1,2}[./]\d{4})\s*$~iu';
        $looseYearRange = '~^\s*\d{4}\s*[\-–—]{1,2}\s*(?:heute|today|laufend|\d{4})\s*$~iu';
        $bulletPrefix = '~^[\-*•·–—]\s+(.*)$~u';

        $blocks = [];
        foreach ($lines as $line) {
            $stripped = trim($line);

            if ('' === $stripped) {
                $blocks[] = ['type' => 'spacer'];
                continue;
            }

            if (1 === preg_match($dateRangePattern, $stripped) || 1 === preg_match($looseYearRange, $stripped)) {
                $blocks[] = ['type' => 'date', 'text' => $stripped];
                continue;
            }

            if (1 === preg_match($bulletPrefix, $stripped, $bm)) {
                $blocks[] = ['type' => 'bullet', 'text' => trim($bm[1])];
                continue;
            }

            $blocks[] = ['type' => 'text', 'text' => $stripped];
        }

        // Collapse consecutive spacers
        $collapsed = [];
        $lastSpacer = false;
        foreach ($blocks as $b) {
            if ('spacer' === $b['type']) {
                if ($lastSpacer) {
                    continue;
                }
                $lastSpacer = true;
            } else {
                $lastSpacer = false;
            }
            $collapsed[] = $b;
        }

        // Trim leading/trailing spacers
        while (!empty($collapsed) && 'spacer' === $collapsed[0]['type']) {
            array_shift($collapsed);
        }
        while (!empty($collapsed) && 'spacer' === end($collapsed)['type']) {
            array_pop($collapsed);
        }

        return $collapsed;
    }

    /**
     * Render a parsed details string into a sequence of <w:p> OOXML paragraphs
     * suitable for inlining inside a table cell (<w:tc>).
     *
     * - $basePPr is the host paragraph's <w:pPr> including its <w:rPr> pragraph-mark
     *   defaults; it is reused verbatim for non-bullet paragraphs so fonts, sizes,
     *   and justification stay consistent with the surrounding cell.
     * - $bulletNumId, when non-null, means the document has a real bullet numbering
     *   entry; bullet paragraphs reference it via <w:numPr>. When null, bullets
     *   degrade to a character "•" prefix with a hanging indent.
     */
    private function renderStationDetailsXml(string $details, string $basePPr, ?int $bulletNumId, string $baseRPr = '', array $bulletStyle = []): string
    {
        return $this->renderStationBlocksXml($this->parseStationDetails($details), $basePPr, $bulletNumId, $baseRPr, $bulletStyle);
    }

    /**
     * Classify an array of plain string list items into the same date / title /
     * bullet / spacer block sequence that {@see parseStationDetails} produces
     * for multi-line strings. Lets HR-style "stations.details" lists be
     * authored as JSON arrays AND keep the customer's expected layout:
     *
     *   - empty entry            → spacer (blank line between sub-positions)
     *   - date-range pattern     → date header (rendered bold, no bullet)
     *   - first non-empty item OR
     *     item right after a date → position title (no bullet)
     *   - everything else        → task bullet
     *
     * The state machine resets to "expecting title" after every date header,
     * so multi-position stations naturally render as
     *   DATE → TITLE → bullets (tasks)
     *   DATE → TITLE → bullets (tasks)
     * For a single-position station with no date in the array, the first
     * item becomes the position title (without a bullet) and subsequent
     * items render as task bullets — matching the customer's spec
     * "Positionen haben keine Aufzählungszeichen, dann die Aufgaben als Liste".
     *
     * @param list<string> $items
     *
     * @return list<array{type: string, text?: string}>
     */
    private function classifyListItems(array $items): array
    {
        $dateRangePattern = '~^\s*\d{1,2}[./]\d{4}\s*[\-–—]{1,2}\s*(?:heute|today|laufend|\d{1,2}[./]\d{4})\s*$~iu';
        $looseYearRange = '~^\s*\d{4}\s*[\-–—]{1,2}\s*(?:heute|today|laufend|\d{4})\s*$~iu';
        $bulletPrefix = '~^[\-*•·–—]\s+(.*)$~u';

        $blocks = [];
        $expectingTitle = true;
        foreach ($items as $item) {
            $stripped = trim((string) $item);

            if ('' === $stripped) {
                $blocks[] = ['type' => 'spacer'];
                continue;
            }

            if (1 === preg_match($dateRangePattern, $stripped) || 1 === preg_match($looseYearRange, $stripped)) {
                $blocks[] = ['type' => 'date', 'text' => $stripped];
                $expectingTitle = true;
                continue;
            }

            // Strip an explicit bullet prefix the AI or user may have left in
            // ("- foo", "• foo", "* foo"); the renderer adds its own bullets.
            if (1 === preg_match($bulletPrefix, $stripped, $bm)) {
                $blocks[] = ['type' => 'bullet', 'text' => trim($bm[1])];
                $expectingTitle = false;
                continue;
            }

            if ($expectingTitle) {
                $blocks[] = ['type' => 'text', 'text' => $stripped];
                $expectingTitle = false;
                continue;
            }

            $blocks[] = ['type' => 'bullet', 'text' => $stripped];
        }

        // Collapse consecutive spacers and trim leading/trailing ones.
        $collapsed = [];
        $lastSpacer = false;
        foreach ($blocks as $b) {
            if ('spacer' === $b['type']) {
                if ($lastSpacer) {
                    continue;
                }
                $lastSpacer = true;
            } else {
                $lastSpacer = false;
            }
            $collapsed[] = $b;
        }
        while (!empty($collapsed) && 'spacer' === $collapsed[0]['type']) {
            array_shift($collapsed);
        }
        while (!empty($collapsed) && 'spacer' === end($collapsed)['type']) {
            array_pop($collapsed);
        }

        return $collapsed;
    }

    /**
     * Render a pre-classified block sequence (from {@see parseStationDetails}
     * or {@see classifyListItems}) into <w:p> paragraphs suitable for inlining
     * inside a table cell. Shared between the legacy multi-line string path
     * and the structured-array path so both render identically.
     *
     * @param list<array{type: string, text?: string}> $blocks
     */
    private function renderStationBlocksXml(array $blocks, string $basePPr, ?int $bulletNumId, string $baseRPr = '', array $bulletStyle = [], array $rowContext = []): string
    {
        // WS-F: employer umbrella + nested position support (feedback #9/#10).
        // A row may carry:
        //   - time_total       employer's overall span (e.g. "01/2014 – 03/2024")
        //   - employer         company name (rendered italic in the umbrella line)
        //   - sub_of_previous  this role's period lies within another → indent
        $timeTotal = trim((string) ($rowContext['time_total'] ?? ''));
        $umbrellaEmployer = trim((string) ($rowContext['employer'] ?? ''));
        $nestIndent = !empty($rowContext['sub_of_previous']) ? 360 : 0;

        if (empty($blocks) && '' === $timeTotal) {
            return '';
        }

        // v4.3.1: collapse bullets that repeat within this row's details.
        // The v4.3 change that blends the CV with certificates/Zeugnisse into
        // the same `details` list can emit the same activity twice when both
        // source documents describe it ("alle Unterpunkte doppelt").
        $blocks = $this->dedupeBulletBlocks($blocks);

        // Nested rows shift every paragraph (and its bullets) one level right.
        if ($nestIndent > 0) {
            $bulletStyle['indent'] = ($bulletStyle['indent'] ?? 360) + $nestIndent;
        }

        // Bullet paragraphs intentionally do NOT inherit the host paragraph's
        // pPr, because that pPr already carries the host's bullet numId for
        // exactly this purpose; reusing it verbatim would double-indent. We
        // build a clean bullet pPr instead.
        //
        // `<w:widowControl w:val="0"/>` allows Word to split a single long
        // bullet that wraps across a page boundary — without this, a long
        // wrapped bullet would jump entirely to the next page and leave the
        // previous one looking short. Successive bullets always break freely
        // from each other (no keepNext anywhere).
        // The bullet GLYPH (• or the numbering symbol) takes its size and font
        // from the paragraph-MARK run properties, not the text run. If we leave
        // them unset, the dot renders at the Normal-style default while the text
        // uses the host's size — exactly the "bullets in different sizes"
        // complaint. Re-using the host run's rPr as the paragraph-mark rPr (the
        // last child of pPr, per the OOXML schema) keeps glyph and text in sync.
        $markRPr = $baseRPr;
        [$bulletPPr, $charPrefix] = $this->buildBulletParagraphProps($bulletNumId, $markRPr, $bulletStyle);

        // Non-bullet paragraphs (date/title/spacer) inherit the host paragraph
        // pPr but with any list-bullet numPr stripped — otherwise the title
        // line would inherit the bullet styling from the host paragraph.
        $plainPPr = $this->stripNumPr($basePPr);
        if ($nestIndent > 0) {
            $plainPPr = $this->addLeftIndent($plainPPr, $nestIndent);
        }

        // Pre-compute a bold rPr that merges the host run's font/size/colour
        // (so date headers stay in the cell's font) with a forced <w:b/>.
        $dateRPr = $this->mergeRPrAddBold($baseRPr);
        $italicRPr = $this->mergeRPrAddItalic($baseRPr);

        $out = '';

        // Umbrella header: "<time_total>   <employer italic>" on one line
        // (feedback #9 total span + #10 italic employer). Only emitted when
        // the row explicitly carries a total span, so existing single-period
        // rows are unaffected.
        if ('' !== $timeTotal) {
            $out .= '<w:p>'.$plainPPr
                .'<w:r>'.$dateRPr.'<w:t xml:space="preserve">'
                .$this->escapeForWordXml($timeTotal).'</w:t></w:r>';
            if ('' !== $umbrellaEmployer) {
                $out .= '<w:r>'.$baseRPr.'<w:t xml:space="preserve">   </w:t></w:r>'
                    .'<w:r>'.$italicRPr.'<w:t xml:space="preserve">'
                    .$this->escapeForWordXml($umbrellaEmployer).'</w:t></w:r>';
            }
            $out .= '</w:p>';
        }

        foreach ($blocks as $b) {
            switch ($b['type']) {
                case 'spacer':
                    $out .= '<w:p>'.$plainPPr.'</w:p>';
                    break;

                case 'date':
                    $text = $this->escapeForWordXml((string) ($b['text'] ?? ''));
                    $out .= '<w:p>'.$plainPPr
                        .'<w:r>'.$dateRPr
                        .'<w:t xml:space="preserve">'.$text.'</w:t></w:r>'
                        .'</w:p>';
                    break;

                case 'bullet':
                    $text = $this->escapeForWordXml((string) ($b['text'] ?? ''));
                    $out .= '<w:p>'.$bulletPPr
                        .'<w:r>'.$baseRPr.'<w:t xml:space="preserve">'.$charPrefix.$text.'</w:t></w:r>'
                        .'</w:p>';
                    break;

                case 'text':
                default:
                    $text = $this->escapeForWordXml((string) ($b['text'] ?? ''));
                    $out .= '<w:p>'.$plainPPr
                        .'<w:r>'.$baseRPr.'<w:t xml:space="preserve">'.$text.'</w:t></w:r>'
                        .'</w:p>';
                    break;
            }
        }

        // Trailing spacer — one empty paragraph after the whole block. Each
        // station row ends with its details block, so without this the next
        // position's date/title line is glued directly onto the previous
        // position's last bullet ("the next position is directly attached to
        // the one above"). Emitting a single blank paragraph gives the
        // "empty line after list blocks" separation the HR profile expects.
        // We reuse $plainPPr (host pPr minus bullet numPr) so the blank
        // line's height matches the surrounding cell, and only the structured
        // positions/details path goes through here — flat skill lists
        // (languages, other_skills, benefits) render via renderBulletList()
        // and are intentionally left without a trailing blank line.
        if ('' !== $out) {
            $out .= '<w:p>'.$plainPPr.'</w:p>';
        }

        return $out;
    }

    /**
     * Remove `<w:numPr>...</w:numPr>` from a paragraph's `<w:pPr>` block so a
     * non-bullet paragraph (date header, position title, spacer) inherits the
     * host's font / size / indent without inheriting its bullet bullet.
     */
    private function stripNumPr(string $pPrXml): string
    {
        if ('' === $pPrXml) {
            return '';
        }
        $stripped = preg_replace('#<w:numPr\b.*?</w:numPr>#s', '', $pPrXml);

        return is_string($stripped) ? $stripped : $pPrXml;
    }

    /**
     * Whether a table column should render its list items with structured
     * date/title/bullet detection (the HR-profile layout) vs. flat bullets.
     *
     * Opt-in via designer flag (`structured: true` on the column) OR — for
     * back-compat with HR templates that have always relied on this — by
     * convention for any column whose key is `details`.
     *
     * @param array<string, mixed> $col
     */
    private function isStructuredListColumn(array $col): bool
    {
        if (($col['type'] ?? '') !== 'list') {
            return false;
        }
        // Explicit setting wins in BOTH directions: `structured: false` on a
        // `details` column opts out of the legacy structured default so every
        // item renders as a bullet (customer feedback: "sometimes bullets,
        // sometimes not" — the structured classifier demotes the first item
        // to a bullet-less title line, which surprises users who expect a
        // plain bullet list).
        if (array_key_exists('structured', $col)) {
            return (bool) $col['structured'];
        }
        $designer = $col['designer'] ?? null;
        if (is_array($designer) && array_key_exists('structured', $designer)) {
            return (bool) $designer['structured'];
        }

        return 'details' === strtolower((string) ($col['key'] ?? ''));
    }

    /**
     * Read the per-column bullet style from its designer config (feedback #4:
     * customer-controllable bullet shape/indent). Returns a compact
     * `['char' => ?string, 'indent' => ?int]` where `char` is a custom bullet
     * glyph (e.g. "■" for square, "♥") and `indent` is the left/hanging indent
     * in twips (1 cm = 567 twips). Empty array = keep the default bullet.
     *
     * @param array<string, mixed> $col
     *
     * @return array{char?: string, indent?: int}
     */
    private function bulletStyleForColumn(array $col): array
    {
        $designer = is_array($col['designer'] ?? null) ? $col['designer'] : [];
        $style = [];

        $char = $designer['bullet_char'] ?? ($col['bullet_char'] ?? null);
        if (is_string($char) && '' !== trim($char)) {
            // Guard against a pasted word — a bullet is a single glyph.
            $style['char'] = mb_substr(trim($char), 0, 2);
        }

        $indentCm = $designer['bullet_indent_cm'] ?? ($col['bullet_indent_cm'] ?? null);
        if (is_numeric($indentCm) && (float) $indentCm > 0) {
            $style['indent'] = (int) round((float) $indentCm * 567.0);
        }

        return $style;
    }
}
