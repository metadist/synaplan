<?php

namespace App\Controller;

use App\Service\WordPressIntegrationService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/integrations/wordpress', name: 'api_wordpress_integration_')]
class WordPressIntegrationController extends AbstractController
{
    public function __construct(
        private readonly WordPressIntegrationService $integrationService,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/step1', name: 'step1', methods: ['POST'])]
    public function step1(Request $request): JsonResponse
    {
        return $this->handle(function () use ($request) {
            $payload = $this->collectPayload($request);

            return $this->integrationService->step1VerifyAndCreateUser($payload);
        });
    }

    #[Route('/step2', name: 'step2', methods: ['POST'])]
    public function step2(Request $request): JsonResponse
    {
        return $this->handle(function () use ($request) {
            $payload = $this->collectPayload($request);
            $userId = (int) ($payload['user_id'] ?? 0);

            if ($userId <= 0) {
                throw new \InvalidArgumentException('user_id is required');
            }

            return $this->integrationService->step2CreateApiKey($userId);
        });
    }

    #[Route('/step3', name: 'step3', methods: ['POST'])]
    public function step3(Request $request): JsonResponse
    {
        return $this->handle(function () use ($request) {
            $payload = $this->collectPayload($request);
            $userId = (int) ($payload['user_id'] ?? 0);

            if ($userId <= 0) {
                throw new \InvalidArgumentException('user_id is required');
            }

            $file = $request->files->get('file');
            if (null === $file) {
                throw new \InvalidArgumentException('file upload is required');
            }

            return $this->integrationService->step3UploadFile($userId, $file);
        });
    }

    #[Route('/step4', name: 'step4', methods: ['POST'])]
    public function step4(Request $request): JsonResponse
    {
        return $this->handle(function () use ($request) {
            $payload = $this->collectPayload($request);
            $userId = (int) ($payload['user_id'] ?? 0);

            if ($userId <= 0) {
                throw new \InvalidArgumentException('user_id is required');
            }

            return $this->integrationService->step4EnableFileSearch($userId);
        });
    }

    #[Route('/step5', name: 'step5', methods: ['POST'])]
    public function step5(Request $request): JsonResponse
    {
        return $this->handle(function () use ($request) {
            $payload = $this->collectPayload($request);
            $userId = (int) ($payload['user_id'] ?? 0);

            if ($userId <= 0) {
                throw new \InvalidArgumentException('user_id is required');
            }

            return $this->integrationService->step5SaveWidget($userId, $payload);
        });
    }

    #[Route('/complete', name: 'complete', methods: ['POST'])]
    public function complete(Request $request): JsonResponse
    {
        return $this->handle(function () use ($request) {
            $payload = $this->collectPayload($request);
            $files = $request->files->all();

            return $this->integrationService->completeWizard($payload, array_values($files));
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function collectPayload(Request $request): array
    {
        $payload = array_merge($request->query->all(), $request->request->all());
        $contentType = $request->headers->get('Content-Type', '');

        if (str_contains($contentType, 'application/json') && $request->getContent()) {
            $decoded = json_decode($request->getContent(), true);
            if (is_array($decoded)) {
                $payload = array_merge($payload, $decoded);
            }
        }

        return $payload;
    }

    /**
     * @param callable(): array<string,mixed> $callback
     */
    private function handle(callable $callback): JsonResponse
    {
        try {
            $result = $callback();

            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->error('WordPress integration error', [
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }
}
