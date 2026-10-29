<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\OpenApi\Config\CapabilitiesResponse;
use App\Attribute\OpenApi\Config\DefaultModelsResponse;
use App\Attribute\OpenApi\Config\FeatureStatusResponse;
use App\Attribute\OpenApi\Config\ModelCheckResponse;
use App\Attribute\OpenApi\Config\ModelListResponse;
use App\Attribute\OpenApi\Config\RuntimeConfigResponse;
use App\Entity\User;
use App\Service\Capability\CapabilityService;
use App\Service\Config\FeatureStatusReporter;
use App\Service\Config\Readiness\ServiceReadinessReporter;
use App\Service\Config\Runtime\RuntimeConfigAssembler;
use App\Service\Embedding\Exception\PremiumRequiredException;
use App\Service\LocalAi\LocalAiDownloadStatusService;
use App\Service\Memory\MemoryServiceStatus;
use App\Service\Model\Exception\DefaultModelRejectedException;
use App\Service\Model\Exception\InvalidModelSelectionException;
use App\Service\Model\ModelAvailabilityCheck;
use App\Service\Model\ModelDefaultsService;
use App\Service\Model\ModelListingService;
use App\Service\Model\RoutingModelsService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/config', name: 'api_config_')]
#[OA\Tag(name: 'Configuration')]
final class ConfigController extends AbstractController
{
    private const DEFAULTS_SCOPE_USER = 'user';
    private const DEFAULTS_SCOPE_INSTANCE = 'instance';

    public function __construct(
        private RuntimeConfigAssembler $runtimeConfig,
        private CapabilityService $capabilityService,
        private FeatureStatusReporter $featureStatusReporter,
        private MemoryServiceStatus $memoryServiceStatus,
        private LocalAiDownloadStatusService $localAiDownloadStatus,
        private ModelListingService $modelListing,
        private ModelDefaultsService $modelDefaults,
        private ModelAvailabilityCheck $modelAvailability,
        private RoutingModelsService $routingModels,
    ) {
    }

