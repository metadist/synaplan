<?php

declare(strict_types=1);

namespace App\Observability;

use App\Model\ModelCatalog;
use Symfony\Contracts\Service\ResetInterface;

/**
 * In-flight timing collector for the current HTTP request (`04_capacity_and_kubernetes.md` §3, S1 AU13).
 *
 * Assembles the allow-listed record fields across `kernel.request`, the Doctrine
 * DBAL timing middleware (`db_ms`, `db_queries`), {@see \App\AI\Pipeline\Hook\AiCallMetricsHook}
 * (`ai[]`), and `kernel.terminate` — without ever storing prompts, answers, tool
 * arguments/results, file names, URLs with ids, emails or keys.
 */
final class RequestTimelineContext implements ResetInterface
{
    public const ROUTE_CLASSES = [
        'chat_stream',
        'widget_message',
        'openai_api',
        'messages_api',
        'files',
        'search',
        'admin',
        'auth',
        'config',
        'mcp',
        'webhook',
        'media',
        'other',
    ];

    private ?string $requestId = null;
    private int $startedTs = 0;
    private int $startedHrtimeNs = 0;
    private string $routeClass = 'other';
    private ?int $userId = null;
    private float $dbMs = 0.0;
    private int $dbQueries = 0;

    /** @var list<array{provider: string, model: string, capability: string, source: string, ttft_ms: ?int, duration_ms: int, tokens_in: int, tokens_out: int, cost: string, outcome: string, hooks_ms: float}> */
    private array $aiCalls = [];

    /** @var list<bool> True once a usage receipt has set the cost of the AI call at the same index */
    private array $aiCostFinal = [];

    /** @var list<array{source: string, kind: string, outcome: string, duration_ms: int}> */
    private array $toolCalls = [];

    /** @var list<array{destination: string, status_class: string, duration_ms: int}> */
    private array $egressCalls = [];

    public function start(string $requestId, string $routeClass, ?int $userId, int $nowTs, int $nowHrtimeNs): void
    {
        $this->requestId = $requestId;
        $this->routeClass = \in_array($routeClass, self::ROUTE_CLASSES, true) ? $routeClass : 'other';
        $this->userId = null !== $userId && $userId > 0 ? $userId : null;
        $this->startedTs = $nowTs;
        $this->startedHrtimeNs = $nowHrtimeNs;
        $this->dbMs = 0.0;
        $this->dbQueries = 0;
        $this->aiCalls = [];
        $this->aiCostFinal = [];
        $this->toolCalls = [];
        $this->egressCalls = [];
    }

    public function isStarted(): bool
    {
        return null !== $this->requestId && $this->startedHrtimeNs > 0;
    }

    public function setRouteClass(string $routeClass): void
    {
        if (\in_array($routeClass, self::ROUTE_CLASSES, true)) {
            $this->routeClass = $routeClass;
        }
    }

    public function setUserId(?int $userId): void
    {
        if (null !== $userId && $userId > 0) {
            $this->userId = $userId;
        }
    }

    public function recordDbQuery(float $durationMs): void
    {
        $this->dbMs += max(0.0, $durationMs);
        ++$this->dbQueries;
    }

    /**
     * Record one completed AI call from {@see \App\AI\Pipeline\Hook\AiCallMetricsHook}.
     *
     * Only bounded catalog identifiers and numeric metrics are accepted — never
     * prompt, response, or error text.
     */
    public function recordAiCall(
        string $provider,
        ?string $model,
        string $capability,
        string $source,
        ?float $ttftMs,
        float $durationMs,
        int $tokensIn,
        int $tokensOut,
        string $cost,
        string $outcome,
        float $hooksMs,
    ): void {
        $this->aiCalls[] = [
            'provider' => self::boundedLabel($provider, 'unknown'),
            'model' => self::boundedCatalogModel($model),
            'capability' => self::boundedLabel($capability, 'other'),
            'source' => self::boundedLabel($source, 'system'),
            'ttft_ms' => null !== $ttftMs ? (int) round(max(0.0, $ttftMs)) : null,
            'duration_ms' => (int) round(max(0.0, $durationMs)),
            'tokens_in' => max(0, $tokensIn),
            'tokens_out' => max(0, $tokensOut),
            'cost' => number_format(max(0.0, (float) $cost), 6, '.', ''),
            'outcome' => self::boundedLabel($outcome, 'failed'),
            'hooks_ms' => round(max(0.0, $hooksMs), 3),
        ];
        $this->aiCostFinal[] = false;
    }

