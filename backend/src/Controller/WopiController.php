<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Office\Wopi\WopiAccess;
use App\Service\Office\Wopi\WopiDocumentService;
use App\Service\Office\Wopi\WopiHttpResult;
use App\Service\Office\Wopi\WopiTokenService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Collabora Online talks to Synaplan as a WOPI host. The browser never sends
 * the user's session here: every callback carries the short-lived file token
 * from {@see editSession}.
 */
final class WopiController extends AbstractController
{
    public function __construct(
        private readonly WopiDocumentService $documents,
        private readonly WopiTokenService $tokens,
    ) {
    }

    #[Route('/api/v1/files/{id}/edit-session', name: 'api_files_edit_session', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[OA\Get(
        path: '/api/v1/files/{id}/edit-session',
        operationId: 'fileEditSession',
        summary: 'Open a file in the office editor',
        description: 'Mints a short-lived WOPI token for this file and returns the Collabora editor URL. The token is the only credential on the later WOPI calls. Answers 404 when the editor is not configured.',
        tags: ['Files'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Editor session',
                content: new OA\JsonContent(
                    required: ['editorUrl', 'accessToken', 'accessTokenTtl', 'wopiSrc'],
                    properties: [
                        new OA\Property(property: 'editorUrl', type: 'string', example: 'http://localhost:9981/browser/abc/cool.html?WOPISrc=http%3A%2F%2Fbackend%2Fapi%2Fv1%2Fwopi%2Ffiles%2F1'),
                        new OA\Property(property: 'accessToken', type: 'string'),
                        new OA\Property(property: 'accessTokenTtl', type: 'integer', description: 'Unix expiry in milliseconds', example: 1762100000000),
                        new OA\Property(property: 'wopiSrc', type: 'string', example: 'http://backend/api/v1/wopi/files/1'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 403, description: 'Only the owner can open the editor'),
            new OA\Response(response: 404, description: 'File not found, or the editor is not configured'),
            new OA\Response(response: 422, description: 'This file type cannot be opened in the editor'),
            new OA\Response(response: 503, description: 'Collabora discovery is not reachable'),
        ],
    )]
    public function editSession(int $id, #[CurrentUser] ?User $user): Response
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->respond($this->documents->editSession($user, $id));
    }

