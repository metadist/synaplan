<?php

declare(strict_types=1);

namespace App\Command;

use App\AI\Credential\ChatReadinessService;
use App\AI\Credential\ProviderKeyStore;
use App\AI\Service\ProviderRegistry;
use App\Model\ModelCatalog;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:provider:list',
    description: 'Show the AI providers of this installation and their availability',
)]
final class ProviderListCommand extends Command
{
    public function __construct(
        private readonly ProviderRegistry $providerRegistry,
        private readonly ProviderKeyStore $providerKeyStore,
        private readonly ChatReadinessService $chatReadiness,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('fresh', null, InputOption::VALUE_NONE,
                'Bypass the cached availability snapshot and probe every provider now')
            ->setHelp(
                "Availability is the installation-level truth the catalog surfaces build on:\n".
                "a provider is available when its credentials are present (API key, or a\n".
                "reachable URL for local providers; Ollama additionally needs the chat model\n".
                "pulled). Users only see models of available providers.\n\n".
                "Credentials column: <info>key (ui)</info> = saved in the admin UI,\n".
                "<info>key (env)</info> = bootstrapped from an environment variable,\n".
                "<info>none</info> = not configured, <info>n/a</info> = provider without a\n".
                "platform key (local URL or per-endpoint credentials).\n\n".
                'Enable or disable models per provider with '.
                '<info>app:model:enable --provider <name></info> / <info>app:model:disable --provider <name></info>.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $availability = $this->chatReadiness->providerAvailability(fresh: (bool) $input->getOption('fresh'));
        $modelCounts = $this->modelCountsByService();

        $rows = [];
        foreach ($this->providerRegistry->getUniqueProviders() as $name => $provider) {
            $key = ModelCatalog::normalizeProvider((string) $name);

            // Internal fixture serving dev/test — not a provider an operator manages.
            if ('test' === $key) {
                continue;
            }

            $counts = $modelCounts[$key] ?? ['active' => 0, 'total' => 0];

            $rows[] = [
                'name' => $provider->getDisplayName(),
                'credentials' => $this->credentialLabel($key),
                'available' => ($availability[$key] ?? false) ? '<info>yes</info>' : 'no',
                'models' => sprintf('%d / %d', $counts['active'], $counts['total']),
                'catalog' => count(ModelCatalog::findByService($key)),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        $io->table(
            ['Provider', 'Credentials', 'Available', 'Models active/DB', 'In catalog'],
            array_map(static fn (array $row): array => array_values($row), $rows)
        );

        $io->writeln('Availability is cached briefly; use <info>--fresh</info> to re-probe.');
        $io->writeln('Configure keys in Admin → AI Providers (/admin/setup) or via environment variables.');

        return Command::SUCCESS;
    }

    /**
     * Active and total BMODELS rows per lowercase service name. Negative BIDs
     * are dev/test mock models and never part of the operator's catalog.
     *
     * @return array<string, array{active: int, total: int}>
     */
    private function modelCountsByService(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(BSERVICE) AS service,
                    SUM(CASE WHEN BACTIVE = 1 THEN 1 ELSE 0 END) AS active,
                    COUNT(*) AS total
             FROM BMODELS
             WHERE BID > 0
             GROUP BY LOWER(BSERVICE)'
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['service']] = [
                'active' => (int) $row['active'],
                'total' => (int) $row['total'],
            ];
        }

        return $counts;
    }

    private function credentialLabel(string $provider): string
    {
        if (!ProviderKeyStore::isSupported($provider)) {
            return 'n/a';
        }

        $status = $this->providerKeyStore->getStatus($provider);
        if (!$status['configured']) {
            return 'none';
        }

        // A DB row carries its origin; a key read straight from the
        // environment (not yet imported) is an env credential as well.
        $origin = 'db' === $status['source'] ? ($status['origin'] ?? ProviderKeyStore::ORIGIN_UI) : ProviderKeyStore::ORIGIN_ENV;

        return sprintf('key (%s)', $origin);
    }
}
