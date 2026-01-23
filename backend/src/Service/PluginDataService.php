<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PluginData;
use App\Repository\PluginDataRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service for managing plugin data storage.
 *
 * Provides a simple key-value interface for plugins to store structured JSON data
 * without requiring plugin-specific database tables or schema changes.
 *
 * Usage:
 *   $pluginData->set(1, 'sortx', 'category', 'invoice', ['name' => 'Invoice', ...]);
 *   $data = $pluginData->get(1, 'sortx', 'category', 'invoice');
 *   $allCategories = $pluginData->list(1, 'sortx', 'category');
 */
final readonly class PluginDataService
{
    public function __construct(
        private EntityManagerInterface $em,
        private PluginDataRepository $repository,
    ) {
    }

    /**
     * Get a single data entry.
     *
     * @return array<string, mixed>|null The data array or null if not found
     */
    public function get(int $userId, string $plugin, string $type, string $key): ?array
    {
        $entry = $this->repository->findOneByKey($userId, $plugin, $type, $key);

        return $entry?->getData();
    }

    /**
     * Set (create or update) a data entry.
     *
     * @param array<string, mixed> $data The data to store
     */
    public function set(int $userId, string $plugin, string $type, string $key, array $data): void
    {
        $entry = $this->repository->findOneByKey($userId, $plugin, $type, $key);

        if (null === $entry) {
            $entry = new PluginData();
            $entry->setUserId($userId);
            $entry->setPluginName($plugin);
            $entry->setDataType($type);
            $entry->setDataKey($key);
            $this->em->persist($entry);
        }

        $entry->setData($data);
        $this->em->flush();
    }

    /**
     * List all data entries of a specific type.
     *
     * @return array<string, array<string, mixed>> Map of key => data
     */
    public function list(int $userId, string $plugin, string $type): array
    {
        $entries = $this->repository->findAllByType($userId, $plugin, $type);
        $result = [];

        foreach ($entries as $entry) {
            $key = $entry->getDataKey() ?? '';
            $result[$key] = $entry->getData();
        }

        return $result;
    }

    /**
     * List all data entries of a specific type as indexed array.
     *
     * @return array<int, array{key: string, data: array<string, mixed>}>
     */
    public function listWithKeys(int $userId, string $plugin, string $type): array
    {
        $entries = $this->repository->findAllByType($userId, $plugin, $type);
        $result = [];

        foreach ($entries as $entry) {
            $result[] = [
                'key' => $entry->getDataKey() ?? '',
                'data' => $entry->getData(),
            ];
        }

        return $result;
    }

    /**
     * Delete a specific data entry.
     */
    public function delete(int $userId, string $plugin, string $type, string $key): bool
    {
        $entry = $this->repository->findOneByKey($userId, $plugin, $type, $key);

        if (null === $entry) {
            return false;
        }

        $this->em->remove($entry);
        $this->em->flush();

        return true;
    }

    /**
     * Delete all data of a specific type.
     */
    public function deleteAllByType(int $userId, string $plugin, string $type): int
    {
        return $this->repository->deleteAllByType($userId, $plugin, $type);
    }

    /**
     * Delete all data for a plugin.
     */
    public function deleteAllByPlugin(int $userId, string $plugin): int
    {
        return $this->repository->deleteAllByPlugin($userId, $plugin);
    }

    /**
     * Check if a specific data entry exists.
     */
    public function exists(int $userId, string $plugin, string $type, string $key): bool
    {
        return null !== $this->repository->findOneByKey($userId, $plugin, $type, $key);
    }

    /**
     * Count entries of a specific type.
     */
    public function count(int $userId, string $plugin, string $type): int
    {
        return count($this->repository->findAllByType($userId, $plugin, $type));
    }

    /**
     * Bulk set multiple entries of the same type.
     *
     * @param array<string, array<string, mixed>> $entries Map of key => data
     */
    public function bulkSet(int $userId, string $plugin, string $type, array $entries): void
    {
        foreach ($entries as $key => $data) {
            $entry = $this->repository->findOneByKey($userId, $plugin, $type, $key);

            if (null === $entry) {
                $entry = new PluginData();
                $entry->setUserId($userId);
                $entry->setPluginName($plugin);
                $entry->setDataType($type);
                $entry->setDataKey($key);
                $this->em->persist($entry);
            }

            $entry->setData($data);
        }

        $this->em->flush();
    }
}
