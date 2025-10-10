<?php

namespace App\Service;

use App\Repository\RateLimitConfigRepository;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Service für Rate Limiting basierend auf User-Level und Scope
 * 
 * Verwendet Redis Cache für schnelle Zugriffe und RateLimitConfig für Limits
 */
class RateLimiterService
{
    public function __construct(
        private RateLimitConfigRepository $rateLimitConfigRepository,
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger
    ) {}

    /**
     * Prüft ob ein Request erlaubt ist (unter dem Limit)
     * 
     * @param int $userId User ID
     * @param string $userLevel User Level (NEW, PRO, TEAM, BUSINESS)
     * @param string $scope Scope (api_calls, widget_messages, ai_requests, file_uploads)
     * @return array ['allowed' => bool, 'remaining' => int, 'reset_at' => int]
     */
    public function check(int $userId, string $userLevel, string $scope): array
    {
        // Hole Rate Limit Config
        $config = $this->rateLimitConfigRepository->findByUserLevel($scope, $userLevel);
        
        if (!$config) {
            // Kein Limit konfiguriert = erlaubt
            return [
                'allowed' => true,
                'remaining' => 999999,
                'reset_at' => time() + 3600,
            ];
        }
        
        $limit = $config->getLimit();
        $window = $config->getWindow();
        $cacheKey = $this->getCacheKey($userId, $scope);
        
        $item = $this->cache->getItem($cacheKey);
        
        if (!$item->isHit()) {
            // Erste Anfrage im Fenster
            $data = [
                'count' => 0,
                'reset_at' => time() + $window,
            ];
            $item->set($data);
            $item->expiresAfter($window);
            $this->cache->save($item);
        }
        
        $data = $item->get();
        $count = $data['count'] ?? 0;
        $resetAt = $data['reset_at'] ?? time() + $window;
        
        // Prüfe ob Fenster abgelaufen
        if ($resetAt <= time()) {
            // Fenster abgelaufen, reset
            $data = [
                'count' => 0,
                'reset_at' => time() + $window,
            ];
            $count = 0;
            $resetAt = $data['reset_at'];
            $item->set($data);
            $item->expiresAfter($window);
            $this->cache->save($item);
        }
        
        $allowed = $count < $limit;
        $remaining = max(0, $limit - $count);
        
        return [
            'allowed' => $allowed,
            'remaining' => $remaining,
            'reset_at' => $resetAt,
            'limit' => $limit,
        ];
    }

    /**
     * Inkrementiert den Counter für einen Request
     */
    public function increment(int $userId, string $userLevel, string $scope): void
    {
        $config = $this->rateLimitConfigRepository->findByUserLevel($scope, $userLevel);
        
        if (!$config) {
            // Kein Limit = kein Increment
            return;
        }
        
        $window = $config->getWindow();
        $cacheKey = $this->getCacheKey($userId, $scope);
        
        $item = $this->cache->getItem($cacheKey);
        
        if (!$item->isHit()) {
            // Erste Anfrage
            $data = [
                'count' => 1,
                'reset_at' => time() + $window,
            ];
        } else {
            $data = $item->get();
            $data['count'] = ($data['count'] ?? 0) + 1;
        }
        
        $item->set($data);
        $item->expiresAfter($window);
        $this->cache->save($item);
    }

    /**
     * Reset des Counters für einen User und Scope
     */
    public function reset(int $userId, string $scope): void
    {
        $cacheKey = $this->getCacheKey($userId, $scope);
        $this->cache->deleteItem($cacheKey);
    }

    /**
     * Holt den aktuellen Status für einen User
     */
    public function getStatus(int $userId, string $userLevel): array
    {
        $scopes = ['api_calls', 'widget_messages', 'ai_requests', 'file_uploads'];
        $status = [];
        
        foreach ($scopes as $scope) {
            $status[$scope] = $this->check($userId, $userLevel, $scope);
        }
        
        return $status;
    }

    private function getCacheKey(int $userId, string $scope): string
    {
        return "rate_limit.{$userId}.{$scope}";
    }
}

