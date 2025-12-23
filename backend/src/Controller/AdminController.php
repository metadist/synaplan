<?php

namespace App\Controller;

use App\Entity\Prompt;
use App\Entity\User;
use App\Repository\PromptRepository;
use App\Repository\UseLogRepository;
use App\Repository\UserRepository;
use App\Service\UsageStatsService;
use App\Service\UserDeletionService;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/admin')]
#[OA\Tag(name: 'Admin')]
class AdminController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
        private PromptRepository $promptRepository,
        private UseLogRepository $useLogRepository,
        private UsageStatsService $usageStatsService,
        private UserDeletionService $userDeletionService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Get all users (admin only).
     */
    #[Route('/users', name: 'admin_get_users', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/admin/users',
        summary: 'Get all users',
        description: 'Get list of all users (admin only)',
        security: [['Bearer' => []]],
        tags: ['Admin']
    )]
    #[OA\Parameter(
        name: 'page',
        in: 'query',
        description: 'Page number',
        required: false,
        schema: new OA\Schema(type: 'integer', default: 1)
    )]
    #[OA\Parameter(
        name: 'limit',
        in: 'query',
        description: 'Items per page',
        required: false,
        schema: new OA\Schema(type: 'integer', default: 50)
    )]
    #[OA\Parameter(
        name: 'search',
        in: 'query',
        description: 'Search by email',
        required: false,
        schema: new OA\Schema(type: 'string')
    )]
    #[OA\Response(
        response: 200,
        description: 'List of users',
        content: new OA\JsonContent(
            required: ['users', 'total', 'page', 'limit'],
            properties: [
                new OA\Property(
                    property: 'users',
                    type: 'array',
                    items: new OA\Items(
                        type: 'object',
                        required: ['id', 'level', 'type', 'providerId', 'emailVerified', 'created', 'isAdmin', 'locale'],
                        properties: [
                            new OA\Property(property: 'id', type: 'integer', example: 1),
                            new OA\Property(property: 'email', type: 'string', description: 'Email address or phone number (if no email available)', example: 'user@example.com', nullable: true),
                            new OA\Property(property: 'level', type: 'string', enum: ['ANONYMOUS', 'NEW', 'PRO', 'TEAM', 'BUSINESS', 'ADMIN'], example: 'PRO'),
                            new OA\Property(property: 'type', type: 'string', example: 'email'),
                            new OA\Property(property: 'providerId', type: 'string', example: 'email'),
                            new OA\Property(property: 'emailVerified', type: 'boolean', example: true),
                            new OA\Property(property: 'created', type: 'string', format: 'date-time', example: '2024-01-15 10:30:00'),
                            new OA\Property(property: 'isAdmin', type: 'boolean', example: false),
                            new OA\Property(property: 'locale', type: 'string', example: 'en'),
                        ]
                    )
                ),
                new OA\Property(property: 'total', type: 'integer', example: 150),
                new OA\Property(property: 'page', type: 'integer', example: 1),
                new OA\Property(property: 'limit', type: 'integer', example: 50),
            ]
        )
    )]
    #[OA\Response(response: 403, description: 'Not authorized')]
    public function getUsers(
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user || !$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $limit = max(1, min(100, (int) $request->query->get('limit', 50)));
        $search = $request->query->get('search', '');

        $qb = $this->userRepository->createQueryBuilder('u')
            ->orderBy('u.id', 'DESC');

        if ($search) {
            $qb->andWhere('u.mail LIKE :search')
                ->setParameter('search', '%'.$search.'%');
        }

        // Get total count
        $totalQb = clone $qb;
        $total = (int) $totalQb->select('COUNT(u.id)')
            ->getQuery()
            ->getSingleScalarResult();

        // Get paginated results
        $offset = ($page - 1) * $limit;
        $users = $qb->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $usersData = array_map(function (User $u) {
            // Get email from BMAIL field
            $email = $u->getMail();

            // If email is empty or invalid, check BUSERDETAILS for phone number
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $userDetails = $u->getUserDetails();
                // Check for phone_number (verified phone) or anonymous_phone
                $phone = $userDetails['phone_number'] ?? $userDetails['anonymous_phone'] ?? null;
                if (!empty($phone)) {
                    // Use phone number as email identifier
                    $email = $phone;
                } else {
                    // No email and no phone - set to null
                    $email = null;
                }
            }

            // Ensure level is valid, default to 'NEW' if invalid
            $level = $u->getUserLevel();
            $validLevels = ['ANONYMOUS', 'NEW', 'PRO', 'TEAM', 'BUSINESS', 'ADMIN'];
            if (!in_array($level, $validLevels, true)) {
                $level = 'NEW';
            }

            return [
                'id' => $u->getId(),
                'email' => $email,
                'level' => $level,
                'type' => $u->getType(),
                'providerId' => $u->getProviderId(),
                'emailVerified' => $u->isEmailVerified(),
                'created' => $u->getCreated(),
                'isAdmin' => $u->isAdmin(),
                'locale' => $u->getLocale(),
            ];
        }, $users);

        return $this->json([
            'users' => $usersData,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    /**
     * Update user level (admin only).
     */
    #[Route('/users/{id}/level', name: 'admin_update_user_level', methods: ['PATCH'])]
    #[OA\Patch(
        path: '/api/v1/admin/users/{id}/level',
        summary: 'Update user level',
        description: 'Update user level (admin only)',
        security: [['Bearer' => []]],
        tags: ['Admin']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        description: 'User ID',
        required: true,
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['level'],
            properties: [
                new OA\Property(property: 'level', type: 'string', enum: ['ANONYMOUS', 'NEW', 'PRO', 'TEAM', 'BUSINESS', 'ADMIN'], example: 'PRO'),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'User level updated')]
    #[OA\Response(response: 403, description: 'Not authorized')]
    #[OA\Response(response: 404, description: 'User not found')]
    public function updateUserLevel(
        int $id,
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user || !$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        $targetUser = $this->userRepository->find($id);
        if (!$targetUser) {
            return $this->json(['error' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        $newLevel = $data['level'] ?? '';

        $allowedLevels = ['ANONYMOUS', 'NEW', 'PRO', 'TEAM', 'BUSINESS', 'ADMIN'];
        if (!in_array($newLevel, $allowedLevels)) {
            return $this->json(['error' => 'Invalid level'], Response::HTTP_BAD_REQUEST);
        }

        $targetUser->setUserLevel($newLevel);
        $this->em->flush();

        $this->logger->info('Admin updated user level', [
            'admin_id' => $user->getId(),
            'target_user_id' => $id,
            'new_level' => $newLevel,
        ]);

        return $this->json([
            'success' => true,
            'user' => [
                'id' => $targetUser->getId(),
                'email' => $targetUser->getMail(),
                'level' => $targetUser->getUserLevel(),
            ],
        ]);
    }

    /**
     * Delete user (admin only).
     */
    #[Route('/users/{id}', name: 'admin_delete_user', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/admin/users/{id}',
        summary: 'Delete user',
        description: 'Delete user account (admin only)',
        security: [['Bearer' => []]],
        tags: ['Admin']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        description: 'User ID',
        required: true,
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(response: 200, description: 'User deleted')]
    #[OA\Response(response: 403, description: 'Not authorized or cannot delete self')]
    #[OA\Response(response: 404, description: 'User not found')]
    public function deleteUser(
        int $id,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user || !$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        if ($user->getId() === $id) {
            return $this->json(['error' => 'Cannot delete your own account'], Response::HTTP_FORBIDDEN);
        }

        $targetUser = $this->userRepository->find($id);
        if (!$targetUser) {
            return $this->json(['error' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        $this->logger->info('Admin initiated user deletion', [
            'admin_id' => $user->getId(),
            'target_user_id' => $id,
            'target_email' => $targetUser->getMail(),
        ]);

        try {
            $this->userDeletionService->deleteUser($targetUser);

            return $this->json(['success' => true, 'message' => 'User deleted']);
        } catch (\Throwable $e) {
            $this->logger->error('Admin user deletion failed', [
                'admin_id' => $user->getId(),
                'target_user_id' => $id,
                'exception' => $e,
            ]);

            return $this->json([
                'error' => 'Failed to delete user. Please contact support.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get all system prompts (admin only).
     */
    #[Route('/prompts', name: 'admin_get_prompts', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/admin/prompts',
        summary: 'Get all system prompts',
        description: 'Get all system prompts (ownerId = 0) (admin only)',
        security: [['Bearer' => []]],
        tags: ['Admin']
    )]
    #[OA\Response(
        response: 200,
        description: 'List of prompts',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'prompts', type: 'array', items: new OA\Items(type: 'object')),
            ]
        )
    )]
    #[OA\Response(response: 403, description: 'Not authorized')]
    public function getSystemPrompts(
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user || !$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        $prompts = $this->promptRepository->findBy(['ownerId' => 0], ['topic' => 'ASC']);

        $promptsData = array_map(function (Prompt $p) {
            return [
                'id' => $p->getId(),
                'topic' => $p->getTopic(),
                'language' => $p->getLanguage(),
                'shortDescription' => $p->getShortDescription(),
                'prompt' => $p->getPrompt(),
                'selectionRules' => $p->getSelectionRules(),
            ];
        }, $prompts);

        return $this->json(['prompts' => $promptsData]);
    }

    /**
     * Update system prompt (admin only).
     */
    #[Route('/prompts/{id}', name: 'admin_update_prompt', methods: ['PATCH'])]
    #[OA\Patch(
        path: '/api/v1/admin/prompts/{id}',
        summary: 'Update system prompt',
        description: 'Update system prompt (admin only)',
        security: [['Bearer' => []]],
        tags: ['Admin']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        description: 'Prompt ID',
        required: true,
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'prompt', type: 'string'),
                new OA\Property(property: 'shortDescription', type: 'string'),
                new OA\Property(property: 'selectionRules', type: 'string'),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Prompt updated')]
    #[OA\Response(response: 403, description: 'Not authorized')]
    #[OA\Response(response: 404, description: 'Prompt not found')]
    public function updatePrompt(
        int $id,
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user || !$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        $prompt = $this->promptRepository->find($id);
        if (!$prompt || 0 !== $prompt->getOwnerId()) {
            return $this->json(['error' => 'System prompt not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['prompt'])) {
            $prompt->setPrompt($data['prompt']);
        }
        if (isset($data['shortDescription'])) {
            $prompt->setShortDescription($data['shortDescription']);
        }
        if (isset($data['selectionRules'])) {
            $prompt->setSelectionRules($data['selectionRules']);
        }

        $this->em->flush();

        $this->logger->info('Admin updated system prompt', [
            'admin_id' => $user->getId(),
            'prompt_id' => $id,
            'topic' => $prompt->getTopic(),
        ]);

        return $this->json([
            'success' => true,
            'prompt' => [
                'id' => $prompt->getId(),
                'topic' => $prompt->getTopic(),
                'language' => $prompt->getLanguage(),
                'shortDescription' => $prompt->getShortDescription(),
                'prompt' => $prompt->getPrompt(),
                'selectionRules' => $prompt->getSelectionRules(),
            ],
        ]);
    }

    /**
     * Get usage statistics (admin only).
     */
    #[Route('/usage-stats', name: 'admin_get_usage_stats', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/admin/usage-stats',
        summary: 'Get usage statistics',
        description: 'Get system-wide usage statistics (admin only)',
        security: [['Bearer' => []]],
        tags: ['Admin']
    )]
    #[OA\Parameter(
        name: 'period',
        in: 'query',
        description: 'Time period',
        required: false,
        schema: new OA\Schema(type: 'string', enum: ['day', 'week', 'month', 'all'], default: 'week')
    )]
    #[OA\Response(
        response: 200,
        description: 'Usage statistics',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'totalUsers', type: 'integer'),
                new OA\Property(property: 'totalRequests', type: 'integer'),
                new OA\Property(property: 'totalTokens', type: 'integer'),
                new OA\Property(property: 'totalCost', type: 'number'),
                new OA\Property(property: 'byAction', type: 'object'),
                new OA\Property(property: 'byProvider', type: 'object'),
                new OA\Property(property: 'topUsers', type: 'array', items: new OA\Items(type: 'object')),
            ]
        )
    )]
    #[OA\Response(response: 403, description: 'Not authorized')]
    public function getUsageStats(
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user || !$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        $period = $request->query->get('period', 'all');
        $stats = $this->usageStatsService->getOverallStats($period);

        return $this->json($stats);
    }

    /**
     * Get system overview (admin only).
     */
    #[Route('/overview', name: 'admin_get_overview', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/admin/overview',
        summary: 'Get system overview',
        description: 'Get system-wide overview (admin only)',
        security: [['Bearer' => []]],
        tags: ['Admin']
    )]
    #[OA\Response(
        response: 200,
        description: 'System overview',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'totalUsers', type: 'integer'),
                new OA\Property(property: 'usersByLevel', type: 'object'),
                new OA\Property(property: 'recentUsers', type: 'array', items: new OA\Items(type: 'object')),
            ]
        )
    )]
    #[OA\Response(response: 403, description: 'Not authorized')]
    public function getOverview(
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user || !$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        // Total users
        $totalUsers = $this->userRepository->count([]);

        // Users by level
        $qb = $this->em->createQueryBuilder();
        $usersByLevel = $qb->select('u.userLevel as level, COUNT(u.id) as count')
            ->from('App\Entity\User', 'u')
            ->groupBy('u.userLevel')
            ->getQuery()
            ->getResult();

        $levelCounts = [];
        foreach ($usersByLevel as $row) {
            $levelCounts[$row['level']] = (int) $row['count'];
        }

        // Recent users (last 10)
        $recentUsers = $this->userRepository->findBy([], ['id' => 'DESC'], 10);
        $recentUsersData = array_map(function (User $u) {
            return [
                'id' => $u->getId(),
                'email' => $u->getMail(),
                'level' => $u->getUserLevel(),
                'created' => $u->getCreated(),
                'emailVerified' => $u->isEmailVerified(),
            ];
        }, $recentUsers);

        return $this->json([
            'totalUsers' => $totalUsers,
            'usersByLevel' => $levelCounts,
            'recentUsers' => $recentUsersData,
        ]);
    }

    /**
     * Get user registration analytics (admin only).
     */
    #[Route('/analytics/registrations', name: 'admin_get_registrations', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/admin/analytics/registrations',
        summary: 'Get user registration analytics',
        description: 'Get user registration data over time (admin only)',
        security: [['Bearer' => []]],
        tags: ['Admin']
    )]
    #[OA\Parameter(
        name: 'period',
        in: 'query',
        description: 'Time period',
        required: false,
        schema: new OA\Schema(type: 'string', enum: ['7d', '30d', '90d', '1y', 'all'], default: '30d')
    )]
    #[OA\Parameter(
        name: 'groupBy',
        in: 'query',
        description: 'Group by',
        required: false,
        schema: new OA\Schema(type: 'string', enum: ['day', 'week', 'month'], default: 'day')
    )]
    #[OA\Response(
        response: 200,
        description: 'Registration analytics',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'timeline', type: 'array', items: new OA\Items(type: 'object')),
                new OA\Property(property: 'byProvider', type: 'object'),
                new OA\Property(property: 'byType', type: 'object'),
            ]
        )
    )]
    #[OA\Response(response: 403, description: 'Not authorized')]
    public function getRegistrationAnalytics(
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user || !$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        $period = $request->query->get('period', '30d');
        $groupBy = $request->query->get('groupBy', 'day');

        // Calculate date range
        $now = new \DateTime();
        $since = match ($period) {
            '7d' => (clone $now)->modify('-7 days'),
            '30d' => (clone $now)->modify('-30 days'),
            '90d' => (clone $now)->modify('-90 days'),
            '1y' => (clone $now)->modify('-1 year'),
            default => new \DateTime('2020-01-01'), // All time
        };

        // Get all users within period
        $qb = $this->userRepository->createQueryBuilder('u');

        if ('all' !== $period) {
            $qb->where('u.created >= :since')
                ->setParameter('since', $since->format('Y-m-d H:i:s'));
        }

        $users = $qb->orderBy('u.created', 'ASC')
            ->getQuery()
            ->getResult();

        // Group by timeline
        $timeline = [];
        $byProvider = [];
        $byType = [];

        foreach ($users as $user) {
            $created = new \DateTime($user->getCreated());

            // Determine group key based on groupBy
            $groupKey = match ($groupBy) {
                'week' => $created->format('Y-\WW'),
                'month' => $created->format('Y-m'),
                default => $created->format('Y-m-d'), // day
            };

            // Timeline data
            if (!isset($timeline[$groupKey])) {
                $timeline[$groupKey] = [
                    'date' => $groupKey,
                    'count' => 0,
                    'byProvider' => [],
                    'byType' => [],
                ];
            }
            ++$timeline[$groupKey]['count'];

            // By provider
            $providerId = $user->getProviderId();
            if (!isset($timeline[$groupKey]['byProvider'][$providerId])) {
                $timeline[$groupKey]['byProvider'][$providerId] = 0;
            }
            ++$timeline[$groupKey]['byProvider'][$providerId];

            if (!isset($byProvider[$providerId])) {
                $byProvider[$providerId] = 0;
            }
            ++$byProvider[$providerId];

            // By type
            $type = $user->getType();
            if (!isset($timeline[$groupKey]['byType'][$type])) {
                $timeline[$groupKey]['byType'][$type] = 0;
            }
            ++$timeline[$groupKey]['byType'][$type];

            if (!isset($byType[$type])) {
                $byType[$type] = 0;
            }
            ++$byType[$type];
        }

        // Convert timeline to array and sort
        $timelineArray = array_values($timeline);
        usort($timelineArray, function ($a, $b) {
            return strcmp($a['date'], $b['date']);
        });

        return $this->json([
            'timeline' => $timelineArray,
            'byProvider' => $byProvider,
            'byType' => $byType,
            'period' => $period,
            'groupBy' => $groupBy,
        ]);
    }
}
