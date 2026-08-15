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
    public function __construct(
        private ConnectionRepository $connections,
        private CredentialVaultInterface $vault,
        private InboundEmailHandlerRepository $mailHandlers,
        private McpServerConfigRepository $mcpServers,
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

        $connection = new Connection($ownerId, $type, $name);
        if (isset($data['config']) && is_array($data['config'])) {
            $connection->setConfig($data['config']);
        }
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
            $connection->setConfig($data['config']);
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
        return [
            'id' => (string) $connection->getId(),
            'source' => $source,
            'type' => $connection->getType(),
            'name' => $connection->getName(),
            'status' => $connection->getStatus(),
            'last_checked' => $connection->getLastChecked(),
            'has_secret' => null !== $connection->getCredentialId(),
            'config' => $connection->getConfig(),
        ];
    }
}