    /**
     * @return list<array{provider: string, model: string, capability: string, source: string, ttft_ms: ?int, duration_ms: int, tokens_in: int, tokens_out: int, cost: string, outcome: string, hooks_ms: float}>
     */
    public function aiCalls(): array
    {
        return $this->aiCalls;
    }

    /**
     * Apply one usage receipt to the trailing run of AI calls for this provider
     * and model that do not have a receipt yet.
     *
     * One call gets the whole charge, replacing a provisional preview. Several
     * calls (one embedding batch, or several gateway rounds that share a row)
     * split the charge by their token counts, so the total is not piled onto
     * the last call and an earlier call is not charged by a later receipt.
     */
    public function stampChargedCost(string $provider, string $model, string $chargedCost): void
    {
        $providerLabel = self::boundedLabel($provider, 'unknown');
        $modelLabel = self::boundedCatalogModel($model);
        $amount = max(0.0, (float) $chargedCost);

        $indexes = [];
        for ($i = \count($this->aiCalls) - 1; $i >= 0; --$i) {
            if ($this->aiCostFinal[$i] ?? false) {
                break;
            }
            if ($this->aiCalls[$i]['provider'] !== $providerLabel || $this->aiCalls[$i]['model'] !== $modelLabel) {
                break;
            }
            $indexes[] = $i;
        }
        if ([] === $indexes) {
            return;
        }
        $indexes = array_reverse($indexes);

        $weights = [];
        $totalWeight = 0;
        foreach ($indexes as $index) {
            $weight = $this->aiCalls[$index]['tokens_in'] + $this->aiCalls[$index]['tokens_out'];
            $weights[$index] = $weight;
            $totalWeight += $weight;
        }

        $remaining = $amount;
        $last = $indexes[\count($indexes) - 1];
        $shareCount = \count($indexes);
        foreach ($indexes as $index) {
            if ($index === $last) {
                $share = $remaining;
            } elseif ($totalWeight > 0) {
                $share = round($amount * $weights[$index] / $totalWeight, 6);
                $remaining = round($remaining - $share, 6);
            } else {
                $share = round($amount / $shareCount, 6);
                $remaining = round($remaining - $share, 6);
            }
            $this->aiCalls[$index]['cost'] = number_format(max(0.0, $share), 6, '.', '');
            $this->aiCostFinal[$index] = true;
        }
    }

    public function recordTool(string $source, string $kind, string $outcome, int $durationMs): void
    {
        $this->toolCalls[] = [
            'source' => self::boundedLabel($source, 'unknown'),
            'kind' => self::boundedLabel($kind, 'unknown'),
            'outcome' => self::boundedLabel($outcome, 'failed'),
            'duration_ms' => max(0, $durationMs),
        ];
    }

