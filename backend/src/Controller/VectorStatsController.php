<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Vector storage (RAG) statistics.
 *
 * Exposes how many files and vectors (chunks) are stored for the current user
 * and — for admins — a global inventory of the active vector store (Qdrant or
 * MariaDB) including the top users by stored vectors.
 */
#[Route('/api/v1/vector-stats', name: 'api_vector_stats_')]
#[OA\Tag(name: 'Vector Stats')]
final class VectorStatsController extends AbstractController
{
    public function __construct(
        private readonly VectorStorageFacade $vectorStorageFacade,
        private readonly UserRepository $userRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/me', name: 'me', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/vector-stats/me',
        summary: "Get the current user's vector storage statistics",
        tags: ['Vector Stats'],
        responses: [
            new OA\Response(response: 200, description: 'Vector stats for the current user'),
            new OA\Response(response: 401, description: 'Not authenticated'),
        ]
    )]
    public function me(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $provider = $this->vectorStorageFacade->getConfiguredProvider();
        $available = $this->vectorStorageFacade->isAvailable();

        try {
            $stats = $this->vectorStorageFacade->getStats($user->getId());

            $groups = [];
            foreach ($stats->chunksByGroup as $name => $count) {
                $groups[] = ['name' => (string) $name, 'chunks' => (int) $count];
            }
            usort($groups, fn (array $a, array $b) => $b['chunks'] <=> $a['chunks']);

            return $this->json([
                'success' => true,
                'provider' => $provider,
                'available' => $available,
                'totalFiles' => $stats->totalFiles,
                'totalChunks' => $stats->totalChunks,
                'totalGroups' => $stats->totalGroups,
                'groups' => $groups,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('VectorStatsController: failed to load user stats', ['error' => $e->getMessage()]);

            return $this->json([
                'success' => true,
                'provider' => $provider,
                'available' => false,
                'totalFiles' => 0,
                'totalChunks' => 0,
                'totalGroups' => 0,
                'groups' => [],
            ]);
        }
    }

    #[Route('/admin', name: 'admin', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN', message: 'Admin access required')]
    #[OA\Get(
        path: '/api/v1/vector-stats/admin',
        summary: 'Get global vector storage statistics across all users (admin only)',
        tags: ['Vector Stats'],
        responses: [
            new OA\Response(response: 200, description: 'Global vector stats'),
            new OA\Response(response: 403, description: 'Admin access required'),
        ]
    )]
    public function admin(): JsonResponse
    {
        $provider = $this->vectorStorageFacade->getConfiguredProvider();
        $available = $this->vectorStorageFacade->isAvailable();

        try {
            $global = $this->vectorStorageFacade->getGlobalStats(10);
        } catch (\Throwable $e) {
            $this->logger->warning('VectorStatsController: failed to load global stats', ['error' => $e->getMessage()]);

            return $this->json([
                'success' => true,
                'provider' => $provider,
                'available' => false,
                'totalUsers' => 0,
                'totalFiles' => 0,
                'totalChunks' => 0,
                'topUsers' => [],
            ]);
        }

        $topUsers = $global['topUsers'];
        $userIds = array_map(static fn (array $row) => $row['userId'], $topUsers);

        $emailById = [];
        $levelById = [];
        if (!empty($userIds)) {
            foreach ($this->userRepository->findBy(['id' => $userIds]) as $u) {
                $emailById[$u->getId()] = $u->getMail();
                $levelById[$u->getId()] = $u->getUserLevel();
            }
        }

        $enrichedTopUsers = array_map(static fn (array $row) => [
            'userId' => $row['userId'],
            'email' => $emailById[$row['userId']] ?? null,
            'level' => $levelById[$row['userId']] ?? null,
            'files' => $row['files'],
            'chunks' => $row['chunks'],
        ], $topUsers);

        return $this->json([
            'success' => true,
            'provider' => $provider,
            'available' => $available,
            'totalUsers' => $global['totalUsers'],
            'totalFiles' => $global['totalFiles'],
            'totalChunks' => $global['totalChunks'],
            'topUsers' => $enrichedTopUsers,
        ]);
    }
}
