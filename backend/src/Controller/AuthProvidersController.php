<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use OpenApi\Attributes as OA;

#[Route('/api/v1/auth')]
class AuthProvidersController extends AbstractController
{
    public function __construct(
        private ?string $googleClientId,
        private ?string $githubClientId,
        private ?string $oidcClientId,
        private ?string $oidcDiscoveryUrl
    ) {}

    #[Route('/providers', name: 'auth_providers', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/auth/providers',
        summary: 'Get available authentication providers',
        tags: ['Authentication']
    )]
    #[OA\Response(
        response: 200,
        description: 'List of enabled authentication providers',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'providers',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'id', type: 'string', example: 'google'),
                            new OA\Property(property: 'name', type: 'string', example: 'Google'),
                            new OA\Property(property: 'enabled', type: 'boolean', example: true)
                        ]
                    )
                )
            ]
        )
    )]
    public function getProviders(): JsonResponse
    {
        $providers = [
            [
                'id' => 'google',
                'name' => 'Google',
                'enabled' => !empty($this->googleClientId) && $this->googleClientId !== 'your-google-client-id',
                'icon' => 'google'
            ],
            [
                'id' => 'github',
                'name' => 'GitHub',
                'enabled' => !empty($this->githubClientId) && $this->githubClientId !== 'your-github-client-id',
                'icon' => 'github'
            ],
            [
                'id' => 'keycloak',
                'name' => 'Keycloak',
                'enabled' => !empty($this->oidcClientId) && 
                            !empty($this->oidcDiscoveryUrl) &&
                            $this->oidcClientId !== 'your-oidc-client-id',
                'icon' => 'key'
            ]
        ];

        return $this->json([
            'providers' => array_values(array_filter($providers, fn($p) => $p['enabled']))
        ]);
    }
}

