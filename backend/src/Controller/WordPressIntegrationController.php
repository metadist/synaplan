<?php

namespace App\Controller;

use App\Entity\User;
use App\Exception\ApiKeyPlanRequiredException;
use App\Service\Widget\WordPressIntegrationService;
use OpenApi\Attributes as OA;
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
    #[OA\Post(path: '/api/v1/integrations/wordpress/step1', summary: 'WordPress setup: verify the site for the signed-in account', security: [['Bearer' => []]], tags: ['WordPress'])]
    public function step1(Request $request): JsonResponse
    {
        $user = $this->requireUser();

        return $this->handle(function () use ($request, $user) {
            $payload = $this->collectPayload($request);
            $this->assertOwnAccount($user, $payload);

            return $this->integrationService->step1VerifyAndCreateUser($payload);
        });
    }

    #[Route('/step2', name: 'step2', methods: ['POST'])]
    #[OA\Post(path: '/api/v1/integrations/wordpress/step2', summary: 'WordPress setup: create an API key for the signed-in account', security: [['Bearer' => []]], tags: ['WordPress'])]
    public function step2(Request $request): JsonResponse
    {
        unset($request);
        $user = $this->requireUser();

        return $this->handle(function () use ($user) {
            return $this->integrationService->step2CreateApiKey((int) $user->getId());
        });
    }

    #[Route('/step3', name: 'step3', methods: ['POST'])]
    #[OA\Post(path: '/api/v1/integrations/wordpress/step3', summary: 'WordPress setup: upload a file for the signed-in account', security: [['Bearer' => []]], tags: ['WordPress'])]
    public function step3(Request $request): JsonResponse
    {
        $user = $this->requireUser();

        return $this->handle(function () use ($request, $user) {
            $file = $request->files->get('file');
            if (null === $file) {
                throw new \InvalidArgumentException('file upload is required');
            }

            return $this->integrationService->step3UploadFile((int) $user->getId(), $file);
        });
    }

    #[Route('/step4', name: 'step4', methods: ['POST'])]
    #[OA\Post(path: '/api/v1/integrations/wordpress/step4', summary: 'WordPress setup: turn on file search for the signed-in account', security: [['Bearer' => []]], tags: ['WordPress'])]
    public function step4(Request $request): JsonResponse
    {
        unset($request);
        $user = $this->requireUser();

        return $this->handle(function () use ($user) {
            return $this->integrationService->step4EnableFileSearch((int) $user->getId());
        });
    }

    #[Route('/step5', name: 'step5', methods: ['POST'])]
    #[OA\Post(path: '/api/v1/integrations/wordpress/step5', summary: 'WordPress setup: save the chat widget for the signed-in account', security: [['Bearer' => []]], tags: ['WordPress'])]
    public function step5(Request $request): JsonResponse
    {
        $user = $this->requireUser();

        return $this->handle(function () use ($request, $user) {
            return $this->integrationService->step5SaveWidget((int) $user->getId(), $this->collectPayload($request));
        });
    }

    #[Route('/complete', name: 'complete', methods: ['POST'])]
    #[OA\Post(path: '/api/v1/integrations/wordpress/complete', summary: 'WordPress setup: finish the wizard for the signed-in account', security: [['Bearer' => []]], tags: ['WordPress'])]
    public function complete(Request $request): JsonResponse
    {
        $user = $this->requireUser();

        return $this->handle(function () use ($request, $user) {
            $payload = $this->collectPayload($request);
            $this->assertOwnAccount($user, $payload);

            return $this->integrationService->completeWizard($payload, array_values($request->files->all()));
        });
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User || (int) $user->getId() <= 0) {
            throw $this->createAccessDeniedException('Authentication required.');
        }

        return $user;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertOwnAccount(User $user, array $payload): void
    {
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        if ('' === $email || $email !== strtolower($user->getMail())) {
            throw new \InvalidArgumentException('WordPress setup can only continue for the signed-in account.');
        }
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
        } catch (ApiKeyPlanRequiredException $refusal) {
            return $this->json($refusal->body(), Response::HTTP_FORBIDDEN);
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
