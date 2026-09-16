<?php

declare(strict_types=1);

namespace App\Service\Connection;

use App\Entity\Connection;
use App\Repository\ConnectionRepository;
use App\Repository\InboundEmailHandlerRepository;
use App\Repository\McpServerConfigRepository;
use App\Service\Credential\CredentialVaultInterface;

final readonly class ConnectionService
{
    /**
     * @param iterable<ConnectionTester> $testers
     */
    public function __construct(
        private ConnectionRepository $connections,
        private CredentialVaultInterface $vault,
        private InboundEmailHandlerRepository $mailHandlers,
        private McpServerConfigRepository $mcpServers,
        private iterable $testers = [],
        private ?PlannerChannelCatalog $channels = null,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForUser(int $ownerId): array
    {
        $items = [];
        foreach ($this->connections->findByOwner($ownerId) as $connection) {
            $items[] = $this->serialize($connection, 'registry');
        }

        foreach ($this->mailHandlers->findByUser($ownerId) as $handler) {
            $id = $handler->getId();
            if (null === $id) {
                continue;
            }
            $status = 'active' === $handler->getStatus()
                ? Connection::STATUS_CONNECTED
                : Connection::STATUS_DISCONNECTED;
            $items[] = [
                'id' => 'mailbox:'.$id,
                'source' => 'inbound_email',
                'type' => 'mailbox',
                'name' => $handler->getName(),
                'status' => $status,
                'last_checked' => null,
                'has_secret' => true,
                'manage_path' => '/channels/email',
            ];
        }

        foreach ($this->mcpServers->findByUser($ownerId) as $server) {
            $id = $server->getId();
            if (null === $id) {
                continue;
            }
            $items[] = [
                'id' => 'mcp:'.$id,
                'source' => 'mcp',
                'type' => 'mcp',
                'name' => $server->getName(),
                'status' => $server->isEnabled() ? Connection::STATUS_CONNECTED : Connection::STATUS_DISCONNECTED,
                'last_checked' => null,
                'has_secret' => '' !== $server->getAuthToken(),
                'manage_path' => '/channels/mcp',
            ];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function create(int $ownerId, array $data): array
    {
        $type = is_string($data['type'] ?? null) ? $data['type'] : '';
        $name = is_string($data['name'] ?? null) ? $data['name'] : '';
        if (!in_array($type, Connection::TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported connection type "%s"', $type));
        }

        // An OAuth connection's secret is a token set the authorization server
        // issues; accepting one here would let a client plant an arbitrary
        // "token" that the Graph client would then send as a Bearer credential.
        if (in_array($type, Connection::OAUTH_TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('Connections of type "%s" are created by signing in with the provider, not through this endpoint', $type));
        }

        $connection = new Connection($ownerId, $type, $name);
        $config = isset($data['config']) && is_array($data['config']) ? $data['config'] : [];
        $config['channel'] = $this->assignChannel($ownerId, $type, $name, $config);
        $connection->setConfig($config);
        $this->connections->save($connection);

        $secret = $data['secret'] ?? '';
        if (is_string($secret) && '' !== $secret) {
            $id = $connection->getId();
            if (null === $id) {
                throw new \RuntimeException('Connection persist did not assign an id');
            }
            $credentialId = $this->vault->store($ownerId, $type, $secret);
            $connection->setCredentialId($credentialId);
            $this->connections->save($connection);
        }

        return $this->serialize($connection, 'registry');
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>|null
     */
    public function update(int $id, int $ownerId, array $data): ?array
    {
        $connection = $this->connections->findByIdAndOwner($id, $ownerId);
        if (null === $connection) {
            return null;
        }

        if (isset($data['name']) && is_string($data['name']) && '' !== $data['name']) {
            $connection->setName($data['name']);
        }
        if (isset($data['config']) && is_array($data['config'])) {
            $config = $data['config'];
            $existing = $connection->getConfig() ?? [];
            if (!isset($config['channel']) && isset($existing['channel'])) {
                $config['channel'] = $existing['channel'];
            }
            $connection->setConfig($config);
        }
        $secret = $data['secret'] ?? null;
        if (is_string($secret) && '' !== $secret) {
            if (null !== $connection->getCredentialId()) {
                $this->vault->rotate($connection->getCredentialId(), $ownerId, $secret);
            } else {
                $connection->setCredentialId($this->vault->store($ownerId, $connection->getType(), $secret));
            }
        }
        $this->connections->save($connection);

        return $this->serialize($connection, 'registry');
    }

    public function delete(int $id, int $ownerId): bool
    {
        $connection = $this->connections->findByIdAndOwner($id, $ownerId);
        if (null === $connection) {
            return false;
        }
        if (null !== $connection->getCredentialId()) {
            $this->vault->forget($connection->getCredentialId(), $ownerId);
        }
        $this->connections->remove($connection);

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function test(int $id, int $ownerId): ?array
    {
        $connection = $this->connections->findByIdAndOwner($id, $ownerId);
        if (null === $connection) {
            return null;
        }

        foreach ($this->testers as $tester) {
            if (!$tester->supports($connection->getType())) {
                continue;
            }

            $result = $tester->test($connection);

            return $this->serialize($connection, 'registry') + [
                'test_succeeded' => $result['success'],
                'test_error' => $result['error'] ?? null,
                'account' => $result['account'] ?? null,
            ];
        }

        // No tester for this type yet: the only honest statement is whether a
        // credential is stored at all.
        $status = null !== $connection->getCredentialId()
            ? Connection::STATUS_CONNECTED
            : Connection::STATUS_ERROR;
        $connection->markChecked($status);
        $this->connections->save($connection);

        return $this->serialize($connection, 'registry');
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Connection $connection, string $source): array
    {
        $config = $connection->getConfig();
        $channel = is_array($config) && is_string($config['channel'] ?? null)
            ? PlannerChannelCatalog::sanitize($config['channel'])
            : PlannerChannelCatalog::preferredKey($connection->getType(), $connection->getName(), $config ?? []);

        return [
            'id' => (string) $connection->getId(),
            'source' => $source,
            'type' => $connection->getType(),
            'name' => $connection->getName(),
            'channel' => $channel,
            'status' => $connection->getStatus(),
            'last_checked' => $connection->getLastChecked(),
            'has_secret' => null !== $connection->getCredentialId(),
            'config' => $config,
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function assignChannel(int $ownerId, string $type, string $name, array $config): string
    {
        $requested = is_string($config['channel'] ?? null)
            ? PlannerChannelCatalog::sanitize($config['channel'])
            : '';
        if (null !== $this->channels) {
            return '' !== $requested
                ? PlannerChannelCatalog::unique($requested, $this->usedKeys($ownerId))
                : $this->channels->suggest($ownerId, $type, $name, $config);
        }

        return '' !== $requested ? $requested : PlannerChannelCatalog::preferredKey($type, $name, $config);
    }

    /**
     * @return list<string>
     */
    private function usedKeys(int $ownerId): array
    {
        if (null === $this->channels) {
            return [];
        }

        return array_map(
            static fn (PlannerChannel $channel): string => $channel->key,
            $this->channels->forUser($ownerId),
        );
    }
}
