<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\AdminSignupCode;
use App\DTO\AdminSignupCodeUse;
use App\Entity\User;
use App\Service\Auth\SignupCodeAdminException;
use App\Service\Auth\SignupCodeAdminService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin › People › Sign-up codes: issue, change, revoke and inspect the codes
 * that let a person sign up without paying (admission sign-up, AS3).
 */
#[Route('/api/v1/admin/signup-codes', name: 'admin_signup_codes_')]
#[OA\Tag(name: 'Admin Sign-up Codes')]
#[IsGranted('ROLE_ADMIN')]
final class AdminSignupCodeController extends AbstractController
{
    public function __construct(
        private readonly SignupCodeAdminService $service,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/admin/signup-codes',
        operationId: 'listAdminSignupCodes',
        summary: 'List every sign-up code, newest first',
        tags: ['Admin Sign-up Codes'],
        responses: [
            new OA\Response(response: 200, description: 'Codes', content: new OA\JsonContent(
                required: ['success', 'codes'],
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'codes', type: 'array', items: new OA\Items(ref: new Model(type: AdminSignupCode::class))),
                ]
            )),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 403, description: 'Not an administrator'),
        ]
    )]
    public function list(): JsonResponse
    {
        return $this->json(['success' => true, 'codes' => $this->service->list()]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/admin/signup-codes',
        operationId: 'createAdminSignupCode',
        summary: 'Issue a sign-up code; the code is in this response only',
        tags: ['Admin Sign-up Codes'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['label', 'level', 'validity'],
            properties: [
                new OA\Property(property: 'label', type: 'string', maxLength: 120, example: 'Pilot customers'),
                new OA\Property(property: 'level', type: 'string', enum: ['NEW', 'PRO', 'TEAM', 'BUSINESS'], example: 'TEAM'),
                new OA\Property(property: 'validity', type: 'string', enum: ['forever', 'week', 'fiveMonths'], example: 'fiveMonths'),
                new OA\Property(property: 'maxUses', type: 'integer', minimum: 1, nullable: true, description: 'Null: no limit; absent: 1', example: 5),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(
                required: ['success', 'code', 'signupCode'],
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'code', type: 'string', description: 'The plain code in groups of four; not retrievable again', example: 'R7KQ-M2XH-9TZA'),
                    new OA\Property(property: 'signupCode', ref: new Model(type: AdminSignupCode::class)),
                ]
            )),
            new OA\Response(response: 400, description: 'Invalid label, level, validity or limit'),
            new OA\Response(response: 403, description: 'Not an administrator'),
        ]
    )]
    public function create(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        try {
            $body = $this->body($request);
            $created = $this->service->create(
                $user,
                is_string($body['label'] ?? null) ? $body['label'] : '',
                is_string($body['level'] ?? null) ? $body['level'] : '',
                is_string($body['validity'] ?? null) ? $body['validity'] : '',
                array_key_exists('maxUses', $body) ? $body['maxUses'] : SignupCodeAdminService::DEFAULT_MAX_USES,
                $request->getClientIp() ?? '',
            );
        } catch (SignupCodeAdminException $e) {
            return $this->failure($e);
        }

        return $this->json(['success' => true] + $created, Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[OA\Patch(
        path: '/api/v1/admin/signup-codes/{id}',
        operationId: 'updateAdminSignupCode',
        summary: 'Change the label, limit, expiry or state of a sign-up code',
        tags: ['Admin Sign-up Codes'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            description: 'Only the keys sent are changed',
            properties: [
                new OA\Property(property: 'label', type: 'string', maxLength: 120),
                new OA\Property(property: 'maxUses', type: 'integer', minimum: 1, nullable: true, description: 'Null: no limit'),
                new OA\Property(property: 'expiresAt', type: 'integer', nullable: true, description: 'Unix time; null: never'),
                new OA\Property(property: 'active', type: 'boolean', description: 'False revokes, true restores'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(
                required: ['success', 'signupCode'],
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'signupCode', ref: new Model(type: AdminSignupCode::class)),
                ]
            )),
            new OA\Response(response: 400, description: 'Invalid value or a body that is not a JSON object'),
            new OA\Response(response: 403, description: 'Not an administrator'),
            new OA\Response(response: 404, description: 'Unknown code'),
        ]
    )]
    public function update(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        try {
            $code = $this->service->update($user, $id, $this->body($request), $request->getClientIp() ?? '');
        } catch (SignupCodeAdminException $e) {
            return $this->failure($e);
        }

        return $this->json(['success' => true, 'signupCode' => $code]);
    }

    #[Route('/{id}', name: 'revoke', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[OA\Delete(
        path: '/api/v1/admin/signup-codes/{id}',
        operationId: 'revokeAdminSignupCode',
        summary: 'Revoke a sign-up code; the code and its uses stay listed',
        tags: ['Admin Sign-up Codes'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Revoked', content: new OA\JsonContent(
                required: ['success', 'signupCode'],
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'signupCode', ref: new Model(type: AdminSignupCode::class)),
                ]
            )),
            new OA\Response(response: 403, description: 'Not an administrator'),
            new OA\Response(response: 404, description: 'Unknown code'),
        ]
    )]
    public function revoke(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        try {
            $code = $this->service->revoke($user, $id, $request->getClientIp() ?? '');
        } catch (SignupCodeAdminException $e) {
            return $this->failure($e);
        }

        return $this->json(['success' => true, 'signupCode' => $code]);
    }

    #[Route('/{id}/uses', name: 'uses', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[OA\Get(
        path: '/api/v1/admin/signup-codes/{id}/uses',
        operationId: 'listAdminSignupCodeUses',
        summary: 'The accounts that signed up with a code, newest first',
        tags: ['Admin Sign-up Codes'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Uses', content: new OA\JsonContent(
                required: ['success', 'uses'],
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'uses', type: 'array', items: new OA\Items(ref: new Model(type: AdminSignupCodeUse::class))),
                ]
            )),
            new OA\Response(response: 403, description: 'Not an administrator'),
            new OA\Response(response: 404, description: 'Unknown code'),
        ]
    )]
    public function uses(int $id): JsonResponse
    {
        try {
            return $this->json(['success' => true, 'uses' => $this->service->uses($id)]);
        } catch (SignupCodeAdminException $e) {
            return $this->failure($e);
        }
    }

    /**
     * A JSON object, or a 400: a malformed body must not be acknowledged as
     * an update that was never applied.
     *
     * @return array<string, mixed>
     *
     * @throws SignupCodeAdminException
     */
    private function body(Request $request): array
    {
        $body = json_decode($request->getContent(), true);
        if (!is_array($body) || (array_is_list($body) && [] !== $body)) {
            throw SignupCodeAdminException::invalid('The request body must be a JSON object.');
        }

        return $body;
    }

    private function failure(SignupCodeAdminException $e): JsonResponse
    {
        $status = SignupCodeAdminException::NOT_FOUND === $e->getErrorCode() ? Response::HTTP_NOT_FOUND : Response::HTTP_BAD_REQUEST;

        return $this->json(['success' => false, 'error' => $e->getMessage(), 'code' => $e->getErrorCode()], $status);
    }
}
