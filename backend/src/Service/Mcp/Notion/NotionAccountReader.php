<?php

declare(strict_types=1);

namespace App\Service\Mcp\Notion;

use App\Entity\McpServerConfig;
use App\Service\Mcp\McpClient;
use App\Service\Security\EncryptionService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Who the Notion connection signs in as, so the Apps panel can say which
 * account and workspace the AI reads and writes. Never throws: an unknown
 * account only hides that line.
 */
final readonly class NotionAccountReader
{
    private const TOOL = 'notion-get-self';
    private const CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private McpClient $client,
        private CacheInterface $cache,
        private EncryptionService $encryption,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{workspace_name: string|null, user_name: string|null, user_email: string|null}
     */
    public function read(McpServerConfig $server): array
    {
        $empty = ['workspace_name' => null, 'user_name' => null, 'user_email' => null];

        try {
            return $this->cache->get($this->cacheKey($server), function (ItemInterface $item) use ($server): array {
                $item->expiresAfter(self::CACHE_TTL_SECONDS);
                $account = self::parse($this->client->callTool($server, self::TOOL, []));
                if (null === $account['workspace_name'] && null === $account['user_name'] && null === $account['user_email']) {
                    throw new \RuntimeException('Notion returned no account details.');
                }

                return $account;
            });
        } catch (\Throwable $e) {
            $this->logger->info('NotionAccountReader: account lookup failed', [
                'server_id' => $server->getId(),
                'error' => $e->getMessage(),
            ]);

            return $empty;
        }
    }

    /**
     * @param array{content: list<array<string, mixed>>, isError: bool} $result
     *
     * @return array{workspace_name: string|null, user_name: string|null, user_email: string|null}
     */
    public static function parse(array $result): array
    {
        $data = [];
        if (!$result['isError']) {
            foreach ($result['content'] as $block) {
                $decoded = is_string($block['text'] ?? null) ? json_decode($block['text'], true) : null;
                if (is_array($decoded)) {
                    $data = $decoded;
                    break;
                }
            }
        }

        $workspace = is_array($data['workspace'] ?? null) ? $data['workspace'] : [];
        $user = is_array($data['user'] ?? null) ? $data['user'] : [];

        return [
            'workspace_name' => self::text($workspace['name'] ?? null),
            'user_name' => self::text($user['name'] ?? null),
            'user_email' => self::text($user['email'] ?? null),
        ];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && '' !== trim($value) ? trim($value) : null;
    }

    /**
     * A new sign-in gets a new token, so a different account is never shown
     * from the cache.
     */
    private function cacheKey(McpServerConfig $server): string
    {
        $state = $server->getDecryptedOAuthState($this->encryption);

        return sprintf('notion_account_%d_%s', (int) $server->getId(), hash('sha256', $state->clientId.'|'.$state->refreshToken.'|'.$state->accessToken));
    }
}