    #[Route('/api/v1/wopi/files/{id}', name: 'api_wopi_check_info', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[OA\Get(
        path: '/api/v1/wopi/files/{id}',
        operationId: 'wopiCheckFileInfo',
        summary: 'WOPI CheckFileInfo',
        description: 'Collabora calls this with the file-scoped access_token. It does not accept a session cookie or an API key.',
        tags: ['WOPI'],
        security: [],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'access_token', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'CheckFileInfo JSON'),
            new OA\Response(response: 401, description: 'Missing or invalid access token'),
            new OA\Response(response: 404, description: 'File not found, or the editor is not configured'),
        ],
    )]
    public function checkFileInfo(int $id, Request $request): Response
    {
        $access = $this->access($request, $id);
        if ($access instanceof Response) {
            return $access;
        }

        return $this->respond($this->documents->checkFileInfo($access));
    }

    #[Route('/api/v1/wopi/files/{id}/contents', name: 'api_wopi_contents', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[OA\Get(
        path: '/api/v1/wopi/files/{id}/contents',
        operationId: 'wopiGetFile',
        summary: 'WOPI GetFile',
        tags: ['WOPI'],
        security: [],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'access_token', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'File bytes'),
            new OA\Response(response: 401, description: 'Missing or invalid access token'),
            new OA\Response(response: 404, description: 'File not found'),
        ],
    )]
    public function getFile(int $id, Request $request): Response
    {
        $access = $this->access($request, $id);
        if ($access instanceof Response) {
            return $access;
        }

        return $this->respond($this->documents->getFile($access));
    }

    #[Route('/api/v1/wopi/files/{id}/contents', name: 'api_wopi_contents_put', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[OA\Post(
        path: '/api/v1/wopi/files/{id}/contents',
        operationId: 'wopiPutFile',
        summary: 'WOPI PutFile',
        description: 'Writes the document when X-COOL-WOPI-Timestamp matches CheckFileInfo LastModifiedTime and the WOPI lock matches. A mismatch answers 409 with COOLStatusCode 1010 and does not write.',
        tags: ['WOPI'],
        security: [],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'access_token', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-WOPI-Override', in: 'header', required: true, schema: new OA\Schema(type: 'string', example: 'PUT')),
            new OA\Parameter(name: 'X-COOL-WOPI-Timestamp', in: 'header', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-WOPI-Lock', in: 'header', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Saved. Body contains the new LastModifiedTime.'),
            new OA\Response(response: 401, description: 'Missing or invalid access token'),
            new OA\Response(response: 404, description: 'File not found'),
            new OA\Response(response: 409, description: 'Lock mismatch, or the timestamp no longer matches (COOLStatusCode 1010)'),
        ],
    )]
    public function putFile(int $id, Request $request): Response
    {
        $access = $this->access($request, $id);
        if ($access instanceof Response) {
            return $access;
        }
        if ('PUT' !== strtoupper(trim((string) $request->headers->get('X-WOPI-Override', '')))) {
            return $this->json(['error' => 'X-WOPI-Override must be PUT'], Response::HTTP_BAD_REQUEST);
        }

        return $this->respond($this->documents->putFile(
            $access,
            $request->getContent(),
            $this->timestamp($request),
            (string) $request->headers->get('X-WOPI-Lock', ''),
        ));
    }

    #[Route('/api/v1/wopi/files/{id}', name: 'api_wopi_lock', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[OA\Post(
        path: '/api/v1/wopi/files/{id}',
        operationId: 'wopiLock',
        summary: 'WOPI Lock, Unlock, RefreshLock, UnlockAndRelock, GetLock',
        tags: ['WOPI'],
        security: [],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'access_token', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-WOPI-Override', in: 'header', required: true, schema: new OA\Schema(type: 'string', enum: ['LOCK', 'UNLOCK', 'REFRESH_LOCK', 'UNLOCK_AND_RELOCK', 'GET_LOCK'])),
            new OA\Parameter(name: 'X-WOPI-Lock', in: 'header', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-WOPI-OldLock', in: 'header', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lock header X-WOPI-Lock carries the current lock'),
            new OA\Response(response: 401, description: 'Missing or invalid access token'),
            new OA\Response(response: 404, description: 'File not found'),
            new OA\Response(response: 409, description: 'Lock conflict. X-WOPI-Lock is the current lock'),
            new OA\Response(response: 501, description: 'Unsupported X-WOPI-Override'),
        ],
    )]
    public function lock(int $id, Request $request): Response
    {
        $access = $this->access($request, $id);
        if ($access instanceof Response) {
            return $access;
        }

        return $this->respond($this->documents->lock(
            $access,
            (string) $request->headers->get('X-WOPI-Override', ''),
            (string) $request->headers->get('X-WOPI-Lock', ''),
            (string) $request->headers->get('X-WOPI-OldLock', ''),
        ));
    }

    private function access(Request $request, int $fileId): WopiAccess|Response
    {
        $token = $request->query->get('access_token');
        if (!is_string($token) || '' === $token) {
            return $this->json(['error' => 'Missing access token'], Response::HTTP_UNAUTHORIZED);
        }
        $access = $this->tokens->verify($token);
        if (null === $access || $access->fileId !== $fileId) {
            return $this->json(['error' => 'Invalid access token'], Response::HTTP_UNAUTHORIZED);
        }

        return $access;
    }

    private function timestamp(Request $request): string
    {
        foreach (['X-COOL-WOPI-Timestamp', 'X-LOOL-WOPI-Timestamp', 'X-WOPI-TimeStamp'] as $name) {
            $value = $request->headers->get($name);
            if (is_string($value) && '' !== trim($value)) {
                return $value;
            }
        }

        return '';
    }

    private function respond(WopiHttpResult $result): Response
    {
        if (null !== $result->filePath) {
            $response = new BinaryFileResponse($result->filePath);
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $result->downloadName ?? 'document');
            $response->headers->set('X-Content-Type-Options', 'nosniff');

            return $response;
        }
        if (null === $result->json) {
            $response = new Response('', $result->status);
            foreach ($result->headers as $name => $value) {
                $response->headers->set($name, $value);
            }

            return $response;
        }

        return new JsonResponse($result->json, $result->status, $result->headers);
    }
}
