<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\File\CloudFilesConfig;
use App\Service\OAuth\Server\AccessTokenKeyStore;
use App\Service\PlatformLink\NotifyKeyStore;
use App\Service\PlatformLink\PlatformLinksConfig;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * What a connected cloud needs to trust Synaplan's notifications, without a
 * credential. The configuration names the issuer a notification carries as
 * `iss` and where the keys are, in the shape of OAuth2 authorization server
 * metadata (RFC 8414), so a cloud that reaches Synaplan by an internal
 * address still learns the public one. The keys are a JWK Set (RFC 7517,
 * Ed25519 per RFC 8037), with the RS256 key Synaplan's OAuth2 access tokens
 * are signed with; /.well-known/oauth-authorization-server names the same
 * issuer and set.
 */
#[OA\Tag(name: 'Platform Links')]
final class CloudWellKnownController extends AbstractController
{
    public function __construct(
        private readonly NotifyKeyStore $notifyKeys,
        private readonly AccessTokenKeyStore $accessTokenKeys,
        private readonly PlatformLinksConfig $platformLinks,
        private readonly CloudFilesConfig $cloudFiles,
        #[Autowire('%env(APP_URL)%')]
        private readonly string $appUrl,
    ) {
    }

    #[Route('/.well-known/synaplan-configuration', name: 'synaplan_configuration', methods: ['GET'])]
    #[OA\Get(
        path: '/.well-known/synaplan-configuration',
        operationId: 'synaplanConfiguration',
        summary: 'The issuer of Synaplan\'s notifications and where its keys are',
        tags: ['Platform Links'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Configuration',
                content: new OA\JsonContent(
                    required: ['issuer', 'jwks_uri', 'capabilities'],
                    properties: [
                        new OA\Property(property: 'issuer', type: 'string', example: 'https://synaplan.example'),
                        new OA\Property(property: 'jwks_uri', type: 'string', example: 'https://synaplan.example/.well-known/jwks.json'),
                        new OA\Property(property: 'capabilities', type: 'array', items: new OA\Items(type: 'string'), description: 'What this Synaplan offers clouds: deliveries, pickups, read_scope, and with cloud files on cloud_status, source_events, watches.'),
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Platform links are off'),
        ]
    )]
    public function configuration(): JsonResponse
    {
        if (!$this->platformLinks->isEnabled(null)) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        $issuer = rtrim($this->appUrl, '/');
        $capabilities = ['deliveries', 'pickups', 'read_scope'];
        if ($this->cloudFiles->isEnabled()) {
            $capabilities = [...$capabilities, 'cloud_status', 'source_events', 'watches'];
        }
        $response = $this->json([
            'issuer' => $issuer,
            'jwks_uri' => $issuer.'/.well-known/jwks.json',
            'capabilities' => $capabilities,
        ]);
        $response->setPublic();
        $response->setMaxAge(300);

        return $response;
    }

    #[Route('/.well-known/jwks.json', name: 'jwks', methods: ['GET'])]
    #[OA\Get(
        path: '/.well-known/jwks.json',
        operationId: 'jwks',
        summary: 'Public keys Synaplan signs cloud notifications with',
        tags: ['Platform Links'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'JWK Set',
                content: new OA\JsonContent(
                    required: ['keys'],
                    properties: [
                        new OA\Property(property: 'keys', type: 'array', items: new OA\Items(
                            required: ['kty', 'crv', 'x', 'kid', 'use', 'alg'],
                            properties: [
                                new OA\Property(property: 'kty', type: 'string', example: 'OKP'),
                                new OA\Property(property: 'crv', type: 'string', example: 'Ed25519'),
                                new OA\Property(property: 'x', type: 'string', description: 'Public key, base64url'),
                                new OA\Property(property: 'kid', type: 'string'),
                                new OA\Property(property: 'use', type: 'string', example: 'sig'),
                                new OA\Property(property: 'alg', type: 'string', example: 'EdDSA'),
                            ],
                        )),
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Platform links are off'),
        ]
    )]
    public function keys(): JsonResponse
    {
        if (!$this->platformLinks->isEnabled(null)) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        $keys = [];
        foreach ($this->notifyKeys->publicKeys() as $key) {
            $keys[] = [
                'kty' => 'OKP',
                'crv' => 'Ed25519',
                'x' => substr($key['publicKey'], strlen('ed25519:')),
                'kid' => $key['kid'],
                'use' => 'sig',
                'alg' => 'EdDSA',
            ];
        }
        $keys[] = $this->accessTokenKeys->publicJwk();
        $response = $this->json(['keys' => $keys]);
        $response->setPublic();
        $response->setMaxAge(300);

        return $response;
    }
}
