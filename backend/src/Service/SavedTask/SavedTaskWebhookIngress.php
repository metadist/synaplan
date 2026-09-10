<?php

declare(strict_types=1);

namespace App\Service\SavedTask;

use App\Entity\SavedTask;
use App\Entity\User;
use App\Repository\SavedTaskRepository;
use App\Repository\UserRepository;
use App\Service\RateLimitService;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public inbound webhook for a Saved Task. Same 404 for unknown, disabled,
 * or flag-off so tokens cannot be enumerated.
 */
final readonly class SavedTaskWebhookIngress
{
    public const MAX_BODY_BYTES = 65536;
    public const RATE_PER_MINUTE = 60;

    public function __construct(
        private SavedTaskRepository $tasks,
        private UserRepository $users,
        private SavedTaskRunner $runner,
        private WorkflowsConfig $workflows,
        private RateLimitService $rateLimits,
        private CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function handle(string $token, string $rawBody, ?string $signatureHeader): array
    {
        $notFound = ['status' => Response::HTTP_NOT_FOUND, 'body' => ['error' => 'Not found']];
        if ('' === $token || strlen($rawBody) > self::MAX_BODY_BYTES) {
            return $notFound;
        }

        $task = $this->tasks->findByWebhookToken($token);
        if (!$task instanceof SavedTask || !$task->isEnabled()) {
            return $notFound;
        }
        if (!$this->workflows->isBuilderEnabled($task->getOwnerId())) {
            return $notFound;
        }

        $secret = $task->getTriggerConfig()['hmacSecret'] ?? null;
        if (is_string($secret) && '' !== $secret) {
            $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);
            $given = is_string($signatureHeader) ? $signatureHeader : '';
            if ('' === $given || !hash_equals($expected, $given)) {
                return ['status' => Response::HTTP_UNAUTHORIZED, 'body' => ['error' => 'Invalid signature']];
            }
        }

        if (!$this->allowTaskRate((int) $task->getId())) {
            return ['status' => Response::HTTP_TOO_MANY_REQUESTS, 'body' => ['error' => 'Too many requests']];
        }

        $user = $this->users->find($task->getOwnerId());
        if (!$user instanceof User || !$user->isActive()) {
            return $notFound;
        }
        $limit = $this->rateLimits->checkLimit($user, 'MESSAGES');
        if (empty($limit['allowed'])) {
            return ['status' => Response::HTTP_TOO_MANY_REQUESTS, 'body' => ['error' => 'Too many requests']];
        }

        $payload = $this->decodeBody($rawBody);
        try {
            $this->runner->run($task->getOwnerId(), (int) $task->getId(), '', 'webhook', $payload);
        } catch (SavedTaskDisabledException|SavedTaskNotFoundException) {
            return $notFound;
        }

        return ['status' => Response::HTTP_ACCEPTED, 'body' => ['success' => true]];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(string $rawBody): array
    {
        if ('' === trim($rawBody)) {
            return [];
        }
        try {
            $decoded = json_decode($rawBody, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['body' => $rawBody];
        }

        return is_array($decoded) ? $decoded : ['body' => $rawBody];
    }

    private function allowTaskRate(int $taskId): bool
    {
        $minute = (string) intdiv(time(), 60);
        $key = sprintf('saved_task_wh_%d_%s', $taskId, $minute);
        $item = $this->cache->getItem($key);
        $used = is_int($item->get()) ? $item->get() : 0;
        if ($used >= self::RATE_PER_MINUTE) {
            return false;
        }
        $item->set($used + 1);
        $item->expiresAfter(70);
        $this->cache->save($item);

        return true;
    }
}
