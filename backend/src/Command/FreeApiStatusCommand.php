<?php

declare(strict_types=1);

namespace App\Command;

use App\AI\Credential\OpenAiCompatibleEndpointRegistry;
use App\Service\FreeApi\FreeApiClient;
use App\Service\FreeApi\FreeApiConfig;
use App\Service\FreeApi\FreeApiException;
use App\Service\FreeApi\FreeApiState;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:freeapi:status',
    description: 'Show whether the Synaplan Demo is available and active on this server'
)]
final class FreeApiStatusCommand extends Command
{
    public function __construct(
        private readonly FreeApiConfig $config,
        private readonly FreeApiState $state,
        private readonly FreeApiClient $client,
        private readonly OpenAiCompatibleEndpointRegistry $endpoints,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('remote', null, InputOption::VALUE_NONE, 'Also ask the Demo server for today\'s remaining messages (only while active)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $active = $this->state->isActive();
        $status = [
            'enabled' => $this->config->isEnabled(),
            'configured' => $this->config->isConfigured(),
            'server' => $this->config->serverHost(),
            'active' => $active,
            'expires_at' => $active ? $this->state->expiresAt() : null,
            'limits' => $active ? $this->state->limits() : [],
            'revoke_pending' => null !== $this->state->pendingRevoke(),
            'remote' => null,
        ];

        $key = $this->endpoints->getEndpoint(FreeApiConfig::ENDPOINT_NAME)['api_key'] ?? '';
        if ($input->getOption('remote') && $active && '' !== $key) {
            try {
                $status['remote'] = $this->client->status($key);
            } catch (FreeApiException $e) {
                $status['remote'] = ['error' => $e->errorCode, 'message' => $e->getMessage()];
            }
        }

        if ($input->getOption('json')) {
            $output->writeln(json_encode($status, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);
        $rows = [
            ['Available', $status['configured'] ? 'yes' : ($status['enabled'] ? 'no — FREEAPI_BASE_URL is missing or not allowed here' : 'no — turned off')],
            ['Server', $status['server'] ?? '—'],
            ['Active', $active ? 'yes' : 'no'],
            ['Expires', null !== $status['expires_at'] ? gmdate('Y-m-d H:i', $status['expires_at']).' UTC' : '—'],
            ['Key revoke pending', $status['revoke_pending'] ? 'yes (retried hourly)' : 'no'],
        ];
        foreach ($status['limits'] as $name => $value) {
            $rows[] = ['Limit: '.$name, (string) $value];
        }
        foreach (\is_array($status['remote']) ? $status['remote'] : [] as $name => $value) {
            $rows[] = ['Server: '.$name, \is_scalar($value) ? (string) $value : json_encode($value, \JSON_THROW_ON_ERROR)];
        }
        $io->table(['', ''], $rows);

        return Command::SUCCESS;
    }
}
