<?php

declare(strict_types=1);

namespace App\Controller;

use App\Bundle\BundleConfig;
use App\Bundle\BundleEnvelopeException;
use App\Bundle\BundleEnvelopeValidator;
use App\Bundle\BundleExporter;
use App\Bundle\BundleImporter;
use App\Bundle\BundleRateLimiter;
use App\Bundle\BundleRequestParser;
use App\Bundle\BundleScope;
use App\Bundle\BundleSectionRegistry;
use App\Bundle\BundleTooLargeException;
use App\Bundle\ImportOptions;
use App\DTO\Bundle\BundleDocument;
use App\DTO\Bundle\BundleError;
use App\DTO\Bundle\BundleImportRequest;
use App\Entity\User;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * `synaplan-bundle.v1` export / preview / import. Every route 404s while
 * `BUNDLE.ENABLED` is off; the `instance` scope is admin-only.
 */
#[Route('/api/v1/bundle', name: 'api_bundle_')]
#[OA\Tag(name: 'Bundle')]
final class BundleController extends AbstractController
{
    private const DEFAULT_SCOPE = 'user';

    public function __construct(
        private readonly BundleConfig $config,
        private readonly BundleSectionRegistry $sections,
        private readonly BundleExporter $exporter,
        private readonly BundleImporter $importer,
        private readonly BundleRateLimiter $rateLimiter,
        private readonly BundleRequestParser $parser,
    ) {
    }

