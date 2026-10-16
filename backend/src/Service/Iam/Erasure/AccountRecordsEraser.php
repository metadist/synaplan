<?php

declare(strict_types=1);

namespace App\Service\Iam\Erasure;

use App\Entity\McpServerConfig;
use App\Entity\PluginData;
use App\Entity\RevectorizeRun;
use App\Entity\Topup;
use App\Repository\McpServerConfigRepository;
use App\Repository\PluginDataRepository;
use App\Repository\RevectorizeRunRepository;
use App\Repository\TopupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Per-account records with no cleanup of their own: plugin data, top-ups,
 * MCP server connections and re-vectorize runs.
 */
#[AsTaggedItem(priority: 80)]
final readonly class AccountRecordsEraser implements UserDataEraserInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private PluginDataRepository $pluginData,
        private TopupRepository $topups,
        private McpServerConfigRepository $mcpServers,
        private RevectorizeRunRepository $revectorizeRuns,
    ) {
    }

    public function erase(UserErasure $erasure): void
    {
        $rows = [
            ...$this->pluginData->findBy(['userId' => $erasure->userId]),
            ...$this->topups->findBy(['userId' => $erasure->userId]),
            ...$this->mcpServers->findBy(['userId' => $erasure->userId]),
            ...$this->revectorizeRuns->findBy(['userId' => $erasure->userId]),
        ];
        foreach ($rows as $row) {
            $this->em->remove($row);
        }
    }

    public static function covers(): array
    {
        return [PluginData::class, Topup::class, McpServerConfig::class, RevectorizeRun::class];
    }
}