    public function recordEgress(string $urlOrHost, int $statusCode, int $durationMs): void
    {
        $host = parse_url($urlOrHost, \PHP_URL_HOST);
        $dest = \is_string($host) && '' !== $host ? strtolower($host) : self::boundedLabel($urlOrHost, 'unknown');

        $this->egressCalls[] = [
            'destination' => (string) preg_replace('/[^a-z0-9._-]/', '', $dest),
            'status_class' => self::classifyStatus($statusCode),
            'duration_ms' => max(0, $durationMs),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildRecord(string $serverId, string $statusClass, int $endHrtimeNs): array
    {
        $totalMs = $this->startedHrtimeNs > 0
            ? (int) round(max(0.0, ($endHrtimeNs - $this->startedHrtimeNs) / 1_000_000.0))
            : 0;

        return [
            'id' => $this->requestId ?? 'unknown',
            'ts' => $this->startedTs > 0 ? $this->startedTs : time(),
            'server' => $serverId,
            'route_class' => $this->routeClass,
            'status_class' => \in_array($statusClass, ['2xx', '4xx', '5xx', 'busy'], true) ? $statusClass : '2xx',
            'total_ms' => $totalMs,
            'php_ms' => $totalMs,
            'db_ms' => (int) round($this->dbMs),
            'db_queries' => $this->dbQueries,
            'ai' => $this->aiCalls,
            'tools' => $this->toolCalls,
            'egress' => $this->egressCalls,
            'user_id' => $this->userId,
        ];
    }

    public static function classifyRoute(?string $route, string $path): string
    {
        $route = (string) $route;
        if (str_starts_with($route, 'api_messages_stream') || str_starts_with($path, '/api/v1/messages/stream')) {
            return 'chat_stream';
        }
        if (str_starts_with($route, 'api_widget_public') || str_starts_with($path, '/api/v1/widget/')) {
            return 'widget_message';
        }
        if (str_starts_with($route, 'openai_') || str_starts_with($path, '/v1/chat/completions') || str_starts_with($path, '/v1/embeddings') || str_starts_with($path, '/v1/images/') || str_starts_with($path, '/v1/audio/') || str_starts_with($path, '/v1/models')) {
            return 'openai_api';
        }
        if (str_starts_with($route, 'messages_api') || str_starts_with($path, '/v1/messages')) {
            return 'messages_api';
        }
        if (str_starts_with($route, 'api_files') || str_starts_with($path, '/api/v1/files')) {
            return 'files';
        }
        if (str_starts_with($route, 'api_search') || str_starts_with($route, 'api_rag') || str_starts_with($path, '/api/v1/search') || str_starts_with($path, '/api/v1/rag')) {
            return 'search';
        }
        if (str_starts_with($route, 'admin_') || str_starts_with($path, '/api/v1/admin')) {
            return 'admin';
        }
        if (str_starts_with($route, 'api_auth') || str_starts_with($route, 'keycloak_') || str_starts_with($path, '/api/v1/auth')) {
            return 'auth';
        }
        if (str_starts_with($route, 'api_config') || str_starts_with($path, '/api/v1/config')) {
            return 'config';
        }
        if (str_starts_with($route, 'mcp_') || str_starts_with($path, '/mcp')) {
            return 'mcp';
        }
        if (str_starts_with($route, 'api_webhooks') || str_starts_with($path, '/api/v1/webhooks')) {
            return 'webhook';
        }
        if (str_starts_with($route, 'api_tts') || str_starts_with($route, 'api_media') || str_starts_with($path, '/api/v1/tts') || str_starts_with($path, '/api/v1/media')) {
            return 'media';
        }

        return 'other';
    }

    public static function classifyStatus(int $statusCode, ?string $content = null): string
    {
        if (503 === $statusCode && null !== $content && str_contains($content, 'server_busy')) {
            return 'busy';
        }
        if ($statusCode >= 500) {
            return '5xx';
        }
        if ($statusCode >= 400) {
            return '4xx';
        }

        return '2xx';
    }

    /**
     * Bound model label to known catalog providerIds or a generic `'custom'`
     * label (`03_contracts.md` §9: no model names from free text).
     */
    public static function boundedCatalogModel(?string $model): string
    {
        if (null === $model || '' === trim($model)) {
            return 'unknown';
        }

        static $known = null;
        if (null === $known) {
            $known = [
                'test-model' => true,
                'gpt-4o' => true,
                'claude-sonnet-4-6' => true,
                'gemini-2.5-flash' => true,
                'text-embedding-3-small' => true,
                'dall-e-3' => true,
                'tts-1' => true,
                'whisper-1' => true,
                'sonar' => true,
            ];
            foreach (ModelCatalog::all() as $row) {
                if (isset($row['providerId']) && \is_string($row['providerId'])) {
                    $known[strtolower($row['providerId'])] = true;
                }
            }
        }

        $candidate = strtolower(trim($model));

        return isset($known[$candidate]) ? trim($model) : 'custom';
    }

    public static function boundedLabel(string $raw, string $fallback): string
    {
        $clean = strtolower((string) preg_replace('/[^a-zA-Z0-9._:-]/', '', trim($raw)));

        return '' !== $clean && \strlen($clean) <= 64 ? $clean : $fallback;
    }

    public function reset(): void
    {
        $this->requestId = null;
        $this->startedTs = 0;
        $this->startedHrtimeNs = 0;
        $this->routeClass = 'other';
        $this->userId = null;
        $this->dbMs = 0.0;
        $this->dbQueries = 0;
        $this->aiCalls = [];
        $this->aiCostFinal = [];
        $this->toolCalls = [];
        $this->egressCalls = [];
    }
}
