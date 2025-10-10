<?php

namespace App\Controller;

use App\AI\Service\ProviderRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class HealthController extends AbstractController
{
    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function health(ProviderRegistry $registry): JsonResponse
    {
        $providers = [];
        foreach ($registry->getAllProviders() as $provider) {
            $providers[$provider->getName()] = $provider->getStatus();
        }
        
        return $this->json([
            'status' => 'ok',
            'timestamp' => time(),
            'providers' => $providers,
        ]);
    }
}