    /**
     * Quick Qdrant availability check (lightweight, no full status)
     * Frontend calls this asynchronously after app load to check if Qdrant is reachable.
     */
    #[Route('/memory-service/check', name: 'memory_service_check', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/config/memory-service/check',
        summary: 'Check Qdrant availability',
        description: 'Quick check if Qdrant vector database is reachable (called asynchronously)',
        tags: ['Configuration']
    )]
    #[OA\Response(
        response: 200,
        description: 'Qdrant availability status',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'available', type: 'boolean', example: true),
                new OA\Property(property: 'configured', type: 'boolean', example: true),
            ]
        )
    )]
    public function checkMemoryService(): JsonResponse
    {
        return $this->json($this->memoryServiceStatus->probe());
    }

    /**
     * Get public runtime configuration (no auth required)
     * Used by frontend to get reCAPTCHA site key and other public settings.
     */
    #[Route('/runtime', name: 'runtime_config', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/config/runtime',
        summary: 'Get public runtime configuration',
        description: 'Returns public configuration like reCAPTCHA site key, feature flags (no authentication required)',
        tags: ['Configuration']
    )]
    #[RuntimeConfigResponse]
    public function getRuntimeConfig(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        return $this->json($this->runtimeConfig->assemble($request, $user));
    }

    /**
     * Expose supported file formats, languages and summary options so consumer
     * integrations can discover capabilities dynamically instead of hardcoding
     * lists that drift when Synaplan adds support for new formats/languages.
     *
     * Public (no auth): the descriptor is static, non-sensitive capability
     * metadata sourced from the same constants the runtime enforces (#676).
     */
    #[Route('/capabilities', name: 'capabilities', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/config/capabilities',
        summary: 'Get supported file formats, languages and summary options',
        description: 'Returns the file formats accepted for upload (grouped by category), the languages available for '
            .'translation/summary output, the summary types/lengths/focus areas, and the maximum upload size. Consumer '
            .'integrations (e.g. synaplan-nextcloud, synaplan-opencloud) should read this instead of hardcoding lists. '
            .'No authentication required.',
        tags: ['Configuration']
    )]
    #[CapabilitiesResponse]
    public function getCapabilities(): JsonResponse
    {
        return $this->json($this->capabilityService->getCapabilities());
    }

    /**
     * Get all available models (all active models for all capabilities)
     * User can choose ANY model for ANY capability (cross-capability usage).
     */
    #[Route('/models', name: 'models_list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/config/models',
        summary: 'Get all available AI models',
        description: 'Returns active models grouped by capability (CHAT, IMAGE, SORT, etc.), restricted to models whose provider is available on this installation (API key / URL configured; Ollama models must be pulled). Admins can pass includeUnavailable=1 to also receive models of unconfigured providers, flagged via available/unavailableReason, e.g. to grey them out.',
        security: [['Bearer' => []]],
        tags: ['Configuration']
    )]
    #[OA\Parameter(
        name: 'includeUnavailable',
        description: 'Admin only (silently ignored otherwise): also return models whose provider is not configured, flagged with available=false.',
        in: 'query',
        required: false,
        schema: new OA\Schema(type: 'boolean', default: false)
    )]
    #[ModelListResponse]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    public function getModels(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        // Unavailable models are hidden from regular users — they could not
        // be used anyway. Admin views request them to grey them out.
        $isAdmin = $this->isGranted('ROLE_ADMIN');

        return $this->json(['success' => true] + $this->modelListing->forUser(
            $user,
            includeUnavailable: $request->query->getBoolean('includeUnavailable') && $isAdmin,
            applyGroupPolicy: !$isAdmin,
        ));
    }

    /**
     * Get current default model configuration for user.
     */
    #[Route('/models/defaults', name: 'models_defaults', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/config/models/defaults',
        summary: 'Get default model configuration',
        description: 'Returns the currently configured default model IDs per capability for the authenticated user. Falls back to global defaults when no user-specific setting exists. VECTORIZE always returns the system-wide default. Admins may pass `scope=instance` to read the system-wide defaults (ownerId=0) that guests and every member without their own choice use.',
        security: [['Bearer' => []]],
        tags: ['Configuration'],
        parameters: [
            new OA\Parameter(
                name: 'scope',
                in: 'query',
                required: false,
                description: '`user` (default): the defaults that apply to the signed-in user. `instance` (admin only): the system-wide defaults, as guests and members without their own choice get them. With IAM group policies on, a group default still wins for that group\'s members. Any other value is rejected with 400.',
                schema: new OA\Schema(type: 'string', enum: ['user', 'instance'], default: 'user')
            ),
        ]
    )]
    #[DefaultModelsResponse]
    #[OA\Response(
        response: 400,
        description: 'Unknown `scope` value',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'error', type: 'string', example: 'Unknown scope "instnace". Use "user" or "instance".'),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(
        response: 403,
        description: '`scope=instance` used without ROLE_ADMIN',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'error', type: 'string', example: 'Admin access required for instance defaults'),
            ]
        )
    )]
    public function getDefaultModels(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $scope = $request->query->getString('scope', self::DEFAULTS_SCOPE_USER);
        if (!in_array($scope, [self::DEFAULTS_SCOPE_USER, self::DEFAULTS_SCOPE_INSTANCE], true)) {
            return $this->json(
                ['error' => sprintf('Unknown scope "%s". Use "user" or "instance".', $scope)],
                Response::HTTP_BAD_REQUEST
            );
        }

        if (self::DEFAULTS_SCOPE_INSTANCE === $scope) {
            if (!$this->isGranted('ROLE_ADMIN')) {
                return $this->json(['error' => 'Admin access required for instance defaults'], Response::HTTP_FORBIDDEN);
            }

            return $this->json(['success' => true] + $this->modelDefaults->instanceDefaults());
        }

        return $this->json(['success' => true] + $this->modelDefaults->userDefaults((int) $user->getId()));
    }

    /**
     * Save default model configuration.
     *
     * By default saves user-specific defaults (ownerId = current user).
     * With `global: true` (admin-only), saves system-wide defaults (ownerId = 0).
     */
    #[Route('/models/defaults', name: 'models_defaults_save', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/config/models/defaults',
        summary: 'Save default model configuration',
        description: 'Saves per-capability default model IDs for the authenticated user. Admins may pass `global: true` to override system-wide defaults (ownerId=0), which act as the fallback for all users. VECTORIZE requires a premium subscription for non-admins.',
        security: [['Bearer' => []]],
        tags: ['Configuration'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['defaults'],
                properties: [
                    new OA\Property(
                        property: 'defaults',
                        type: 'object',
                        description: 'Map of capability name to model ID',
                        example: ['CHAT' => 53, 'SORT' => 12, 'ANALYZE' => 53]
                    ),
                    new OA\Property(
                        property: 'global',
                        type: 'boolean',
                        description: 'Admin-only: when true, saves as system-wide defaults that apply to all users as fallback',
                        example: false
                    ),
                ]
            )
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Defaults saved successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Default models saved successfully'),
                new OA\Property(
                    property: 'skipped',
                    type: 'object',
                    description: 'Capabilities whose model ID was rejected because the model no longer exists or is inactive',
                    example: ['TEXT2PIC' => 99],
                    nullable: true
                ),
                new OA\Property(
                    property: 'defaults',
                    type: 'object',
                    description: 'Per-user saves only: the defaults that apply after the save, as GET /api/v1/config/models/defaults reports them',
                    additionalProperties: new OA\AdditionalProperties(type: 'integer', nullable: true),
                    example: ['CHAT' => 249, 'SORT' => 12]
                ),
                new OA\Property(
                    property: 'replaced',
                    type: 'object',
                    description: 'Per-user saves only: requested capabilities whose saved model does not apply (for example its provider has no working key), mapped to the model that applies instead, or null when none does',
                    additionalProperties: new OA\AdditionalProperties(type: 'integer', nullable: true),
                    example: ['CHAT' => 249]
                ),
            ]
        )
    )]
    #[OA\Response(response: 400, description: 'Invalid request body')]
    #[OA\Response(
        response: 422,
        description: 'The model is outside the member allow-list',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'error', type: 'string', example: 'That model is not allowed for your account.'),
                new OA\Property(property: 'code', type: 'string', example: 'iam.modelNotAllowed'),
                new OA\Property(property: 'capability', type: 'string', example: 'CHAT'),
            ]
        )
    )]
    #[OA\Response(
        response: 409,
        description: 'A locked default cannot be overridden',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'error', type: 'string', example: 'This setting is set by your administrator'),
                new OA\Property(property: 'code', type: 'string', example: 'iam.settingLocked'),
                new OA\Property(property: 'capability', type: 'string', example: 'CHAT'),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(
        response: 403,
        description: 'Forbidden. Two distinct cases: (1) `global: true` used without ROLE_ADMIN, (2) VECTORIZE change attempted without premium subscription.',
        content: new OA\JsonContent(
            oneOf: [
                new OA\Schema(
                    title: 'AdminRequired',
                    description: 'Returned when `global: true` is passed without ROLE_ADMIN',
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'Admin access required for global defaults'),
                    ]
                ),
                new OA\Schema(
                    title: 'PremiumRequired',
                    description: 'Returned when a non-admin user attempts to change the VECTORIZE model',
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'requires_premium'),
                        new OA\Property(property: 'capability', type: 'string', example: 'VECTORIZE'),
                        new OA\Property(property: 'message', type: 'string', example: 'Switching the embedding model requires a premium subscription'),
                        new OA\Property(property: 'currentLevel', type: 'string', example: 'FREE'),
                    ]
                ),
            ]
        )
    )]
    public function saveDefaultModels(
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        if (!isset($data['defaults']) || !is_array($data['defaults'])) {
            return $this->json(['error' => 'Invalid data'], Response::HTTP_BAD_REQUEST);
        }

        $global = !empty($data['global']);
        if ($global && !$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['error' => 'Admin access required for global defaults'], Response::HTTP_FORBIDDEN);
        }

        try {
            $saved = $this->modelDefaults->save($user, $data['defaults'], $global);
        } catch (PremiumRequiredException $e) {
            return $this->json([
                'error' => 'requires_premium',
                'capability' => 'VECTORIZE',
                'message' => $e->getMessage(),
                'currentLevel' => $e->currentLevel,
            ], Response::HTTP_FORBIDDEN);
        } catch (DefaultModelRejectedException $e) {
            return $this->json([
                'error' => $e->getMessage(),
                'code' => $e->reason,
                'capability' => $e->capability,
            ], $e->isLocked() ? Response::HTTP_CONFLICT : Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['success' => true] + $saved);
    }

    /**
     * Replace the calling user's model configuration with the
     * code-recommended defaults from DefaultModelConfigSeeder.
     *
     * Removes stale per-user overrides and writes fresh ones that match the
     * catalog-recommended models, skipping any whose provider is not usable
     * here. Other users and the global (ownerId=0) row are unaffected.
     */
    #[Route('/models/defaults/reset', name: 'models_defaults_reset', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/config/models/defaults/reset',
        summary: 'Apply recommended model defaults to own configuration',
        description: 'Replaces all per-user DEFAULTMODEL overrides with the recommended defaults (from DefaultModelConfigSeeder) that are usable on this installation: a recommended model whose provider has no key is replaced by the first usable model for that capability, and nothing is written when no provider is usable at all. Does NOT modify global defaults — other users are unaffected. Returns the newly written defaults.',
        security: [['Bearer' => []]],
        tags: ['Configuration']
    )]
    #[OA\Response(
        response: 200,
        description: 'Defaults applied successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Applied 11 recommended defaults (removed 3 previous overrides)'),
                new OA\Property(
                    property: 'defaults',
                    type: 'object',
                    description: 'New default model IDs per capability',
                    example: ['CHAT' => 161, 'SORT' => 76]
                ),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    public function resetDefaultModels(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json(['success' => true] + $this->modelDefaults->resetToRecommended((int) $user->getId()));
    }

    /**
     * Get the user's planner model selection (DEFAULTMODEL.PLAN).
     *
     * The planner (TaskPlanner) breaks complex requests into a multi-task DAG.
     * Its model resolves to the per-user DEFAULTMODEL.PLAN override, then the
     * global one, then falls back to the Sorting model (DEFAULTMODEL.SORT). This
     * endpoint exposes that selection so it is configurable in the UI instead of
     * only via direct SQL (#1143).
     */
    #[Route('/routing/planner-model', name: 'routing_planner_model_get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/config/routing/planner-model',
        summary: 'Get the planner and research model selections',
        description: 'Returns the resolved planner model id (DEFAULTMODEL.PLAN, user override then global), the Sorting model id it falls back to, whether Research is enabled, and the optional research reading (DEFAULTMODEL.READ) and checking (DEFAULTMODEL.VERIFY) overrides.',
        security: [['Bearer' => []]],
        tags: ['Configuration']
    )]
    #[OA\Response(
        response: 200,
        description: 'Planner and research model selections',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'modelId', type: 'integer', nullable: true, description: 'Selected planner model id, or null when none is configured (falls back to the Sorting model)', example: 12),
                new OA\Property(property: 'fallbackModelId', type: 'integer', nullable: true, description: 'Sorting model id used when no planner model is set', example: 7),
                new OA\Property(property: 'researchEnabled', type: 'boolean', description: 'Whether Research is turned on for this installation', example: true),
                new OA\Property(property: 'readingModelId', type: 'integer', nullable: true, description: 'Selected research source-reading model id (DEFAULTMODEL.READ), or null for automatic fallback', example: 12),
                new OA\Property(property: 'checkingModelId', type: 'integer', nullable: true, description: 'Selected research fact-checking model id (DEFAULTMODEL.VERIFY), or null for automatic fallback', example: 53),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    public function getPlannerModel(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json(['success' => true] + $this->routingModels->plannerSelection((int) $user->getId()));
    }

    /**
     * Save (or clear) the user's planner and/or research model overrides
     * (DEFAULTMODEL.PLAN, DEFAULTMODEL.READ, DEFAULTMODEL.VERIFY).
     *
     * A null value removes the per-user override so the slot reverts to
     * its automatic fallback.
     */
    #[Route('/routing/planner-model', name: 'routing_planner_model_save', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/config/routing/planner-model',
        summary: 'Save the planner or research model selections',
        description: 'Writes per-user DEFAULTMODEL.PLAN (`modelId`), DEFAULTMODEL.READ (`readingModelId`), or DEFAULTMODEL.VERIFY (`checkingModelId`) overrides. Pass `null` for a field to clear that override.',
        security: [['Bearer' => []]],
        tags: ['Configuration'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'modelId', type: 'integer', nullable: true, description: 'Planner model id, or null to clear the override', example: 12),
                    new OA\Property(property: 'readingModelId', type: 'integer', nullable: true, description: 'Research reading model id, or null to clear the override', example: 12),
                    new OA\Property(property: 'checkingModelId', type: 'integer', nullable: true, description: 'Research fact-checking model id, or null to clear the override', example: 53),
                ]
            )
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Model selections saved',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'modelId', type: 'integer', nullable: true, example: 12),
                new OA\Property(property: 'readingModelId', type: 'integer', nullable: true, example: 12),
                new OA\Property(property: 'checkingModelId', type: 'integer', nullable: true, example: 53),
            ]
        )
    )]
    #[OA\Response(response: 400, description: 'Invalid request body or model not available')]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    public function savePlannerModel(
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || ([] === array_intersect(array_keys($data), ['modelId', 'readingModelId', 'checkingModelId']))) {
            return $this->json(['error' => 'Invalid data'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $saved = $this->routingModels->saveAgentModels((int) $user->getId(), $data);
        } catch (InvalidModelSelectionException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['success' => true] + $saved);
    }

    /**
     * Get the platform-wide summary model (DEFAULTMODEL.SUMMARIZE).
     *
     * The rolling conversation summary condenses the older turns of a long chat
     * on every affected turn, so it wants a small, fast model (the seeded
     * default is GPT-OSS-120B on Groq) rather than the answering model. Unlike
     * the planner selection this is deliberately global: the summary is part of
     * the server-side pipeline every user's chat runs through, and it also
     * backs widget titles and document summaries.
     */
    #[Route('/routing/summary-model', name: 'routing_summary_model_get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/config/routing/summary-model',
        summary: 'Get the platform summary model',
        description: 'Admin only. Returns the global DEFAULTMODEL.SUMMARIZE selection and the Sorting model it falls back to when none is set.',
        security: [['Bearer' => []]],
        tags: ['Configuration']
    )]
    #[OA\Response(
        response: 200,
        description: 'Summary model selection',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'modelId', type: 'integer', nullable: true, description: 'Selected summary model id, or null when none is configured (falls back to the Sorting model)', example: 300),
                new OA\Property(property: 'fallbackModelId', type: 'integer', nullable: true, description: 'Sorting model id used when no summary model is set', example: 7),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    public function getSummaryModel(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        return $this->json(['success' => true] + $this->routingModels->summarySelection());
    }

    /**
     * Save (or clear) the platform-wide summary model (DEFAULTMODEL.SUMMARIZE).
     *
     * A null `modelId` removes the row so summarization reverts to the Sorting
     * model.
     */
    #[Route('/routing/summary-model', name: 'routing_summary_model_save', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/config/routing/summary-model',
        summary: 'Save the platform summary model',
        description: 'Admin only. Writes the global DEFAULTMODEL.SUMMARIZE row. Pass `modelId: null` to clear it and fall back to the Sorting model.',
        security: [['Bearer' => []]],
        tags: ['Configuration'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['modelId'],
                properties: [
                    new OA\Property(property: 'modelId', type: 'integer', nullable: true, description: 'Summary model id, or null to clear the selection', example: 300),
                ]
            )
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Summary model saved',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'modelId', type: 'integer', nullable: true, example: 300),
            ]
        )
    )]
    #[OA\Response(response: 400, description: 'Invalid request body or model not available')]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    public function saveSummaryModel(
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !array_key_exists('modelId', $data)) {
            return $this->json(['error' => 'Invalid data'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $modelId = $this->routingModels->saveSummary($data['modelId']);
        } catch (InvalidModelSelectionException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['success' => true, 'modelId' => $modelId]);
    }

    /**
     * Check if a model is available/ready to use.
     *
     * @param int $modelId Model ID to check
     *
     * @return JsonResponse {available: bool, provider_type: string, message?: string, install_command?: string}
     */
    #[Route('/models/{modelId}/check', name: 'models_check', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/config/models/{modelId}/check',
        summary: 'Check model availability',
        description: 'Checks whether a specific model is ready to use. For local Ollama models it verifies the Ollama server is running. For external providers it validates that the required API keys or environment variables are configured.',
        security: [['Bearer' => []]],
        tags: ['Configuration']
    )]
    #[OA\Parameter(
        name: 'modelId',
        in: 'path',
        required: true,
        description: 'Model database ID',
        schema: new OA\Schema(type: 'integer', example: 53)
    )]
    #[ModelCheckResponse]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(
        response: 404,
        description: 'Model not found',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'available', type: 'boolean', example: false),
                new OA\Property(property: 'error', type: 'string', example: 'Model not found'),
            ]
        )
    )]
    public function checkModelAvailability(
        int $modelId,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $result = $this->modelAvailability->check($modelId);
        if (null === $result) {
            return $this->json([
                'available' => false,
                'error' => 'Model not found',
            ], Response::HTTP_NOT_FOUND);
        }

        return $this->json($result);
    }

    /**
     * Local AI (Ollama) model-download progress written by the backend entrypoint.
     * Authenticated users can poll this while AUTO_DOWNLOAD_MODELS pulls models.
     */
    #[Route('/local-ai/status', name: 'local_ai_download_status', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/config/local-ai/status',
        summary: 'Get local AI model download status',
        description: 'Returns progress of background Ollama model pulls started by the container entrypoint. When no download is running the status is idle/ready.',
        security: [['Bearer' => []]],
        tags: ['Configuration']
    )]
    #[OA\Response(
        response: 200,
        description: 'Local AI download status',
        content: new OA\JsonContent(
            required: ['status', 'currentModel', 'percent', 'message', 'models', 'updatedAt'],
            properties: [
                new OA\Property(property: 'status', type: 'string', enum: ['idle', 'waiting', 'downloading', 'ready', 'error'], example: 'downloading'),
                new OA\Property(property: 'currentModel', type: 'string', nullable: true, example: 'bge-m3'),
                new OA\Property(property: 'percent', type: 'integer', nullable: true, example: 43),
                new OA\Property(property: 'message', type: 'string', nullable: true, example: 'Downloading bge-m3'),
                new OA\Property(
                    property: 'models',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'name', type: 'string', example: 'bge-m3'),
                            new OA\Property(property: 'state', type: 'string', example: 'downloading'),
                            new OA\Property(property: 'percent', type: 'integer', nullable: true, example: 43),
                        ]
                    )
                ),
                new OA\Property(property: 'updatedAt', type: 'string', nullable: true, example: '2026-07-30T10:00:00Z'),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    public function getLocalAiDownloadStatus(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json($this->localAiDownloadStatus->getStatus());
    }

    /**
     * Get status of all features and services (Web Search, AI Providers, Processing Services, etc.)
     * Admin only (available in production builds).
     */
    #[Route('/features', name: 'features_status', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/config/features',
        summary: 'Get feature and service status (admin)',
        description: 'Returns the live status of all configured features, AI providers, and infrastructure services. **Admin only** (available in production).',
        security: [['Bearer' => []]],
        tags: ['Configuration']
    )]
    #[FeatureStatusResponse]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    public function getFeaturesStatus(#[CurrentUser] ?User $user, ServiceReadinessReporter $serviceReadiness): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        return $this->json($this->featureStatusReporter->build($user) + ['services' => $serviceReadiness->rows()]);
    }
}
