<?php

declare(strict_types=1);

namespace Plugin\Synaform\Controller;

use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\PluginDataRepository;
use App\AI\Service\AiFacade;
use App\Service\File\FileProcessor;
use App\Service\PluginDataService;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use PhpOffice\PhpWord\IOFactory as PhpWordIOFactory;
use PhpOffice\PhpWord\TemplateProcessor;
use Plugin\Synaform\Service\TemplateHtmlPreviewService;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

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
    private const ALLOWED_UPLOAD_EXTENSIONS = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'tiff', 'tif', 'bmp', 'txt', 'rtf', 'odt', 'xls', 'xlsx', 'pptx'];

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
        private LoggerInterface $logger,
        private AiFacade $aiFacade,
        private FileProcessor $fileProcessor,
        private TemplateHtmlPreviewService $htmlPreviewService,
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

        $file = $request->files->get('file');
        if (!$file) {
            return $this->json(['success' => false, 'error' => 'No file uploaded'], Response::HTTP_BAD_REQUEST);
        }

        $originalName = $file->getClientOriginalName();
        $ext = strtolower($file->getClientOriginalExtension());
        if ($ext !== 'docx') {
            return $this->json(['success' => false, 'error' => 'Only .docx files are allowed'], Response::HTTP_BAD_REQUEST);
        }

        $name = $request->request->get('name', pathinfo($originalName, PATHINFO_FILENAME));
        $templateId = 'tpl_' . bin2hex(random_bytes(6));

        $dir = $this->uploadDir . '/' . $userId . '/synaform/templates/' . $templateId;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file->move($dir, 'template.docx');

        $placeholders = $this->extractPlaceholders($dir . '/template.docx');

        // Build the HTML preview skeleton once and cache it on the template record.
        // Non-fatal: if it fails (malformed docx, exotic content), generation and
        // download still work; only the live-preview panel degrades to "unavailable".
        try {
            $preview = $this->htmlPreviewService->build($dir . '/template.docx');
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
            if ($name !== '') {
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

        $dir = $this->uploadDir . '/' . $userId . '/synaform/templates/' . $templateId;
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
        if (is_string($formId) && $formId !== '') {
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
            $filePath = $this->uploadDir . '/' . $userId . '/synaform/templates/' . $templateId . '/template.docx';
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
            'success'        => true,
            'template_id'    => $templateId,
            'schema_version' => $preview['schema_version'] ?? 0,
            'html'           => $preview['html'] ?? '',
            'placeholders'   => $preview['placeholders'] ?? [],
            'row_groups'     => $preview['row_groups'] ?? [],
            'generated_at'   => $preview['generated_at'] ?? null,
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

        $filePath = $this->uploadDir . '/' . $userId . '/synaform/templates/' . $templateId . '/template.docx';
        if (!is_file($filePath)) {
            return $this->json(['success' => false, 'error' => 'Template file not found on disk'], Response::HTTP_NOT_FOUND);
        }

        $downloadName = $template['original_filename'] ?? ($template['name'] . '.docx');
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

        $formId = $data['id'] ?? ('form_' . bin2hex(random_bytes(6)));

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
                if ($field === 'fields' && is_array($data[$field])) {
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
            if ($ext !== 'docx') {
                return $this->json(['success' => false, 'error' => 'Only .docx files are supported'], Response::HTTP_BAD_REQUEST);
            }
            $text = $this->extractTextFromDocx($file->getPathname());
            if ($text === null) {
                return $this->json(['success' => false, 'error' => 'Could not extract text from DOCX'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        } else {
            $body = json_decode($request->getContent(), true);
            $text = $body['text'] ?? null;
        }

        if (!$text || trim($text) === '') {
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
            if ($parsed === null || !is_array($parsed)) {
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
            $this->logger->error('Import parse failed: ' . $e->getMessage());

            return $this->json(['success' => false, 'error' => 'AI parsing failed: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
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

        $entryId = 'entry_' . bin2hex(random_bytes(6));

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
            if (array_key_exists($field, $data)) {
                $existing[$field] = $data[$field];
            }
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
        if ($raw === null || $raw === '' || $raw === false || (is_string($raw) && strtolower(trim($raw)) === 'never')) {
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
        if ($months === null || $months <= 0 || !is_string($baseIso) || $baseIso === '') {
            return null;
        }
        try {
            $dt = new \DateTimeImmutable($baseIso);
        } catch (\Throwable) {
            return null;
        }

        return $dt->modify('+' . $months . ' months')->format('c');
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

        $dir = $this->uploadDir . '/' . $userId . '/synaform/candidates/' . $candidateId;
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

        $file = $request->files->get('file');
        if (!$file) {
            return $this->json(['success' => false, 'error' => 'No file uploaded'], Response::HTTP_BAD_REQUEST);
        }

        $ext = strtolower($file->getClientOriginalExtension());
        if (!in_array($ext, self::ALLOWED_UPLOAD_EXTENSIONS, true)) {
            return $this->json(['success' => false, 'error' => 'Unsupported file type: ' . $ext], Response::HTTP_BAD_REQUEST);
        }

        $dir = $this->uploadDir . '/' . $userId . '/synaform/candidates/' . $candidateId;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $originalName = $file->getClientOriginalName();
        $storedName = 'cv.' . $ext;
        $file->move($dir, $storedName);

        $existing['files']['cv'] = [
            'filename' => $originalName,
            'stored_as' => $storedName,
            'mime_type' => $file->getClientMimeType() ?? mime_content_type($dir . '/' . $storedName) ?? 'application/octet-stream',
            'size' => filesize($dir . '/' . $storedName),
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

        $file = $request->files->get('file');
        if (!$file) {
            return $this->json(['success' => false, 'error' => 'No file uploaded'], Response::HTTP_BAD_REQUEST);
        }

        $dir = $this->uploadDir . '/' . $userId . '/synaform/candidates/' . $candidateId;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $originalName = $file->getClientOriginalName();
        $safeFilename = bin2hex(random_bytes(4)) . '-' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
        $file->move($dir, $safeFilename);

        $docEntry = [
            'filename' => $originalName,
            'stored_as' => $safeFilename,
            'mime_type' => $file->getClientMimeType() ?? 'application/octet-stream',
            'size' => filesize($dir . '/' . $safeFilename),
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

        $dir = $this->uploadDir . '/' . $userId . '/synaform/candidates/' . $candidateId;

        if ($slot === 'cv') {
            $cvFile = $entry['files']['cv'] ?? null;
            if (!$cvFile) {
                return $this->json(['success' => false, 'error' => 'No CV file found'], Response::HTTP_NOT_FOUND);
            }
            $storedAs = $cvFile['stored_as'] ?? '';
            if ($storedAs && is_file($dir . '/' . $storedAs)) {
                unlink($dir . '/' . $storedAs);
            }
            unset($entry['files']['cv']);
        } elseif ($slot === 'additional') {
            $additionalDocs = $entry['files']['additional'] ?? [];
            if (!isset($additionalDocs[$fileIndex])) {
                return $this->json(['success' => false, 'error' => 'File not found at index'], Response::HTTP_NOT_FOUND);
            }
            $storedAs = $additionalDocs[$fileIndex]['stored_as'] ?? '';
            if ($storedAs && is_file($dir . '/' . $storedAs)) {
                unlink($dir . '/' . $storedAs);
            }
            array_splice($entry['files']['additional'], $fileIndex, 1);
        } elseif ($slot === 'urls') {
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

        if ($url === '') {
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
            'id' => 'url_' . bin2hex(random_bytes(5)),
            'url' => $url,
            'label' => $label !== '' ? $label : $host,
            'host' => $host,
            'kind' => $kind,
            'text_snippet' => $snippet,
            'fetch_error' => $fetchError,
            'fetched_at' => $snippet !== null ? date('c') : null,
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
        $existing['fetched_at'] = $snippet !== null ? date('c') : null;
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
                $relativePath = $userId . '/synaform/candidates/' . $candidateId . '/' . $storedAs;
                [$cvText, $extractMeta] = $this->fileProcessor->extractText($relativePath, $ext, $userId);
                $cvText = $cvText ?? '';
                if (trim($cvText) !== '') {
                    $rawText .= "=== Primary Document ===\n" . $cvText . "\n\n";
                }
            }
            foreach ($entry['files']['urls'] ?? [] as $urlEntry) {
                $snippet = $urlEntry['text_snippet'] ?? null;
                if (is_string($snippet) && trim($snippet) !== '') {
                    $host = $urlEntry['host'] ?? 'url';
                    $kind = $urlEntry['kind'] ?? 'web';
                    $rawText .= "=== URL Source ({$kind} · {$host} · " . ($urlEntry['url'] ?? '') . ") ===\n" . $snippet . "\n\n";
                }
            }

            if (trim($rawText) === '') {
                return $this->json(['success' => false, 'error' => 'Could not extract text from CV or URL sources'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $form = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_FORM, $entry['form_id'] ?? 'default');
            $formFields = $form['fields'] ?? [];
            $extractionLanguage = $this->resolveExtractionLanguage($userId, $form);

            $prompt = $this->buildExtractionPrompt($rawText, $formFields, $extractionLanguage);
            $messages = [
                ['role' => 'system', 'content' => 'You are a precise CV data extraction assistant. Return only valid JSON.'],
                ['role' => 'user', 'content' => $prompt],
            ];
            $aiOptions = $this->resolveAiModelOptions($userId);
            $result = $this->aiFacade->chat($messages, $userId, $aiOptions);
            $content = $result['content'] ?? '';

            $extracted = $this->parseJsonFromAiResponse($content);
            if ($extracted === null) {
                return $this->json(['success' => false, 'error' => 'Failed to parse AI extraction result'], Response::HTTP_UNPROCESSABLE_ENTITY);
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
                'error' => 'Extraction failed: ' . $e->getMessage(),
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
    public function candidatesParseDocuments(int $userId, string $candidateId, #[CurrentUser] ?User $user): JsonResponse
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

        $candidateDir = $this->uploadDir . '/' . $userId . '/synaform/candidates/' . $candidateId;
        $allTexts = [];

        if (!empty($entry['files']['cv'])) {
            $storedAs = $entry['files']['cv']['stored_as'] ?? 'cv.pdf';
            $ext = strtolower(pathinfo($storedAs, PATHINFO_EXTENSION));
            $relativePath = $userId . '/synaform/candidates/' . $candidateId . '/' . $storedAs;
            try {
                [$text] = $this->fileProcessor->extractText($relativePath, $ext, $userId);
                if (!empty(trim((string) $text))) {
                    $allTexts[] = '=== Primary Document (' . ($entry['files']['cv']['filename'] ?? $storedAs) . ') ===' . "\n" . $text;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Parse-documents: failed to extract CV text', ['error' => $e->getMessage()]);
            }
        }

        foreach ($entry['files']['additional'] ?? [] as $doc) {
            $storedAs = $doc['stored_as'] ?? '';
            if (empty($storedAs) || !is_file($candidateDir . '/' . $storedAs)) {
                continue;
            }
            $ext = strtolower(pathinfo($storedAs, PATHINFO_EXTENSION));
            $relativePath = $userId . '/synaform/candidates/' . $candidateId . '/' . $storedAs;
            try {
                [$text] = $this->fileProcessor->extractText($relativePath, $ext, $userId);
                if (!empty(trim((string) $text))) {
                    $allTexts[] = '=== Document (' . ($doc['filename'] ?? $storedAs) . ') ===' . "\n" . $text;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Parse-documents: failed to extract doc text', ['error' => $e->getMessage(), 'file' => $storedAs]);
            }
        }

        foreach ($entry['files']['urls'] ?? [] as $urlEntry) {
            $snippet = $urlEntry['text_snippet'] ?? null;
            if (is_string($snippet) && trim($snippet) !== '') {
                $host = $urlEntry['host'] ?? 'url';
                $kind = $urlEntry['kind'] ?? 'web';
                $allTexts[] = "=== URL Source ({$kind} · {$host} · " . ($urlEntry['url'] ?? '') . ") ===\n" . $snippet;
            }
        }

        if (empty($allTexts)) {
            return $this->json(['success' => false, 'error' => 'No documents or URLs uploaded, or text could not be extracted from any source'], Response::HTTP_BAD_REQUEST);
        }

        $combinedText = implode("\n\n", $allTexts);

        $fields = $form['fields'] ?? [];
        $fieldDescriptions = [];
        foreach ($fields as $field) {
            $type = $field['type'] ?? 'text';
            $desc = $field['key'] . ' (' . $type . '): ' . ($field['label'] ?? $field['key']);
            if (!empty($field['hint'])) {
                $desc .= ' — ' . $field['hint'];
            }
            if (!empty($field['options'])) {
                $desc .= ' [allowed values: ' . implode(', ', $field['options']) . ']';
            }
            if ($type === 'list') {
                $desc .= ' [return as JSON array of strings]';
            }
            if ($type === 'table') {
                $columns = $field['columns'] ?? [];
                $colDescs = array_map(function ($c) {
                    $key = (string) ($c['key'] ?? '');
                    $label = (string) ($c['label'] ?? $key);
                    $colType = (string) ($c['type'] ?? 'text');
                    return $key . ' (' . $label . ', type=' . $colType . ')';
                }, $columns);
                $listCols = array_values(array_filter(
                    array_map(fn ($c) => ($c['type'] ?? 'text') === 'list' ? (string) ($c['key'] ?? '') : null, $columns),
                ));
                $desc .= ' — columns: ' . implode(', ', $colDescs)
                    . '. [return as JSON array of objects with these column keys]';
                if (!empty($listCols)) {
                    $desc .= ' — the following columns must themselves be JSON arrays of short strings (one bullet per item, no markdown, no dashes, no numbering): '
                        . implode(', ', $listCols);
                }
            }
            $fieldDescriptions[] = $desc;
        }

        $fieldsBlock = implode("\n", array_map(fn ($d) => '- ' . $d, $fieldDescriptions));

        $extractionLanguage = $this->resolveExtractionLanguage($userId, $form);
        $extractionLanguageName = $this->languageName($extractionLanguage);

        $prompt = <<<PROMPT
        You are an assistant that extracts form field values from documents.
        Below are the form fields that need to be filled, followed by the document text.

        IMPORTANT - Output language: All free-text string values you return MUST be
        written in {$extractionLanguageName}. If the source documents are in another
        language, translate descriptive text (job titles, summaries, list items,
        achievements, education descriptions, etc.) into {$extractionLanguageName}.
        Proper nouns (people, company names, cities), email addresses, phone numbers,
        URLs, and dates stay verbatim.

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

        Form fields:
        {$fieldsBlock}

        Documents:
        ---
        {$combinedText}
        ---

        Return ONLY a valid JSON object where keys are the field keys and values are the extracted data.
        PROMPT;

        try {
            $messages = [
                ['role' => 'system', 'content' => 'You are a precise document parsing assistant. Return only valid JSON.'],
                ['role' => 'user', 'content' => $prompt],
            ];
            $aiOptions = $this->resolveAiModelOptions($userId);
            $result = $this->aiFacade->chat($messages, $userId, $aiOptions);
            $content = $result['content'] ?? '';

            $parsed = $this->parseJsonFromAiResponse($content);
            if ($parsed === null) {
                return $this->json(['success' => false, 'error' => 'Failed to parse AI response'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return $this->json([
                'success' => true,
                'suggestions' => $parsed,
                'documents_parsed' => count($allTexts),
                'model' => $result['model'] ?? 'unknown',
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Parse-documents failed', ['error' => $e->getMessage(), 'candidateId' => $candidateId]);
            return $this->json(['success' => false, 'error' => 'Document parsing failed: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
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
        summary: 'Generate a DOCX document from template and resolved variables',
        security: [['ApiKey' => []]],
        tags: ['Synaform Plugin']
    )]
    #[OA\Response(response: 200, description: 'Generated document metadata')]
    public function candidatesGenerate(int $userId, string $candidateId, string $templateId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $entry = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);
        if (!$entry) {
            return $this->json(['success' => false, 'error' => 'Entry not found'], Response::HTTP_NOT_FOUND);
        }

        $template = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_TEMPLATE, $templateId);
        if (!$template) {
            return $this->json(['success' => false, 'error' => 'Template not found'], Response::HTTP_NOT_FOUND);
        }

        $templatePath = $this->uploadDir . '/' . $userId . '/synaform/templates/' . $templateId . '/template.docx';
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
                $yesLabel = is_string($designer['yes_label'] ?? null) && $designer['yes_label'] !== ''
                    ? $designer['yes_label']
                    : 'Ja';
                $noLabel = is_string($designer['no_label'] ?? null) && $designer['no_label'] !== ''
                    ? $designer['no_label']
                    : 'Nein';
                $variables[$k] = $v ? $yesLabel : $noLabel;
            }

            $arrays = $this->collectArrayData($entry, $formFields);
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
            $this->processImages($tp, $formFields, $entry);
            $this->processScalars($tp, $classified['scalars'], $variables);

            $docId = 'doc_' . bin2hex(random_bytes(6));
            $genDir = $this->uploadDir . '/' . $userId . '/synaform/candidates/' . $candidateId . '/generated';
            if (!is_dir($genDir)) {
                mkdir($genDir, 0755, true);
            }
            $outputPath = $genDir . '/' . $docId . '.docx';
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

            if (is_file($cleanedPath)) {
                unlink($cleanedPath);
            }

            $docMeta = [
                'id' => $docId,
                'template_id' => $templateId,
                'template_name' => $template['name'] ?? $templateId,
                'filename' => $docId . '.docx',
                'generated_at' => date('c'),
                'variable_snapshot' => $variables,
            ];

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
                'error' => 'Generation failed: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
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

        $filePath = $this->uploadDir . '/' . $userId . '/synaform/candidates/' . $candidateId . '/generated/' . $docMeta['filename'];
        if (!is_file($filePath)) {
            return $this->json(['success' => false, 'error' => 'Document file not found on disk'], Response::HTTP_NOT_FOUND);
        }

        $downloadName = ($entry['name'] ?? 'document') . '_' . ($docMeta['template_name'] ?? 'template') . '.docx';
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

        $docxPath = $this->uploadDir . '/' . $userId . '/synaform/candidates/' . $candidateId . '/generated/' . $docMeta['filename'];
        if (!is_file($docxPath)) {
            return $this->json(['success' => false, 'error' => 'Document file not found on disk'], Response::HTTP_NOT_FOUND);
        }

        $pdfPath = $this->convertDocxToPdf($docxPath);
        if ($pdfPath === null) {
            return $this->json([
                'success' => false,
                'error' => 'libreoffice is not installed on the backend. True-preview PDF is unavailable; the HTML live preview still works. Install libreoffice in the backend image or run a gotenberg sidecar to enable this feature.',
            ], Response::HTTP_NOT_IMPLEMENTED);
        }

        return new BinaryFileResponse($pdfPath, 200, [
            'Content-Type'  => 'application/pdf',
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

        $filePath = $this->uploadDir . '/' . $userId . '/synaform/candidates/' . $candidateId . '/generated/' . $docMeta['filename'];
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

        $file = $request->files->get('file');
        if (!$file) {
            return $this->json(['success' => false, 'error' => 'No file uploaded'], Response::HTTP_BAD_REQUEST);
        }

        $mime = $file->getMimeType() ?? 'application/octet-stream';
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];
        if (!in_array($mime, $allowedMimes, true)) {
            return $this->json(['success' => false, 'error' => 'Unsupported image format: ' . $mime], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $sizeLimit = 8 * 1024 * 1024;
        if ($file->getSize() > $sizeLimit) {
            return $this->json(['success' => false, 'error' => 'Image too large (max 8 MB)'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $ext = $this->mimeToExtension($mime);
        $dir = $this->uploadDir . '/' . $userId . '/synaform/candidates/' . $candidateId . '/images';
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        // Wipe any previous file for this key (different extension possible).
        foreach (glob($dir . '/' . $key . '.*') ?: [] as $old) {
            @unlink($old);
        }

        $filename = $key . '.' . $ext;
        $file->move($dir, $filename);

        $storedPath = $dir . '/' . $filename;
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
            'Content-Type'  => $meta['mime'] ?? 'application/octet-stream',
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
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            'image/bmp'  => 'bmp',
            default      => 'bin',
        };
    }

    // =========================================================================
    // Assets (frontend)
    // =========================================================================

    #[Route('/assets/{path}', name: 'assets', methods: ['GET'], requirements: ['path' => '.+'])]
    public function assets(string $path): Response
    {
        $pluginDir = dirname(__DIR__, 2);
        $file = $pluginDir . '/frontend/' . $path;

        if (!is_file($file) || !str_starts_with(realpath($file), realpath($pluginDir . '/frontend'))) {
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
        if ($user === null) {
            return false;
        }

        if ($user->getId() !== $userId) {
            return false;
        }

        return $this->configRepository->getValue($userId, self::CONFIG_GROUP, 'enabled') === '1';
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
        if ($zip->open($docxPath) !== true) {
            $this->logger->warning('Failed to open DOCX for placeholder extraction', ['path' => $docxPath]);
            return [];
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            return [];
        }

        // Strip namespace prefixes from tags so DOMDocument can find elements by local name
        $xml = preg_replace('/<(\/?)(?:w|mc|r|wp|wps|a|v|o):/', '<$1', $xml);
        $xml = preg_replace('/\s+xmlns[^=]*="[^"]*"/', '', $xml);

        $doc = new \DOMDocument();
        @$doc->loadXML($xml);

        $paragraphs = $doc->getElementsByTagName('p');
        $found = [];

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
     * Convert a DOCX to PDF via a headless LibreOffice invocation. The PDF is
     * written next to the source DOCX with a `.pdf` extension and cached there
     * as long as the DOCX is fresher (mtime >= DOCX mtime). Returns null if
     * the backend doesn't have libreoffice/soffice available — the caller
     * should treat that as a soft 501.
     */
    private function convertDocxToPdf(string $docxPath): ?string
    {
        $pdfPath = preg_replace('/\.docx$/i', '.pdf', $docxPath) ?? ($docxPath . '.pdf');

        if (is_file($pdfPath) && filemtime($pdfPath) >= filemtime($docxPath)) {
            return $pdfPath;
        }

        $binary = null;
        foreach (['libreoffice', 'soffice'] as $candidate) {
            $which = @shell_exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null');
            if (is_string($which) && trim($which) !== '') {
                $binary = trim($which);
                break;
            }
        }
        if ($binary === null) {
            return null;
        }

        $outDir = dirname($docxPath);
        // LibreOffice writes to `$outDir/<basename>.pdf`; we use --outdir to make
        // that explicit. Isolate each conversion with a per-process user profile
        // to avoid lock contention when two conversions race.
        $userProfile = sys_get_temp_dir() . '/lo-profile-' . getmypid() . '-' . bin2hex(random_bytes(4));
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

        if ($code !== 0 || !is_file($pdfPath)) {
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

    private function buildExtractionPrompt(string $rawText, array $formFields = [], string $language = 'de'): string
    {
        $languageName = $this->languageName($language);
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
            if ($key === '') {
                continue;
            }
            $coveredKeys[$key] = true;
            $label = $field['label'] ?? $key;
            $hint = !empty($field['hint']) ? ' — ' . $field['hint'] : '';

            if (($field['type'] ?? 'text') === 'table') {
                $columns = $field['columns'] ?? [];
                if (empty($columns)) {
                    continue;
                }
                $colDescs = [];
                foreach ($columns as $col) {
                    $colDescs[] = ($col['key'] ?? '') . ' (string): ' . ($col['label'] ?? $col['key'] ?? '');
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

            Fields to extract:
            {$fieldsBlock}

            Return ONLY valid JSON, no explanation.

            CV Text:
            ---
            {$rawText}
            ---
            PROMPT;
    }

    private function parseJsonFromAiResponse(string $content): ?array
    {
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?\s*```/s', $content, $match)) {
            $decoded = json_decode($match[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        if (preg_match('/\{[\s\S]*\}/', $content, $match)) {
            $decoded = json_decode($match[0], true);
            if (is_array($decoded)) {
                return $decoded;
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
        if ($code === '') {
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
                if (!is_string($tplId) || $tplId === '') {
                    continue;
                }
                $tpl = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_TEMPLATE, $tplId);
                $lang = $this->normalizeLanguage($tpl['language'] ?? null);
                if ($lang !== '') {
                    $templateLanguages[$lang] = true;
                }
            }
        }
        if (count($templateLanguages) === 1) {
            return array_key_first($templateLanguages);
        }

        $formLang = $this->normalizeLanguage($form['language'] ?? null);
        if ($formLang !== '') {
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
     * Best-effort, defensive: many public profile pages (LinkedIn in particular)
     * gate behind JavaScript or a login wall. When this is the case we still
     * return whatever HTML we managed to pull, along with an explanatory error.
     * The AI prompt tolerates partial / noisy input, so even a partial snippet
     * is more useful than nothing.
     *
     * @return array{0: ?string, 1: ?string}  [plainText|null, errorMessage|null]
     */
    private function fetchUrlText(string $url): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Synaform-Bot/1.0; +https://synaplan.com)',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9,de;q=0.8',
            ],
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch) ?: null;
        $status = (int) (curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0);
        curl_close($ch);

        if ($raw === false || $raw === '') {
            return [null, $err ?: 'Failed to fetch URL'];
        }

        $fetchError = null;
        if ($status >= 400) {
            $fetchError = 'HTTP ' . $status . ($status === 999 ? ' (rate-limited or login required)' : '');
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
            $cleaned = mb_substr($cleaned, 0, $cap) . "\n…[truncated]…";
        } elseif (strlen($cleaned) > $cap) {
            $cleaned = substr($cleaned, 0, $cap) . "\n…[truncated]…";
        }

        if ($cleaned === '') {
            return [null, $fetchError ?? 'No extractable text'];
        }

        return [$cleaned, $fetchError];
    }

    private function extractTextFromDocx(string $path): ?string
    {
        try {
            $phpWord = PhpWordIOFactory::load($path);
            $text = '';
            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $element) {
                    $text .= $this->extractElementText($element) . "\n";
                }
            }

            return trim($text) !== '' ? $text : null;
        } catch (\Throwable $e) {
            $this->logger->warning('DOCX text extraction failed: ' . $e->getMessage());

            return null;
        }
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
     * @return array<string, mixed>
     */
    private function normalizeDesignerConfig(?array $raw, string $fieldType): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        if ($fieldType === 'list') {
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
        } elseif ($fieldType === 'table') {
            if (array_key_exists('repeat_header', $raw)) {
                $out['repeat_header'] = (bool) $raw['repeat_header'];
            }
            if (array_key_exists('prevent_row_break', $raw)) {
                $out['prevent_row_break'] = (bool) $raw['prevent_row_break'];
            }
            if (array_key_exists('keep_with_prev', $raw)) {
                $out['keep_with_prev'] = (bool) $raw['keep_with_prev'];
            }
        } elseif ($fieldType === 'checkbox') {
            if (isset($raw['checked_glyph']) && is_string($raw['checked_glyph']) && $raw['checked_glyph'] !== '') {
                $out['checked_glyph'] = mb_substr((string) $raw['checked_glyph'], 0, 4);
            }
            if (isset($raw['unchecked_glyph']) && is_string($raw['unchecked_glyph']) && $raw['unchecked_glyph'] !== '') {
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
        } elseif ($fieldType === 'image') {
            if (isset($raw['width'])) {
                $out['width'] = max(16, min(1600, (int) $raw['width']));
            }
            if (isset($raw['height'])) {
                $out['height'] = max(16, min(2000, (int) $raw['height']));
            }
            if (array_key_exists('preserve_ratio', $raw)) {
                $out['preserve_ratio'] = (bool) $raw['preserve_ratio'];
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
            if ($fallback !== null) {
                $field['fallback'] = $fallback;
            }
            if (!empty($raw['hint'])) {
                $field['hint'] = (string) $raw['hint'];
            }
            if ($type === 'select' && !empty($raw['options']) && is_array($raw['options'])) {
                $field['options'] = array_values(array_filter(array_map(static fn ($o) => is_string($o) ? $o : (string) $o, $raw['options'])));
            }
            if ($type === 'table' && !empty($raw['columns']) && is_array($raw['columns'])) {
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
                    $cols[] = [
                        'key' => (string) $col['key'],
                        'label' => (string) ($col['label'] ?? $col['key']),
                        'type' => $colType,
                    ];
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
            if ($key === '') {
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
     * @param list<array{key: string, type: string}> $placeholders
     * @param array<string, bool>                    $existingKeys  map of keys already in the form
     * @return array{fields: list<array<string, mixed>>, summary: array<string, int>}
     */
    private function buildVariableSuggestions(array $placeholders, array $existingKeys): array
    {
        $fields = [];
        $summary = ['new' => 0, 'duplicate' => 0, 'structural' => 0, 'tables' => 0, 'checkboxes' => 0, 'lists' => 0, 'texts' => 0];

        $rowGroups = [];   // group => [col => true]
        $checkGroups = []; // key => [yes => true, no => true]

        foreach ($placeholders as $ph) {
            $key = $ph['key'] ?? '';
            $type = $ph['type'] ?? '';
            if ($key === '') {
                continue;
            }

            if ($type === 'block_marker') {
                $summary['structural']++;
                continue;
            }

            if ($type === 'checkbox' && str_starts_with($key, 'checkb.')) {
                $parts = explode('.', $key);
                if (count($parts) >= 3) {
                    $grp = $parts[1];
                    $leaf = end($parts);
                    $checkGroups[$grp][$leaf] = true;
                    continue;
                }
            }

            if ($type === 'row_field' && str_contains($key, '.')) {
                $segs = explode('.', $key);
                if (count($segs) >= 3) {
                    $group = $segs[0];
                    $col = $segs[1];
                    $rowGroups[$group][$col] = true;
                    continue;
                }
            }

            $field = [
                'key' => $key,
                'label' => $this->humanizeKey($key),
                'type' => $type === 'list' ? 'list' : 'text',
                'required' => false,
                'source' => 'form',
                '_status' => isset($existingKeys[$key]) ? 'duplicate' : 'new',
            ];
            if ($field['type'] === 'list') {
                $summary['lists']++;
            } else {
                $summary['texts']++;
            }
            $summary[$field['_status']]++;
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
                'designer' => ['repeat_header' => true, 'prevent_row_break' => true],
                '_status' => isset($existingKeys[$group]) ? 'duplicate' : 'new',
            ];
            $summary['tables']++;
            $summary[$field['_status']]++;
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
            $summary['checkboxes']++;
            $summary[$field['_status']]++;
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
        if ($s === '') {
            return $key;
        }
        return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }

    private function normalizeSource(?string $source): ?string
    {
        if ($source === null || $source === '') {
            return null;
        }
        $valid = ['form', 'ai'];

        return in_array($source, $valid, true) ? $source : 'form';
    }

    /**
     * Build variable source map from form fields, falling back to hardcoded defaults.
     *
     * @param array $formFields The form's fields[] array, each with optional 'source' and 'fallback'
     * @return array<string, array{primary: string, fallback?: string}>
     */
    private function getVariableSources(array $formFields = []): array
    {
        $sources = [];
        foreach ($formFields as $field) {
            $key = $field['key'] ?? null;
            if ($key === null) {
                continue;
            }
            $primary = $field['source'] ?? 'form';
            $sources[$key] = ['primary' => $primary];
            $fallback = $field['fallback'] ?? null;
            if ($fallback !== null && $fallback !== '') {
                $sources[$key]['fallback'] = $fallback;
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
            if (array_key_exists($key, $overrides) && $overrides[$key] !== null) {
                $variables[$key] = $overrides[$key];
                continue;
            }

            $primarySource = $config['primary'];
            $fallbackSource = $config['fallback'] ?? null;
            $value = null;

            if ($primarySource === 'ai') {
                $value = $aiData[$key] ?? null;
            } elseif ($primarySource === 'form') {
                $value = $formData[$key] ?? null;
            }

            if ($value === null && $fallbackSource !== null) {
                if ($fallbackSource === 'ai') {
                    $value = $aiData[$key] ?? null;
                } elseif ($fallbackSource === 'form') {
                    $value = $formData[$key] ?? null;
                }
            }

            $variables[$key] = $value;
        }

        if (isset($variables['expectedansalary']) && strtolower((string) $variables['expectedansalary']) === 'nicht relevant') {
            $variables['expectedansalary'] = null;
        }
        if (isset($variables['workinghours']) && strtolower((string) $variables['workinghours']) === 'nicht relevant') {
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
            $cbYes = strtolower((string) ($variables[$cbKey] ?? '')) === 'ja'
                || ($variables[$cbKey] === true);
            $variables['checkb.' . $cbKey . '.yes'] = $cbYes;
            $variables['checkb.' . $cbKey . '.no'] = !$cbYes;
        }

        // Backward compat: travelorcommute → commute/travel checkboxes
        if (!isset($variables['commute']) && isset($variables['travelorcommute'])) {
            $commuteVal = $formData['commute'] ?? $variables['travelorcommute'] ?? '';
            $commuteYes = strtolower((string) $commuteVal) === 'ja';
            $variables['checkb.commute.yes'] = $commuteYes;
            $variables['checkb.commute.no'] = !$commuteYes;
        }
        if (!isset($variables['travel']) && isset($variables['travelorcommute'])) {
            $travelVal = $formData['travel'] ?? $variables['travelorcommute'] ?? '';
            $travelYes = strtolower((string) $travelVal) === 'ja';
            $variables['checkb.travel.yes'] = $travelYes;
            $variables['checkb.travel.no'] = !$travelYes;
        }

        return [
            'variables' => $variables,
        ];
    }

    private function resolveAiModelOptions(int $userId): array
    {
        $modelId = $this->modelConfigService->getDefaultModel('CHAT', $userId);
        if ($modelId) {
            return [
                'model' => $this->modelConfigService->getModelName($modelId),
                'provider' => $this->modelConfigService->getProviderForModel($modelId),
            ];
        }

        $modelId = $this->modelConfigService->getDefaultModel('CHAT', 0);
        if ($modelId) {
            return [
                'model' => $this->modelConfigService->getModelName($modelId),
                'provider' => $this->modelConfigService->getProviderForModel($modelId),
            ];
        }

        return [];
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
            if ($key === '' || ($type !== 'table' && $type !== 'list')) {
                continue;
            }
            $scannedKeys[$key] = true;
            $primarySource = $field['source'] ?? 'form';
            $fallbackSource = $field['fallback'] ?? null;

            $val = $overrides[$key] ?? null;
            if ($val === null) {
                $val = $primarySource === 'ai' ? ($aiData[$key] ?? null) : ($formData[$key] ?? null);
            }
            if ($val === null && $fallbackSource !== null) {
                $val = $fallbackSource === 'ai' ? ($aiData[$key] ?? null) : ($formData[$key] ?? null);
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
                if ($cbKey !== '') {
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
            if ($count === 0) {
                foreach ($fields as $field) {
                    $tp->setValue($field, '');
                }
                continue;
            }

            $anchorField = $fields[0] ?? null;
            if ($anchorField === null) {
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

            for ($i = 0; $i < $count; $i++) {
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
                    $tp->setValue($phName . '#' . $num, $value);
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
            if ($count === 0) {
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

            for ($i = 0; $i < $count; $i++) {
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
                || $designer['clickable_checkbox'] !== false;

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
        return '[[SYNCB|' . ($checked ? 'on' : 'off') . '|' . $checkedGlyph . '|' . $uncheckedGlyph . ']]';
    }

    /**
     * LIST mode: array values rendered as newline-separated text with OOXML line breaks.
     */
    private function processLists(TemplateProcessor $tp, array $listKeys, array $variables, array $designerMap = []): void
    {
        foreach ($listKeys as $key) {
            $val = $variables[$key] ?? null;
            $items = is_array($val) ? array_map('strval', $val) : ((string) ($val ?? '') === '' ? [] : [(string) $val]);
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
     * placeholder untouched; the scalar pass then replaces it with an empty
     * string so it doesn't show up as literal text in the output.
     *
     * @param array<int, array<string, mixed>> $formFields
     * @param array<string, mixed>             $entry
     */
    private function processImages(TemplateProcessor $tp, array $formFields, array $entry): void
    {
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
            $width = (int) ($designer['width'] ?? 140);
            $height = (int) ($designer['height'] ?? 180);

            try {
                $tp->setImageValue($key, [
                    'path'   => $meta['path'],
                    'width'  => $width,
                    'height' => $height,
                    'ratio'  => !empty($designer['preserve_ratio']),
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('Image placeholder replacement failed', [
                    'key'   => $key,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Scalar mode: simple text replacement for all remaining placeholders.
     */
    private function processScalars(TemplateProcessor $tp, array $scalarKeys, array $variables): void
    {
        foreach ($scalarKeys as $key) {
            $value = $variables[$key] ?? null;
            $value = htmlspecialchars((string) ($value ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $tp->setValue($key, $value);
        }
    }

    private function cleanTemplateMacros(string $docxPath): string
    {
        $cleanedPath = dirname($docxPath) . '/template_cleaned.docx';
        copy($docxPath, $cleanedPath);

        $zip = new \ZipArchive();
        if ($zip->open($cleanedPath) !== true) {
            return $cleanedPath;
        }

        $xml = $zip->getFromName('word/document.xml');
        if ($xml === false) {
            $zip->close();
            return $cleanedPath;
        }

        $xml = preg_replace('/\{(<[^>]*>)*\{/', '{{', $xml);
        $xml = preg_replace('/\}(<[^>]*>)*\}/', '}}', $xml);

        $xml = preg_replace_callback('/\{\{(.*?)\}\}/s', function ($match) {
            $inner = strip_tags($match[1]);
            $inner = preg_replace('/\s+/', '', $inner);
            return '{{' . trim($inner) . '}}';
        }, $xml);

        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        return $cleanedPath;
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
     * Empty lists cause the host paragraph to be dropped entirely.
     *
     * @param list<string>                           $listKeys
     * @param array<string, mixed>                   $variables
     * @param array<string, mixed>                   $arrays
     * @param array<string, array<string, mixed>>    $designerMap
     * @return list<string>                          keys that were successfully expanded
     */
    private function expandListParagraphs(string $docxPath, array $listKeys, array $variables, array $arrays, array $designerMap = []): array
    {
        if (empty($listKeys)) {
            return [];
        }

        $zip = new \ZipArchive();
        if ($zip->open($docxPath) !== true) {
            $this->logger->warning('Failed to open DOCX for list expansion', ['path' => $docxPath]);
            return [];
        }

        $xml = $zip->getFromName('word/document.xml');
        if ($xml === false) {
            $zip->close();
            return [];
        }

        $numberingXml = $zip->getFromName('word/numbering.xml');
        $orderedNumId = is_string($numberingXml) ? $this->detectOrderedNumId($numberingXml) : null;
        $bulletNumId  = is_string($numberingXml) ? $this->detectBulletNumId($numberingXml) : null;

        $originalXml = $xml;
        $expanded = [];

        foreach ($listKeys as $key) {
            $raw = array_key_exists($key, $variables) ? $variables[$key] : ($arrays[$key] ?? null);
            $items = $this->normalizeListValue($raw);

            $placeholder = '{{' . $key . '}}';
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
            $pattern = '#<w:p\b[^>]*>(?:(?!</w:p>).)*?' . preg_quote($placeholder, '#') . '(?:(?!</w:p>).)*?</w:p>#s';

            $replacementCount = 0;
            $newXml = preg_replace_callback(
                $pattern,
                function (array $match) use ($placeholder, $items, $wantsOrdered, $preventOrphans, $topBlankLine, $bottomBlankLine, $orderedNumId, $bulletNumId, &$replacementCount): string {
                    $replacementCount++;
                    $paragraph = $match[0];

                    if ($items === [] || $items === null) {
                        return '';
                    }

                    // If the designer wants an ordered (OL) list and the template
                    // paragraph has bullet numPr, rewrite numPr to point at the
                    // detected ordered numId. If no numPr exists, synthesize one.
                    $paragraphForItem = $paragraph;
                    if ($wantsOrdered && $orderedNumId !== null) {
                        $paragraphForItem = $this->swapListNumPr($paragraphForItem, $orderedNumId);
                    } elseif (!$wantsOrdered && $bulletNumId !== null) {
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

            if ($newXml !== null && $replacementCount > 0) {
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
                    return '<w:numPr><w:ilvl w:val="0"/><w:numId w:val="' . $numId . '"/></w:numPr>';
                },
                $paragraphXml,
                1
            ) ?? $paragraphXml;
        }

        $numPr = '<w:numPr><w:ilvl w:val="0"/><w:numId w:val="' . $numId . '"/></w:numPr>';
        if (preg_match('#<w:pPr\b[^>]*>#', $paragraphXml)) {
            return preg_replace('#(<w:pPr\b[^>]*>)#', '$1' . $numPr, $paragraphXml, 1) ?? $paragraphXml;
        }

        // No pPr: inject a minimal one right after the opening <w:p …>.
        return preg_replace(
            '#(<w:p\b[^>]*>)#',
            '$1<w:pPr>' . $numPr . '</w:pPr>',
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

        return '<w:p>' . $pPr . '</w:p>';
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
     * @param array<int, array<string, mixed>>              $formFields
     * @param array<string, array<int, array<string, mixed>>> $arrays
     * @return array<string, true>                          keys that were expanded
     */
    private function expandTableBlocks(string $docxPath, array $formFields, array $arrays, array $richSubfields = self::RICH_ROW_SUBFIELDS_DEFAULT): array
    {
        $handled = [];

        $tableFields = [];
        foreach ($formFields as $field) {
            $key = $field['key'] ?? '';
            $type = $field['type'] ?? '';
            $cols = $field['columns'] ?? [];
            if ($key === '' || $type !== 'table' || !is_array($cols) || count($cols) === 0) {
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
        if ($zip->open($docxPath) !== true) {
            return $handled;
        }

        $xml = $zip->getFromName('word/document.xml');
        if ($xml === false) {
            $zip->close();
            return $handled;
        }

        $originalXml = $xml;

        foreach ($tableFields as $key => $cfg) {
            $token = '{{' . $key . '}}';
            if (!str_contains($xml, $token)) {
                continue;
            }

            // Locate the nearest <w:tr> that contains the token — but only if the
            // token lives inside a <w:tbl>. Placeholders outside a table fall
            // back to the normal list/scalar path.
            $tokenPos = strpos($xml, $token);
            if ($tokenPos === false) {
                continue;
            }
            $before = substr($xml, 0, $tokenPos);
            $tblOpen = strrpos($before, '<w:tbl>');
            $tblClose = strrpos($before, '</w:tbl>');
            $insideTable = $tblOpen !== false && ($tblClose === false || $tblOpen > $tblClose);
            if (!$insideTable) {
                continue;
            }

            $trPattern = '#<w:tr\b[^>]*>(?:(?!</w:tr>).)*?' . preg_quote($token, '#') . '(?:(?!</w:tr>).)*?</w:tr>#s';
            if (preg_match($trPattern, $xml, $trMatch, PREG_OFFSET_CAPTURE) !== 1) {
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
            if ($cellCount === 0) {
                continue;
            }

            $columns = $cfg['columns'];
            $maxColumns = min($cellCount, count($columns));
            if ($maxColumns === 0) {
                continue;
            }

            // Build one fresh row per data entry.
            $allRows = '';
            $dataIdx = 0;
            foreach ($cfg['data'] as $rowData) {
                if (!is_array($rowData)) {
                    continue;
                }
                $dataIdx++;
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
                            $cellIdx++;
                            return $cell;
                        }
                        $colKey = $columns[$cellIdx]['key'] ?? '';
                        $cellIdx++;
                        if ($colKey === '') {
                            return $cell;
                        }

                        // Rich column? Leave a token for expandRichRowColumns to
                        // replace post-save with real bullet paragraphs. The
                        // {{...#N}} syntax is the same one processRowGroups uses,
                        // so the post-pass works uniformly for both row-flow modes.
                        $richKey = $key . '.' . $colKey;
                        if (in_array($richKey, $richSubfields, true)) {
                            $placeholder = '{{' . $richKey . '#' . $dataIdx . '}}';
                            $escaped = $placeholder;
                        } else {
                            $value = (string) ($rowData[$colKey] ?? '');
                            $escaped = $this->escapeForWordXml($value);
                        }

                        // Replace first <w:t>…</w:t> with the escaped value.
                        if (preg_match('#<w:t\b[^>]*>.*?</w:t>#s', $cell)) {
                            return preg_replace(
                                '#<w:t\b[^>]*>.*?</w:t>#s',
                                '<w:t xml:space="preserve">' . $escaped . '</w:t>',
                                $cell,
                                1
                            ) ?? $cell;
                        }
                        // No run at all — inject one before </w:tc>.
                        return preg_replace(
                            '#</w:tc>\s*$#s',
                            '<w:p><w:r><w:t xml:space="preserve">' . $escaped . '</w:t></w:r></w:p></w:tc>',
                            $cell,
                            1
                        ) ?? $cell;
                    },
                    $newRow
                ) ?? $newRow;
                $allRows .= $newRow;
            }

            if ($allRows === '') {
                continue;
            }

            $xml = substr($xml, 0, $rowStart) . $allRows . substr($xml, $rowEnd);
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
     * @return array<string, true>        set of group names handled by this pre-pass
     */
    private function cloneParagraphGroupsPrepass(string $docxPath, array $rowGroups, array $arrays, array $richSubfields = self::RICH_ROW_SUBFIELDS_DEFAULT): array
    {
        $handled = [];
        if (empty($rowGroups)) {
            return $handled;
        }

        $zip = new \ZipArchive();
        if ($zip->open($docxPath) !== true) {
            return $handled;
        }

        $xml = $zip->getFromName('word/document.xml');
        if ($xml === false) {
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
            if ($count === 0) {
                continue;
            }

            // If the first placeholder lives inside a <w:tr>, defer to cloneRow.
            $firstProbe = '{{' . $placeholders[0] . '}}';
            $firstPos = strpos($xml, $firstProbe);
            if ($firstPos === false) {
                continue;
            }
            $before = substr($xml, 0, $firstPos);
            $trOpenA = strrpos($before, '<w:tr ');
            $trOpenB = strrpos($before, '<w:tr>');
            $trOpen = max($trOpenA === false ? -1 : $trOpenA, $trOpenB === false ? -1 : $trOpenB);
            $trClose = strrpos($before, '</w:tr>');
            $insideRow = $trOpen !== -1 && ($trClose === false || $trOpen > $trClose);
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
                    if (str_contains($entry[0], '{{' . $ph . '}}')) {
                        $hitIndices[] = $idx;
                        break;
                    }
                }
            }
            if (empty($hitIndices)) {
                continue;
            }

            $firstIdx = min($hitIndices);
            $lastIdx  = max($hitIndices);
            $rangeStart = $paragraphs[0][$firstIdx][1];
            $lastEntry  = $paragraphs[0][$lastIdx];
            $rangeEnd   = $lastEntry[1] + strlen($lastEntry[0]);
            $blockXml   = substr($xml, $rangeStart, $rangeEnd - $rangeStart);

            // Build N copies with inline substitution. Rich sub-fields are suffixed
            // but not replaced — Phase B will expand them after saveAs.
            $allCopies = '';
            for ($n = 1; $n <= $count; $n++) {
                $row = is_array($data[$n - 1] ?? null) ? $data[$n - 1] : [];
                $copy = $blockXml;
                foreach ($placeholders as $ph) {
                    $suffix = substr($ph, strlen($groupName) + 1);
                    $cleanSubfield = str_replace('.N', '', $suffix);
                    $richKey = "{$groupName}.{$cleanSubfield}";
                    $token = '{{' . $ph . '}}';

                    if (in_array($richKey, $richSubfields, true)) {
                        $copy = str_replace($token, '{{' . $ph . '#' . $n . '}}', $copy);
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

            $xml = substr($xml, 0, $rangeStart) . $allCopies . substr($xml, $rangeEnd);
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
        if ($val === null) {
            return null;
        }
        if (is_array($val)) {
            $out = [];
            foreach ($val as $entry) {
                if (is_scalar($entry)) {
                    $s = trim((string) $entry);
                    if ($s !== '') {
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
                if ($p !== '') {
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
        if (preg_match('/\r|\n/', $escaped) === 1) {
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
     *
     * @param list<string>                     $richSubfields list of "group.col" strings
     * @param array<string, array<int, array<string, mixed>>> $arrays       group => rows[]
     * @param array<int, array<string, mixed>> $formFields
     */
    private function expandRichRowColumns(string $docxPath, array $richSubfields, array $arrays, array $formFields): void
    {
        if (empty($richSubfields)) {
            return;
        }

        // Map "group.col" → declared column `type`, so renderer knows whether
        // the stored value is expected to be an array or a legacy string.
        $columnTypes = [];
        foreach ($formFields as $field) {
            if (($field['type'] ?? '') !== 'table' || empty($field['key'])) {
                continue;
            }
            $group = (string) $field['key'];
            foreach (($field['columns'] ?? []) as $col) {
                if (!is_array($col) || empty($col['key'])) {
                    continue;
                }
                $columnTypes["{$group}.{$col['key']}"] = (string) ($col['type'] ?? 'text');
            }
        }

        $zip = new \ZipArchive();
        if ($zip->open($docxPath) !== true) {
            $this->logger->warning('Failed to open DOCX for rich-column expansion', ['path' => $docxPath]);
            return;
        }

        $xml = $zip->getFromName('word/document.xml');
        if ($xml === false) {
            $zip->close();
            return;
        }

        $numberingXml = $zip->getFromName('word/numbering.xml');
        $bulletNumId = is_string($numberingXml) ? $this->detectBulletNumId($numberingXml) : null;

        $originalXml = $xml;

        foreach ($richSubfields as $richKey) {
            [$group, $col] = array_pad(explode('.', $richKey, 2), 2, '');
            if ($group === '' || $col === '') {
                continue;
            }
            $rows = $arrays[$group] ?? [];
            if (!is_array($rows)) {
                continue;
            }
            $columnType = $columnTypes[$richKey] ?? 'text';

            foreach ($rows as $i => $row) {
                $num = $i + 1;

                // Pull the value — an array means the user/AI returned structured
                // bullets, a string means legacy markdown-ish prose.
                $raw = null;
                if (is_array($row)) {
                    $raw = $row[$col] ?? null;
                } elseif (is_string($row) && $col === 'details') {
                    // Legacy "stations" entries stored as plain strings.
                    $raw = $row;
                }

                foreach (["{{{$richKey}.N#{$num}}}", "{{{$richKey}#{$num}}}"] as $placeholder) {
                    if (!str_contains($xml, $placeholder)) {
                        continue;
                    }

                    $isEmpty = $raw === null
                        || $raw === ''
                        || (is_array($raw) && empty(array_filter($raw, static fn ($v) => trim((string) $v) !== '')));
                    if ($isEmpty) {
                        $xml = str_replace($placeholder, '', $xml);
                        continue;
                    }

                    $pattern = '#<w:p\b[^>]*>(?:(?!</w:p>).)*?' . preg_quote($placeholder, '#') . '(?:(?!</w:p>).)*?</w:p>#s';
                    $replaced = preg_replace_callback(
                        $pattern,
                        function (array $m) use ($raw, $columnType, $bulletNumId): string {
                            $basePPr = '';
                            if (preg_match('#<w:pPr>.*?</w:pPr>#s', $m[0], $pm)) {
                                $basePPr = $pm[0];
                            }
                            return $this->renderRichColumnXml($raw, $columnType, $basePPr, $bulletNumId);
                        },
                        $xml
                    );

                    if ($replaced !== null && $replaced !== $xml) {
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
     * Array-typed values produce one bullet per item (no heuristic parsing);
     * string-typed values go through the legacy parseStationDetails reader so
     * "date range + dash bullets" prose keeps working for old datasets.
     *
     * @param array<int, mixed>|string|null $raw
     */
    private function renderRichColumnXml(array|string|null $raw, string $columnType, string $basePPr, ?int $bulletNumId): string
    {
        if (is_array($raw)) {
            // Structured path: one bullet per non-empty item. Column type is
            // `list` here (or anything else that naturally arrays); empty items
            // are dropped so trailing input whitespace doesn't add blank lines.
            $items = array_values(array_filter(
                array_map(static fn ($v) => trim((string) $v), $raw),
                static fn ($v) => $v !== '',
            ));
            if (empty($items)) {
                return '';
            }
            return $this->renderBulletList($items, $basePPr, $bulletNumId);
        }

        // Legacy string path: keep the date-header / dash-bullet heuristic so
        // existing "stations.details" prose still renders nicely.
        $str = (string) ($raw ?? '');
        if (trim($str) === '') {
            return '';
        }
        return $this->renderStationDetailsXml($str, $basePPr, $bulletNumId);
    }

    /**
     * Helper: emit `<w:p>` paragraphs — one per item — using the bullet
     * numbering defined in the template's numbering.xml (falling back to a
     * "• " character prefix if no numId is available).
     *
     * @param list<string> $items
     */
    private function renderBulletList(array $items, string $basePPr, ?int $bulletNumId): string
    {
        $bulletPPr = $bulletNumId !== null
            ? '<w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="' . $bulletNumId . '"/></w:numPr>'
                . '<w:spacing w:after="0"/><w:ind w:left="360" w:hanging="360"/></w:pPr>'
            : '<w:pPr><w:spacing w:after="0"/><w:ind w:left="360" w:hanging="360"/></w:pPr>';

        $out = '';
        foreach ($items as $item) {
            $text = $this->escapeForWordXml($item);
            $prefix = $bulletNumId !== null ? '' : '• ';
            $out .= '<w:p>' . $bulletPPr
                . '<w:r><w:t xml:space="preserve">' . $prefix . $text . '</w:t></w:r>'
                . '</w:p>';
        }
        return $out;
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
        if ($zip->open($docxPath) !== true) {
            $this->logger->warning('Failed to open DOCX for checkbox SDT post-pass', ['path' => $docxPath]);

            return;
        }

        $xml = $zip->getFromName('word/document.xml');
        if ($xml === false) {
            $zip->close();

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
        //   2 = the literal <w:t …> opening tag (with its xml:space attr
        //       intact),
        //   3 = text before the marker,
        //   4 = state ("on"/"off"),
        //   5 = checked glyph,
        //   6 = unchecked glyph,
        //   7 = text after the marker.
        // The body uses [^<]* to stay inside a single text node (no nested
        // elements), which is exactly what PhpWord setValue produces.
        $pattern = '#<w:r\b[^>]*>(<w:rPr\b[^/]*?>.*?</w:rPr>|<w:rPr\b[^/]*?/>)?(<w:t\b[^>]*>)([^<]*?)\[\[SYNCB\|(on|off)\|([^|]+)\|([^\]]+)\]\]([^<]*?)</w:t></w:r>#s';

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
                    $tOpen = $match[2];
                    $before = $match[3];
                    $state = $match[4];
                    $checkedGlyph = $match[5];
                    $uncheckedGlyph = $match[6];
                    $after = $match[7];

                    // Force xml:space="preserve" on surviving text fragments
                    // so leading/trailing whitespace around the original
                    // placeholder is not collapsed by Word's XML parser.
                    $tOpenPreserved = $this->ensureXmlSpacePreserve($tOpen);

                    $out = '';
                    if ($before !== '') {
                        $out .= '<w:r>' . $rPr . $tOpenPreserved . $before . '</w:t></w:r>';
                    }
                    $out .= $this->buildCheckboxSdtXml($state === 'on', $checkedGlyph, $uncheckedGlyph);
                    if ($after !== '') {
                        $out .= '<w:r>' . $rPr . $tOpenPreserved . $after . '</w:t></w:r>';
                    }

                    return $out;
                },
                $newXml
            );

            if ($newXml === null) {
                $zip->close();

                return;
            }
            if ($passCount === 0) {
                break;
            }
        }

        if ($count === 0) {
            $zip->close();

            return;
        }

        // Word's content-control checkbox lives in the w14 namespace. Older
        // templates (Word 2007) may not declare it — inject it on the root
        // <w:document> element so Word/LibreOffice render the SDT correctly.
        if (!$hasW14) {
            $newXml = preg_replace(
                '#<w:document\b([^>]*?)>#',
                '<w:document$1 xmlns:w14="http://schemas.microsoft.com/office/word/2010/wordml">',
                $newXml,
                1
            ) ?? $newXml;
        }

        $zip->addFromString('word/document.xml', $newXml);
        $zip->close();
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
            . '<w:sdtPr>'
            .   '<w:rPr><w:rFonts w:ascii="MS Gothic" w:eastAsia="MS Gothic" w:hAnsi="MS Gothic" w:cs="MS Gothic" w:hint="eastAsia"/></w:rPr>'
            .   '<w:id w:val="' . $id . '"/>'
            .   '<w14:checkbox>'
            .     '<w14:checked w14:val="' . $checkedFlag . '"/>'
            .     '<w14:checkedState w14:val="' . $checkedHex . '" w14:font="MS Gothic"/>'
            .     '<w14:uncheckedState w14:val="' . $uncheckedHex . '" w14:font="MS Gothic"/>'
            .   '</w14:checkbox>'
            . '</w:sdtPr>'
            . '<w:sdtContent>'
            .   '<w:r><w:rPr><w:rFonts w:ascii="MS Gothic" w:eastAsia="MS Gothic" w:hAnsi="MS Gothic" w:cs="MS Gothic" w:hint="eastAsia"/></w:rPr><w:t xml:space="preserve">' . $glyphXml . '</w:t></w:r>'
            . '</w:sdtContent>'
            . '</w:sdt>';
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
        if ($glyph === '') {
            return $default;
        }
        $arr = function_exists('mb_str_split') ? mb_str_split($glyph, 1, 'UTF-8') : preg_split('//u', $glyph, -1, PREG_SPLIT_NO_EMPTY);
        $first = is_array($arr) && $arr !== [] ? $arr[0] : '';
        if ($first === '') {
            return $default;
        }
        $cp = mb_ord($first, 'UTF-8');
        if (!is_int($cp) || $cp <= 0) {
            return $default;
        }

        return strtoupper(str_pad(dechex($cp), 4, '0', STR_PAD_LEFT));
    }

    /**
     * @param array<string, mixed>                $arrays       group => rows[]
     * @param array<string, array<string, mixed>> $designerMap  varKey => designer
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
            $isTableLike = $type === 'table' || (is_array($arrays[$key] ?? null) && !empty($arrays[$key]) && is_array($arrays[$key][0] ?? null));
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

        // Any designer entry with at least one non-default key triggers a rewrite.
        $hasAnyConfig = false;
        foreach ($tableDesigners as $cfg) {
            if (!empty($cfg)) {
                $hasAnyConfig = true;
                break;
            }
        }
        if (!$hasAnyConfig) {
            return;
        }

        $zip = new \ZipArchive();
        if ($zip->open($docxPath) !== true) {
            $this->logger->warning('Failed to open DOCX for table layout pass', ['path' => $docxPath]);
            return;
        }

        $xml = $zip->getFromName('word/document.xml');
        if ($xml === false) {
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
        if (empty($merged)) {
            $zip->close();
            return;
        }

        $pattern = '#<w:tbl>(?:(?!</w:tbl>).)*?</w:tbl>#s';
        $newXml = preg_replace_callback(
            $pattern,
            function (array $m) use ($merged): string {
                $tbl = $m[0];
                $rowCount = substr_count($tbl, '</w:tr>');
                if ($rowCount < 2) {
                    // Don't rewrite single-row tables (e.g. the scalar-in-cell style).
                    return $tbl;
                }
                return $this->applyHelpersToTableXml($tbl, $merged);
            },
            $xml
        );

        if ($newXml !== null && $newXml !== $originalXml) {
            $zip->addFromString('word/document.xml', $newXml);
        }
        $zip->close();
    }

    /**
     * Rewrite a single <w:tbl> block, injecting the requested layout helpers.
     *
     * @param array<string, mixed> $cfg  Merged designer directives
     */
    private function applyHelpersToTableXml(string $tblXml, array $cfg): string
    {
        $preventSplit = !empty($cfg['prevent_row_break']);
        $repeatHeader = !empty($cfg['repeat_header']);
        $keepWithPrev = !empty($cfg['keep_with_prev']);

        if (!$preventSplit && !$repeatHeader && !$keepWithPrev) {
            return $tblXml;
        }

        $rowIndex = 0;
        $tblXml = preg_replace_callback(
            '#<w:tr\b[^>]*>(?:(?!</w:tr>).)*?</w:tr>#s',
            function (array $rm) use (&$rowIndex, $preventSplit, $repeatHeader): string {
                $tr = $rm[0];
                $isHeader = $rowIndex === 0;
                $rowIndex++;

                $directives = '';
                if ($preventSplit && !str_contains($tr, '<w:cantSplit/>')) {
                    $directives .= '<w:cantSplit/>';
                }
                if ($repeatHeader && $isHeader && !str_contains($tr, '<w:tblHeader/>')) {
                    $directives .= '<w:tblHeader/>';
                }
                if ($directives === '') {
                    return $tr;
                }

                if (preg_match('#<w:trPr\b[^>]*>#', $tr)) {
                    return preg_replace('#(<w:trPr\b[^>]*>)#', '$1' . $directives, $tr, 1) ?? $tr;
                }
                return preg_replace(
                    '#(<w:tr\b[^>]*>)#',
                    '$1<w:trPr>' . $directives . '</w:trPr>',
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
        if ($numberingXml === '') {
            return null;
        }

        $orderedAbstractIds = [];
        if (preg_match_all('#<w:abstractNum\b[^>]*?w:abstractNumId="(\d+)"[^>]*>(.*?)</w:abstractNum>#s', $numberingXml, $am)) {
            foreach ($am[1] as $idx => $absId) {
                $body = $am[2][$idx];
                if (preg_match('#<w:lvl\b[^>]*?w:ilvl="0"[^>]*>(.*?)</w:lvl>#s', $body, $lvl)) {
                    if (preg_match('#<w:numFmt\s+w:val="([^"]+)"\s*/?>#', $lvl[1], $fm)) {
                        $fmt = strtolower($fm[1]);
                        if ($fmt !== 'bullet' && $fmt !== 'none' && $fmt !== '') {
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
        if ($numberingXml === '') {
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
        $looseYearRange   = '~^\s*\d{4}\s*[\-–—]{1,2}\s*(?:heute|today|laufend|\d{4})\s*$~iu';
        $bulletPrefix     = '~^[\-*•·–—]\s+(.*)$~u';

        $blocks = [];
        foreach ($lines as $line) {
            $stripped = trim($line);

            if ($stripped === '') {
                $blocks[] = ['type' => 'spacer'];
                continue;
            }

            if (preg_match($dateRangePattern, $stripped) === 1 || preg_match($looseYearRange, $stripped) === 1) {
                $blocks[] = ['type' => 'date', 'text' => $stripped];
                continue;
            }

            if (preg_match($bulletPrefix, $stripped, $bm) === 1) {
                $blocks[] = ['type' => 'bullet', 'text' => trim($bm[1])];
                continue;
            }

            $blocks[] = ['type' => 'text', 'text' => $stripped];
        }

        // Collapse consecutive spacers
        $collapsed = [];
        $lastSpacer = false;
        foreach ($blocks as $b) {
            if ($b['type'] === 'spacer') {
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
        while (!empty($collapsed) && $collapsed[0]['type'] === 'spacer') {
            array_shift($collapsed);
        }
        while (!empty($collapsed) && end($collapsed)['type'] === 'spacer') {
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
    private function renderStationDetailsXml(string $details, string $basePPr, ?int $bulletNumId): string
    {
        $blocks = $this->parseStationDetails($details);
        if (empty($blocks)) {
            return '';
        }

        $bulletPPr = $bulletNumId !== null
            ? '<w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="' . $bulletNumId . '"/></w:numPr>'
                . '<w:spacing w:after="0"/><w:ind w:left="360" w:hanging="360"/></w:pPr>'
            : '<w:pPr><w:spacing w:after="0"/><w:ind w:left="360" w:hanging="360"/></w:pPr>';

        $out = '';
        foreach ($blocks as $b) {
            switch ($b['type']) {
                case 'spacer':
                    // Empty paragraph inheriting the cell default (vertical breath)
                    $out .= '<w:p>' . $basePPr . '</w:p>';
                    break;

                case 'date':
                    $text = $this->escapeForWordXml((string) ($b['text'] ?? ''));
                    $out .= '<w:p>' . $basePPr
                        . '<w:r><w:rPr><w:b/></w:rPr>'
                        . '<w:t xml:space="preserve">' . $text . '</w:t></w:r>'
                        . '</w:p>';
                    break;

                case 'bullet':
                    $text = $this->escapeForWordXml((string) ($b['text'] ?? ''));
                    $prefix = $bulletNumId !== null ? '' : '• ';
                    $out .= '<w:p>' . $bulletPPr
                        . '<w:r><w:t xml:space="preserve">' . $prefix . $text . '</w:t></w:r>'
                        . '</w:p>';
                    break;

                case 'text':
                default:
                    $text = $this->escapeForWordXml((string) ($b['text'] ?? ''));
                    $out .= '<w:p>' . $basePPr
                        . '<w:r><w:t xml:space="preserve">' . $text . '</w:t></w:r>'
                        . '</w:p>';
                    break;
            }
        }

        return $out;
    }
}