    #[Route('/sections', name: 'sections', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/bundle/sections',
        summary: 'List exportable bundle sections for the current user',
        description: 'Returns 404 when BUNDLE.ENABLED is off. The `instance` scope is admin-only.',
        tags: ['Bundle'],
        parameters: [
            new OA\Parameter(name: 'scope', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['user', 'instance'], default: 'user')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Registered sections',
                content: new OA\JsonContent(
                    required: ['success', 'sections'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'sections',
                            type: 'array',
                            items: new OA\Items(
                                required: ['kind', 'version', 'dependsOn', 'itemCount'],
                                properties: [
                                    new OA\Property(property: 'kind', type: 'string', example: 'agents'),
                                    new OA\Property(property: 'version', type: 'integer', example: 1),
                                    new OA\Property(property: 'dependsOn', type: 'array', items: new OA\Items(type: 'string'), example: ['prompts']),
                                    new OA\Property(property: 'itemCount', type: 'integer', example: 2),
                                ]
                            )
                        ),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Unknown scope', content: new OA\JsonContent(ref: new Model(type: BundleError::class))),
            new OA\Response(response: 401, description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: BundleError::class))),
            new OA\Response(response: 404, description: 'Feature disabled', content: new OA\JsonContent(ref: new Model(type: BundleError::class))),
        ]
    )]
    public function sections(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);
        $scope = $this->scopeFromPayload(['scope' => $request->query->get('scope', self::DEFAULT_SCOPE)], $user);
        if ($scope instanceof JsonResponse) {
            return $scope;
        }
        $userId = (int) $user->getId();
        $rows = [];
        foreach ($this->sections->available($userId, $scope) as $section) {
            $rows[] = [
                'kind' => $section->kind(),
                'version' => $section->version(),
                'dependsOn' => $section->dependsOn(),
                'itemCount' => count($section->export($userId, $scope)),
            ];
        }

        return $this->json(['success' => true, 'sections' => $rows]);
    }

    #[Route('/export', name: 'export', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/bundle/export',
        summary: 'Export a synaplan-bundle.v1 document',
        description: 'Streams the bundle as a JSON download. Secrets, ids of other owners and share rows are never included.',
        tags: ['Bundle'],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'kinds', type: 'array', items: new OA\Items(type: 'string'), example: ['agents', 'prompts'], description: 'Section kinds to export; empty = all available'),
                    new OA\Property(property: 'scope', type: 'string', enum: ['user', 'instance'], example: 'user'),
                    new OA\Property(property: 'include', type: 'object', additionalProperties: true, description: 'Per-section include filters (section-defined)'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Bundle document (attachment)',
                content: new OA\JsonContent(ref: new Model(type: BundleDocument::class))
            ),
            new OA\Response(response: 400, description: 'Unknown scope', content: new OA\JsonContent(ref: new Model(type: BundleError::class))),
            new OA\Response(response: 401, description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: BundleError::class))),
            new OA\Response(response: 404, description: 'Feature disabled', content: new OA\JsonContent(ref: new Model(type: BundleError::class))),
            new OA\Response(response: 429, description: 'Rate limit exceeded', content: new OA\JsonContent(ref: new Model(type: BundleError::class))),
        ]
    )]
    public function export(Request $request, #[CurrentUser] ?User $user): Response
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);
        $userId = (int) $user->getId();
        if (!$this->rateLimiter->consume($userId, BundleRateLimiter::ACTION_EXPORT)) {
            return $this->json(['error' => 'Too many exports. Try again later.'], Response::HTTP_TOO_MANY_REQUESTS);
        }
        $body = $this->jsonBody($request);
        $scope = $this->scopeFromPayload($body, $user);
        if ($scope instanceof JsonResponse) {
            return $scope;
        }
        $kinds = array_values(array_filter(
            is_array($body['kinds'] ?? null) ? $body['kinds'] : [],
            static fn (mixed $kind): bool => is_string($kind) && '' !== $kind,
        ));
        $include = is_array($body['include'] ?? null) ? $body['include'] : [];
        $document = $this->exporter->export($userId, $scope, $kinds, $include);
        $payload = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return new Response($payload, Response::HTTP_OK, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="synaplan-bundle.json"',
        ]);
    }

    #[Route('/preview', name: 'preview', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/bundle/preview',
        summary: 'Preview a synaplan-bundle.v1 import without writing',
        description: 'Accepts either the bundle document itself or `{ "bundle": <document> }`. Returns a checklist per section (`needs_model`, `needs_mailbox`, …) and never writes.',
        tags: ['Bundle'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: BundleImportRequest::class))
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Checklist',
                content: new OA\JsonContent(
                    required: ['success', 'envelope', 'fromOtherInstance', 'sourceInstance', 'sections'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'envelope',
                            type: 'object',
                            required: ['schema', 'createdAt', 'sourceVersion', 'scope'],
                            properties: [
                                new OA\Property(property: 'schema', type: 'string', example: BundleEnvelopeValidator::SCHEMA),
                                new OA\Property(property: 'createdAt', type: 'string', example: '2026-09-09T12:00:00+00:00'),
                                new OA\Property(property: 'sourceVersion', type: 'string', example: '4.7.2'),
                                new OA\Property(property: 'scope', type: 'string', enum: ['user', 'instance']),
                            ]
                        ),
                        new OA\Property(property: 'fromOtherInstance', type: 'boolean', example: true),
                        new OA\Property(property: 'sourceInstance', type: 'string', example: 'sha256:…'),
                        new OA\Property(
                            property: 'sections',
                            type: 'array',
                            items: new OA\Items(
                                required: ['kind', 'itemCount', 'items'],
                                properties: [
                                    new OA\Property(property: 'kind', type: 'string', example: 'agents'),
                                    new OA\Property(property: 'itemCount', type: 'integer', example: 3),
                                    new OA\Property(
                                        property: 'items',
                                        type: 'array',
                                        items: new OA\Items(
                                            required: ['code', 'itemKey', 'detail'],
                                            properties: [
                                                new OA\Property(property: 'code', type: 'string', example: 'needs_model'),
                                                new OA\Property(property: 'itemKey', type: 'string', example: 'contract-review'),
                                                new OA\Property(property: 'detail', type: 'string', nullable: true, example: 'anthropic:claude-sonnet:chat'),
                                            ]
                                        )
                                    ),
                                ]
                            )
                        ),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Invalid bundle', content: new OA\JsonContent(ref: new Model(type: BundleError::class))),
            new OA\Response(response: 401, description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: BundleError::class))),
            new OA\Response(response: 404, description: 'Feature disabled', content: new OA\JsonContent(ref: new Model(type: BundleError::class))),
            new OA\Response(response: 413, description: 'Bundle too large', content: new OA\JsonContent(ref: new Model(type: BundleError::class))),
        ]
    )]
    public function preview(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);
        $parsed = $this->parseBundleRequest($request);
        if ($parsed instanceof JsonResponse) {
            return $parsed;
        }
        try {
            $preview = $this->importer->preview($parsed['bundle'], (int) $user->getId(), $this->exporter->instanceFingerprint());
        } catch (BundleEnvelopeException $e) {
            return $this->json(['error' => $e->getMessage(), 'path' => $e->path], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['success' => true] + $preview);
    }

    #[Route('/import', name: 'import', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/bundle/import',
        summary: 'Import a synaplan-bundle.v1 document as drafts',
        description: 'Creates drafts owned by the caller. Never creates shares, credentials or file rows. Each section is applied in its own transaction; a failing section is reported and does not roll back the others.',
        tags: ['Bundle'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: BundleImportRequest::class))
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Import result',
                content: new OA\JsonContent(
                    required: ['success', 'createsDrafts', 'results'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'createsDrafts', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'results',
                            type: 'array',
                            items: new OA\Items(
                                required: ['kind', 'created', 'skipped', 'failed'],
                                properties: [
                                    new OA\Property(property: 'kind', type: 'string', example: 'agents'),
                                    new OA\Property(property: 'created', type: 'array', items: new OA\Items(type: 'string'), example: ['contract-review']),
                                    new OA\Property(property: 'skipped', type: 'array', items: new OA\Items(type: 'string'), example: []),
                                    new OA\Property(
                                        property: 'failed',
                                        type: 'array',
                                        items: new OA\Items(
                                            required: ['key', 'reason'],
                                            properties: [
                                                new OA\Property(property: 'key', type: 'string', example: 'weekly-digest'),
                                                new OA\Property(property: 'reason', type: 'string', example: 'definition.models.chat: unknown model key'),
                                            ]
                                        )
                                    ),
                                ]
                            )
                        ),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Invalid bundle or options', content: new OA\JsonContent(ref: new Model(type: BundleError::class))),
            new OA\Response(response: 401, description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: BundleError::class))),
            new OA\Response(response: 404, description: 'Feature disabled', content: new OA\JsonContent(ref: new Model(type: BundleError::class))),
            new OA\Response(response: 413, description: 'Bundle too large', content: new OA\JsonContent(ref: new Model(type: BundleError::class))),
            new OA\Response(response: 429, description: 'Rate limit exceeded', content: new OA\JsonContent(ref: new Model(type: BundleError::class))),
        ]
    )]
    public function import(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);
        $userId = (int) $user->getId();
        if (!$this->rateLimiter->consume($userId, BundleRateLimiter::ACTION_IMPORT)) {
            return $this->json(['error' => 'Too many imports. Try again later.'], Response::HTTP_TOO_MANY_REQUESTS);
        }
        $parsed = $this->parseBundleRequest($request);
        if ($parsed instanceof JsonResponse) {
            return $parsed;
        }
        try {
            $results = $this->importer->apply($parsed['bundle'], $userId, $parsed['options']);
        } catch (BundleEnvelopeException $e) {
            return $this->json(['error' => $e->getMessage(), 'path' => $e->path], Response::HTTP_BAD_REQUEST);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['success' => true, 'createsDrafts' => true, 'results' => $results]);
    }

    private function guard(?User $user): ?JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->config->isEnabled((int) $user->getId())) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array{bundle: string, options: ImportOptions}|JsonResponse
     */
    private function parseBundleRequest(Request $request): array|JsonResponse
    {
        try {
            return $this->parser->parse($request->getContent());
        } catch (BundleTooLargeException $e) {
            return $this->json(
                ['error' => sprintf('This file is larger than %d MB.', intdiv($e->maxBytes, 1024 * 1024))],
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            );
        } catch (BundleEnvelopeException) {
            return $this->json(['error' => 'Send a synaplan-bundle.v1 document.'], Response::HTTP_BAD_REQUEST);
        } catch (\InvalidArgumentException $e) {
            // Bad import option, e.g. an unknown conflict strategy.
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function scopeFromPayload(array $payload, User $user): BundleScope|JsonResponse
    {
        $raw = is_string($payload['scope'] ?? null) ? $payload['scope'] : self::DEFAULT_SCOPE;
        $scope = BundleScope::tryFrom($raw);
        if (null === $scope) {
            return $this->json(['error' => 'scope must be user or instance'], Response::HTTP_BAD_REQUEST);
        }
        if (BundleScope::Instance === $scope && !$user->isAdmin()) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        return $scope;
    }
}
