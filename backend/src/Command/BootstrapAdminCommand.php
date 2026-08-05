<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Admin\BootstrapAdminService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:bootstrap-admin',
    description: 'Creates or promotes the first administrator from deployment environment variables',
)]
final class BootstrapAdminCommand extends Command
{
    public function __construct(
        private readonly BootstrapAdminService $bootstrapAdminService,
        private readonly string $bootstrapAdminEmail,
        #[\SensitiveParameter]
        private readonly string $bootstrapAdminPassword,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $result = $this->bootstrapAdminService->bootstrap(
                $this->bootstrapAdminEmail,
                $this->bootstrapAdminPassword,
            );
        } catch (\Throwable $exception) {
            $io->error('First-admin bootstrap failed: '.$exception->getMessage());

            return Command::FAILURE;
        }

        match ($result) {
            BootstrapAdminService::RESULT_NOT_CONFIGURED => $io->note(
                'First-admin bootstrap is not configured; no changes were made.'
            ),
            BootstrapAdminService::RESULT_ADMIN_EXISTS => $io->note(
                'An administrator already exists; no changes were made.'
            ),
            BootstrapAdminService::RESULT_PROMOTED => $io->success(
                'The configured user was promoted to administrator and verified.'
            ),
            BootstrapAdminService::RESULT_CREATED => $io->success(
                'The first administrator was created and verified.'
            ),
            default => throw new \LogicException(sprintf('Unknown bootstrap result "%s".', $result)),
        };

        return Command::SUCCESS;
    }
}
